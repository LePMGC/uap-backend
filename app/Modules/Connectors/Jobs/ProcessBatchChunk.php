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
use Illuminate\Support\Arr;
use Throwable;

class ProcessBatchChunk implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 1;
    public $backoff = 30;
    public $timeout = 600;

    /**
     * @param JobInstance $instance
     * @param array $chunk
     * @param int $commandId
     * @param string|null $traceId
     */
    public function __construct(
        protected $instance,
        protected array $chunk,
        protected int $commandId,
        protected ?string $traceId = null,
        protected int $heartbeat = 10
    ) {
    }


    /**
    * Execute the job with dynamic "Heartbeat" updates.
    */
    public function handle(CommandExecutor $executor): void
    {
        // 1. Fresh fetch with relationships
        $instance = JobInstance::with(['template.command'])->find($this->instance->id);

        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        $dir = "jobs/{$instance->id}";
        $successPath = Storage::path("{$dir}/results_success.csv");
        $failedPath = Storage::path("{$dir}/results_failed.csv");

        $successFile = fopen($successPath, 'a');
        $failedFile = fopen($failedPath, 'a');

        $localProcessed = 0;
        $localSuccess = 0;
        $localFailed = 0;

        // This is our buffer for the heartbeat
        $uncommittedProcessed = 0;

        foreach ($this->chunk as $row) {
            $localProcessed++;
            $uncommittedProcessed++;

            try {
                // Parameter Mapping Logic
                $resolvedParams = [];
                $mapping = $instance->template->column_mapping ?? [];
                foreach ($mapping as $paramName => $config) {
                    if ($config['excluded'] ?? false) {
                        continue;
                    }
                    $val = ($config['mode'] ?? 'static') === 'dynamic'
                        ? ($row[$config['value']] ?? null)
                        : ($config['value'] ?? null);
                    $resolvedParams[$paramName] = $val;
                }

                $nestedParams = [];
                foreach ($resolvedParams as $key => $value) {
                    Arr::set($nestedParams, $key, $value);
                }

                // Execution
                $logEntry = $executor->execute(
                    (int) $instance->template->provider_instance_id,
                    (int) $this->commandId,
                    $nestedParams,
                    (int) $instance->template->user_id,
                    $instance->id,
                    $this->traceId
                );

                if ($logEntry->is_successful) {
                    $localSuccess++;
                    $this->appendLocked($successFile, array_merge($row, [
                        'command_log_id' => $logEntry->command->command_key,
                        'error_message' => $logEntry->response_code
                    ]));
                } else {
                    $localFailed++;
                    $this->appendLocked($failedFile, array_merge($row, [
                        'command_log_id' => $logEntry->command->command_key,
                        'error_message' => $logEntry->response_code
                    ]));
                }

            } catch (\Throwable $e) {
                $localFailed++;
                $this->appendLocked($failedFile, array_merge($row, [
                    'command_log_id' => 'EXCEPTION',
                    'error_message' => $e->getMessage()
                ]));
            }

            // HEARTBEAT: Push to DB and Refresh local state every X rows
            if ($uncommittedProcessed >= $this->heartbeat) {
                $instance->increment('processed_records', $uncommittedProcessed);
                $instance->refresh();
                $uncommittedProcessed = 0; // Reset the heartbeat counter
            }
            usleep(10000); // Sleep 10ms to prevent hammering the DB in tight loops
        }

        if ($successFile) {
            fclose($successFile);
        }
        if ($failedFile) {
            fclose($failedFile);
        }

        // FINAL SYNC
        if ($uncommittedProcessed > 0) {
            $instance->increment('processed_records', $uncommittedProcessed);
        }

        // Atomic increment for success/failed
        $instance->increment('success_records', $localSuccess);
        $instance->increment('failed_records', $localFailed);

        $instance->refresh();
        if ($instance->processed_records >= $instance->total_records) {
            (new \App\Modules\Connectors\Services\BatchOrchestrator())
                ->finalize($instance, 'completed', $this->traceId);
        }

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $instance->id,
            'chunk_total' => $localProcessed
        ], $this->traceId);
    }

    /**
     * Helper to handle atomic file appending across concurrent workers.
     */
    protected function appendLocked($fileHandle, array $data): void
    {
        if ($fileHandle && flock($fileHandle, LOCK_EX)) {
            fseek($fileHandle, 0, SEEK_END);
            fputcsv($fileHandle, $data);
            fflush($fileHandle);
            flock($fileHandle, LOCK_UN);
        }
    }
}
