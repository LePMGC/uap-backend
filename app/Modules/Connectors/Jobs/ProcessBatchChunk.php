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

    public function handle(BatchItemPipeline $pipeline): void
    {
        $instance = JobInstance::with('template')->find($this->instanceId);
        
        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        // 1. LOG: Chunk processing started
        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_STARTED', [
            'instance_id' => $this->instanceId,
            'chunk_size'  => count($this->rows),
            'batch_id'    => $this->batch()?->id
        ]);

        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$this->instanceId}";
        
        $successFile = fopen(Storage::path("{$dir}/results_success.csv"), 'a');
        $failedFile  = fopen(Storage::path("{$dir}/results_failed.csv"), 'a');

        $localProcessed = 0;
        $localFailed = 0;

        foreach ($this->rows as $row) {
            try {
                $log = $pipeline->process($instance, $row);
                
                $resultRow = array_merge($row, [
                    'batch_status_code'   => $log->response_code ?? 'N/A',
                    'batch_is_successful' => $log->is_successful ? 'YES' : 'NO',
                    'batch_error_message' => !$log->is_successful ? ($log->response_payload['response_message'] ?? 'Unknown Error') : '',
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

                // 2. LOG: Individual Row Exception (Detailed for troubleshooting)
                UapLogger::error('BatchEngine', 'ROW_PROCESSING_EXCEPTION', [
                    'instance_id' => $this->instanceId,
                    'error'       => $e->getMessage(),
                    'row_data'    => $row // Operators can see which MSISDN caused the crash
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

        // 3. LOG: Chunk processing completed
        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $this->instanceId,
            'processed'   => $localProcessed,
            'failed'      => $localFailed
        ]);
    }
}