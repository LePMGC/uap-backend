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

    public $tries = 3;
    public $backoff = 30;

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
        protected ?string $traceId = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(CommandExecutor $executor): void
    {
        $instance = JobInstance::with(['template.command'])->find($this->instance->id);

        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        $dir = "jobs/{$instance->id}";
        $successPath = Storage::path("{$dir}/results_success.csv");
        $failedPath = Storage::path("{$dir}/results_failed.csv");

        // Open file handles in append mode
        $successFile = fopen($successPath, 'a');
        $failedFile = fopen($failedPath, 'a');

        $localProcessed = 0; // Total attempts in this chunk
        $localSuccess = 0;   // Successful executions
        $localFailed = 0;    // Failures/Exceptions

        foreach ($this->chunk as $row) {
            $localProcessed++; // Every row in the loop is "processed"

            try {

                $resolvedParams = [];
                // Use the freshly loaded $instance instead of $this->instance
                $mapping = $instance->template->column_mapping ?? [];

                foreach ($mapping as $paramName => $config) {
                    if ($config['excluded'] ?? false) {
                        continue;
                    }

                    if (($config['mode'] ?? 'static') === 'dynamic') {
                        $columnName = $config['value'];
                        $resolvedParams[$paramName] = $row[$columnName] ?? null;
                    } else {
                        $resolvedParams[$paramName] = $config['value'] ?? null;
                    }
                }

                // Unflatten dot notation (e.g., "user.name" => ["user" => ["name" => ...]])
                $nestedParams = [];
                foreach ($resolvedParams as $key => $value) {
                    Arr::set($nestedParams, $key, $value);
                }

                $logEntry = $executor->execute(
                    (int) $instance->template->provider_instance_id,
                    (int) $this->commandId,
                    $nestedParams,
                    (int) $instance->template->user_id,
                    $instance->id,
                    $this->traceId
                );

                if ($logEntry->is_successful) {
                    $localSuccess++; // Increment success
                    $this->appendLocked($successFile, array_merge($row, [
                        'status' => 'SUCCESS',
                        'code' => $logEntry->response_code
                    ]));
                } else {
                    $localFailed++; // Increment failure
                    $this->appendLocked($failedFile, array_merge($row, [
                        'status' => 'FAILED',
                        'error' => $logEntry->response_code
                    ]));
                }

            } catch (Throwable $e) {
                $localFailed++; // Increment failure on exception
                $this->appendLocked($failedFile, array_merge($row, [
                    'status' => 'EXCEPTION',
                    'error' => $e->getMessage()
                ]));

                UapLogger::error('BatchEngine', 'ROW_EXECUTION_EXCEPTION', [
                    'instance_id' => $instance->id,
                    'error' => $e->getMessage()
                ], $this->traceId);
            }
        }

        if ($successFile) {
            fclose($successFile);
        }
        if ($failedFile) {
            fclose($failedFile);
        }

        // Update the database with all three counters
        $instance->update([
            'processed_records' => $instance->processed_records + $localProcessed,
            'success_records'   => $instance->success_records + $localSuccess,
            'failed_records'    => $instance->failed_records + $localFailed,
        ]);

        UapLogger::info('BatchEngine', 'CHUNK_PROCESS_COMPLETED', [
            'instance_id' => $instance->id,
            'total_attempted' => $localProcessed,
            'success' => $localSuccess,
            'failed'  => $localFailed
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
