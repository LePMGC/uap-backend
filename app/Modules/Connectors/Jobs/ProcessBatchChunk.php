<?php

namespace App\Modules\Connectors\Jobs;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Core\Auditing\Services\UapLogger;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue;
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
     * @param int $heartbeat
     * @param int $targetIntervalMs  <-- Added this
     */
    public function __construct(
        protected $instance,
        protected array $chunk,
        protected int $commandId,
        protected ?string $traceId = null,
        protected int $heartbeat = 10,
        protected int $targetIntervalMs = 0 // <-- Initialize property here
    ) {
    }

    /**
     * Execute the chunk with self-throttling to respect Provider TPS.
     */
    public function handle(CommandExecutor $executor): void
    {
        $instance = JobInstance::with(['template.command'])->find($this->instance->id);

        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        $dir = "jobs/{$instance->id}";
        $successFile = fopen(Storage::path("{$dir}/results_success.csv"), 'a');
        $failedFile = fopen(Storage::path("{$dir}/results_failed.csv"), 'a');

        $localSuccess = 0;
        $localFailed = 0;
        $uncommittedProcessed = 0;

        foreach ($this->chunk as $row) {
            $rowStartTime = microtime(true); // Start timing the request
            $uncommittedProcessed++;

            try {
                // Parameter Mapping
                $resolvedParams = [];
                $mapping = $instance->template->column_mapping ?? [];
                foreach ($mapping as $paramName => $config) {
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
                        'command_log_id' => $logEntry->command_key ?? 'N/A',
                        'response_code' => $logEntry->response_code
                    ]));
                } else {
                    $localFailed++;
                    $this->appendLocked($failedFile, array_merge($row, [
                        'command_log_id' => $logEntry->command_key ?? 'N/A',
                        'response_code' => $logEntry->response_code
                    ]));
                }

            } catch (\Throwable $e) {
                $localFailed++;
                $this->appendLocked($failedFile, array_merge($row, [
                    'command_log_id' => 'EXCEPTION',
                    'error_message' => $e->getMessage()
                ]));
            }

            // --- SELF THROTTLING ---
            if ($this->targetIntervalMs > 0) {
                $elapsedMs = (microtime(true) - $rowStartTime) * 1000;
                $remainingSleep = $this->targetIntervalMs - $elapsedMs;

                if ($remainingSleep > 0) {
                    usleep($remainingSleep * 1000);
                }
            }

            // HEARTBEAT
            if ($uncommittedProcessed >= $this->heartbeat) {
                $instance->increment('processed_records', $uncommittedProcessed);
                $uncommittedProcessed = 0;
            }
        }

        if ($successFile) {
            fclose($successFile);
        }
        if ($failedFile) {
            fclose($failedFile);
        }

        // Final increments
        if ($uncommittedProcessed > 0) {
            $instance->increment('processed_records', $uncommittedProcessed);
        }
        $instance->increment('success_records', $localSuccess);
        $instance->increment('failed_records', $localFailed);

        // Finalize if last chunk
        $instance->refresh();
        if ($instance->processed_records >= $instance->total_records) {
            (new \App\Modules\Connectors\Services\BatchOrchestrator())->finalize($instance, 'completed', $this->traceId);
        }
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
