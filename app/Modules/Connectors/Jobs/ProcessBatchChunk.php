<?php

namespace App\Modules\Connectors\Jobs;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Core\Auditing\Services\UapLogger;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessBatchChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = 30;

    public function __construct(
        protected $instance, 
        protected array $chunk, 
        protected int $commandId, 
        protected ?string $traceId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CommandExecutor $executor): void
    {
        $instance = JobInstance::with('template')->find($this->instance->id);
        
        // Safety check: Don't process if the instance is gone or the batch is cancelled
        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        $dir = "jobs/{$instance->id}";
        $successPath = Storage::path("{$dir}/results_success.csv");
        $failedPath = Storage::path("{$dir}/results_failed.csv");

        // Open file handles for appending
        $successFile = fopen($successPath, 'a');
        $failedFile = fopen($failedPath, 'a');

        $localProcessed = 0;
        $localFailed = 0;

        foreach ($this->chunk as $row) {
            try {
                // 1. Map the CSV row to the specific Command Parameter keys
                $params = $this->mapRowToParams($row, $instance->template->column_mapping);

                // 2. Execute via the CommandExecutor
                // This replaces the old BatchItemPipeline logic with the unified execution engine
                $log = $executor->execute(
                    $instance->template->provider_instance_id,
                    $this->commandId,
                    $params,
                    $instance->template->user_id,
                    $instance->id, // Passing JobInstance ID for the log relationship
                    $this->traceId
                );

                if ($log->is_successful) {
                    $localProcessed++;
                    $this->appendLocked($successFile, array_merge($row, [
                        $log->id, 
                        'YES', 
                        ''
                    ]));
                } else {
                    throw new \Exception($log->raw_response ?? 'Provider rejected request');
                }

            } catch (Throwable $e) {
                $localFailed++;
                $this->appendLocked($failedFile, array_merge($row, [
                    'N/A', 
                    'NO', 
                    $e->getMessage()
                ]));
            }
        }

        fclose($successFile);
        fclose($failedFile);

        // 3. Update the counters atomically
        $instance->increment('processed_records', $localProcessed);
        $instance->increment('failed_records', $localFailed);

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $instance->id,
            'processed'   => $localProcessed,
            'failed'      => $localFailed
        ], $this->traceId);
    }

    /**
     * Maps a raw CSV row into an array keyed by Command Parameter names.
     */
    protected function mapRowToParams(array $row, array $mapping): array
    {
        $params = [];
        foreach ($mapping as $commandParamKey => $csvHeader) {
            $params[$commandParamKey] = $row[$csvHeader] ?? null;
        }
        return $params;
    }

    /**
     * Helper to handle atomic file appending across concurrent workers.
     */
    protected function appendLocked($fileHandle, array $data): void
    {
        if (flock($fileHandle, LOCK_EX)) {
            fseek($fileHandle, 0, SEEK_END);
            fputcsv($fileHandle, $data);
            fflush($fileHandle);
            flock($fileHandle, LOCK_UN);
        }
    }
}