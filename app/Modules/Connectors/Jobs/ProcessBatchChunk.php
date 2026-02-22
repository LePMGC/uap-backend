<?php

namespace App\Modules\Connectors\Jobs;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchItemPipeline;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use App\Modules\Connectors\Services\UapLogger;

class ProcessBatchChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Properties mapped from the Orchestrator
     */
    protected $instance;
    protected array $chunk;
    protected ?string $traceId;

    /**
     * Create a new job instance.
     */
    public function __construct($instance, array $chunk, ?string $traceId = null)
    {
        $this->instance = $instance;
        $this->chunk = $chunk;
        $this->traceId = $traceId;
    }

    /**
     * Execute the job.
     */
    public function handle(BatchItemPipeline $pipeline): void
    {
        // Refresh instance to get latest status and template
        $instance = JobInstance::with('template')->find($this->instance->id);
        
        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        // LOG: Chunk processing started using the shared Trace ID
        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_STARTED', [
            'instance_id' => $instance->id,
            'chunk_size'  => count($this->chunk),
        ], $this->traceId);

        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$instance->id}";
        
        // Open files for appending results
        $successFile = fopen(Storage::path("{$dir}/results_success.csv"), 'a');
        $failedFile = fopen(Storage::path("{$dir}/results_failed.csv"), 'a');

        $localProcessed = 0;
        $localFailed = 0;

        foreach ($this->chunk as $row) {
            try {
                // Execute the pipeline for this specific row
                // Pass traceId to the pipeline so CommandExecutor can use it too
                $log = $pipeline->process($instance, $row, $this->traceId);

                $resultRow = array_merge($row, [
                    'batch_status_code'   => $log->response_code,
                    'batch_is_successful' => $log->is_successful ? 'YES' : 'NO',
                    'batch_error_message' => !$log->is_successful ? ($log->response_message ?? 'Unknown Error') : '',
                ]);

                if ($log->is_successful) {
                    fputcsv($successFile, $resultRow);
                    $localProcessed++;
                } else {
                    fputcsv($failedFile, $resultRow);
                    $localFailed++;
                }
            } catch (\Exception $e) {
                $localFailed++;

                UapLogger::error('BatchEngine', 'ROW_PROCESSING_EXCEPTION', [
                    'instance_id' => $instance->id,
                    'error'       => $e->getMessage(),
                    'row_data'    => $row 
                ], $this->traceId);

                fputcsv($failedFile, array_merge($row, [
                    'batch_status_code'   => '500',
                    'batch_is_successful' => 'NO',
                    'batch_error_message' => $e->getMessage()
                ]));
            }
        }

        fclose($successFile);
        fclose($failedFile);

        // Update main instance stats
        $instance->increment('processed_records', $localProcessed);
        $instance->increment('failed_records', $localFailed);

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $instance->id,
            'processed'   => $localProcessed,
            'failed'      => $localFailed
        ], $this->traceId);
    }
}