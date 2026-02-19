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

class ProcessBatchChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $instanceId,
        protected array $rows
    ) {}

    public function handle(BatchItemPipeline $pipeline): void
    {
        $instance = JobInstance::find($this->instanceId);
        
        // Check if instance exists or if the user cancelled the batch via Laravel Horizon/UI
        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$this->instanceId}";
        
        // Open local result files in "append" mode
        // We use standard PHP fopen for high-performance streaming writes
        $successFile = fopen(Storage::path("{$dir}/results_success.csv"), 'a');
        $failedFile  = fopen(Storage::path("{$dir}/results_failed.csv"), 'a');

        $localProcessed = 0;
        $localFailed = 0;

        foreach ($this->rows as $row) {
            try {
                // Process through the pipeline (Mapping -> Execution -> Logging)
                $log = $pipeline->process($instance, $row);
                
                // Prepare the result row: Original Data + Response Metadata
                $resultRow = array_merge($row, [
                    'batch_status_code' => $log->response_code ?? 'N/A',
                    'batch_is_successful' => $log->is_successful ? 'YES' : 'NO',
                    'batch_error_message' => !$log->is_successful ? ($log->response_payload['error'] ?? 'Unknown Error') : '',
                ]);

                if ($log->is_successful) {
                    fputcsv($successFile, $resultRow);
                    $localProcessed++;
                } else {
                    fputcsv($failedFile, $resultRow);
                    $localFailed++;
                }
            } catch (\Exception $e) {
                // Catch any unexpected pipeline crashes per row
                $localFailed++;
                fputcsv($failedFile, array_merge($row, ['batch_error_message' => $e->getMessage()]));
            }
        }

        fclose($successFile);
        fclose($failedFile);

        // Perform a single atomic update to the database for this entire chunk
        $instance->increment('processed_records', $localProcessed);
        $instance->increment('failed_records', $localFailed);
    }
}