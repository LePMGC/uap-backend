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
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
    ) {
    }

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
            $resolvedParams = [];
            $mapping = $this->instance->template->column_mapping;

            foreach ($mapping as $paramName => $config) {
                if ($config['excluded'] ?? false) {
                    continue;
                }

                if ($config['mode'] === 'dynamic') {
                    // Pull the value from the CSV column named in 'value'
                    $columnName = $config['value'];
                    $resolvedParams[$paramName] = $row[$columnName] ?? null;
                } else {
                    // Static value
                    $resolvedParams[$paramName] = $config['value'];
                }
            }

            // Now, we must UNFLATTEN the resolvedParams (dot notation to nested array)
            // before sending to the CommandExecutor/Provider
            $nestedParams = [];
            foreach ($resolvedParams as $key => $value) {
                \Illuminate\Support\Arr::set($nestedParams, $key, $value);
            }

            // Special fix for UCIP arrays if needed (matching our BatchSchemaService logic)
            if (isset($nestedParams['dedicatedAccountUpdateInformation']) &&
                !isset($nestedParams['dedicatedAccountUpdateInformation'][0])) {
                $nestedParams['dedicatedAccountUpdateInformation'] = [$nestedParams['dedicatedAccountUpdateInformation']];
            }

            // Execute via the existing CommandExecutor
            $this->executor->execute(
                $this->instance->provider_instance_id,
                $this->commandId,
                $nestedParams, // Pass the structured nested array
                $this->instance->template->user_id,
                $this->instance->id
            );
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
