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

    // These properties must be declared
    protected string $instanceId;
    protected array $rows;

    /**
     * Create a new job instance.
     */
    public function __construct(string $instanceId, array $rows)
    {
        $this->instanceId = $instanceId;
        $this->rows = $rows;
    }

    public function handle(BatchItemPipeline $pipeline): void
    {
        $instance = JobInstance::with('template')->find($this->instanceId);
        
        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        // LOG: Chunk processing started
        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_STARTED', [
            'instance_id' => $this->instanceId,
            'chunk_size'  => count($this->rows),
            'batch_id'    => $this->batch()?->id
        ]);

        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$this->instanceId}";
        
        $successPath = "{$dir}/results_success.csv";
        $failedPath  = "{$dir}/results_failed.csv";

        // Open streams using Laravel's Storage or PHP's fopen
        $successFile = fopen(Storage::path($successPath), 'a');
        $failedFile  = fopen(Storage::path($failedPath), 'a');

        $localProcessed = 0;
        $localFailed = 0;

        foreach ($this->rows as $row) {
            try {
                // Pass the row through the pipeline to the Provider
                $log = $pipeline->process($instance, $row);

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
                    'instance_id' => $this->instanceId,
                    'error'       => $e->getMessage(),
                    'row_data'    => $row 
                ]);

                fputcsv($failedFile, array_merge($row, [
                    'batch_status_code'   => '500',
                    'batch_is_successful' => 'NO',
                    'batch_error_message' => $e->getMessage()
                ]));
            }
        }

        fclose($successFile);
        fclose($failedFile);

        $instance->increment('processed_records', $localProcessed);
        $instance->increment('failed_records', $localFailed);

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $this->instanceId,
            'processed'   => $localProcessed,
            'failed'      => $localFailed
        ]);
    }
}