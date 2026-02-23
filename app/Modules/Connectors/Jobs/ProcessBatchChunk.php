<?php

namespace App\Modules\Connectors\Jobs;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchItemPipeline;
use App\Modules\Connectors\Services\UapLogger;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessBatchChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $instance;
    protected array $chunk;
    protected ?string $traceId;

    public function __construct($instance, array $chunk, ?string $traceId = null)
    {
        $this->instance = $instance;
        $this->chunk = $chunk;
        $this->traceId = $traceId;
    }

    public function handle(BatchItemPipeline $pipeline): void
    {
        $instance = JobInstance::with('template')->find($this->instance->id);
        
        // Safety check for cancelled batches
        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_STARTED', [
            'instance_id' => $instance->id,
            'chunk_size'  => count($this->chunk),
        ], $this->traceId);

        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$instance->id}";
        $successPath = Storage::path("{$dir}/results_success.csv");
        $failedPath = Storage::path("{$dir}/results_failed.csv");

        // 1. IMPROVEMENT: Use 'c+' mode and flock() for high-concurrency safety
        $successFile = fopen($successPath, 'c+');
        $failedFile = fopen($failedPath, 'c+');

        $localProcessed = 0;
        $localFailed = 0;

        foreach ($this->chunk as $row) {
            try {
                $log = $pipeline->process($instance, $row, $this->traceId);

                // Extract the error message from the nested response_payload
                $errorMessage = '';
                if (!$log->is_successful) {
                    $payload = $log->response_payload;
                    $errorMessage = $payload['faultString'] ?? ($payload['response_message'] ?? 'Unknown Error');
                }

                $resultRow = array_merge($row, [
                    'batch_status_code'   => $log->response_code,
                    'batch_is_successful' => $log->is_successful ? 'YES' : 'NO',
                    'batch_error_message' => $errorMessage,
                ]);

                if ($log->is_successful) {
                    $this->appendLocked($successFile, $resultRow);
                    $localProcessed++;
                } else {
                    $this->appendLocked($failedFile, $resultRow);
                    $localFailed++;
                }
            } catch (\Exception $e) {
                $localFailed++;
                $this->appendLocked($failedFile, array_merge($row, [
                    'batch_status_code'   => '500',
                    'batch_is_successful' => 'NO',
                    'batch_error_message' => $e->getMessage()
                ]));
            }
        }

        fclose($successFile);
        fclose($failedFile);

        // 2. OPTIMIZATION: Atomic database updates to avoid deadlocks
        $instance->increment('processed_records', $localProcessed);
        $instance->increment('failed_records', $localFailed);

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $instance->id,
            'processed'   => $localProcessed,
            'failed'      => $localFailed
        ], $this->traceId);
    }

    /**
     * Helper to handle atomic file appending across concurrent workers
     */
    protected function appendLocked($fileHandle, array $data): void
    {
        if (flock($fileHandle, LOCK_EX)) { // Exclusive lock
            fseek($fileHandle, 0, SEEK_END);
            fputcsv($fileHandle, $data);
            fflush($fileHandle); // Force write to disk before releasing lock
            flock($fileHandle, LOCK_UN); // Release lock
        }
    }
}