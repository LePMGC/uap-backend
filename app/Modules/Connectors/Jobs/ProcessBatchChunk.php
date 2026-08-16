<?php

namespace App\Modules\Connectors\Jobs;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Providers\BaseProvider;
use App\Modules\Core\Auditing\Services\UapLogger;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use League\Csv\Reader;
use League\Csv\Statement;
use Throwable;
use App\Modules\Operations\Services\DynamicProfileResolver;

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

    public function __construct(
        protected $instance,
        protected string $dir,
        protected int $offset,
        protected int $limit,
        protected int $commandId,
        protected ?string $traceId = null,
        protected int $heartbeat = 10,
        protected int $targetIntervalMs = 0
    ) {
    }

    public function handle(
        CommandExecutor $executor,
        DynamicProfileResolver $profileResolver
    ): void {
        $instance = JobInstance::with(['template.command'])->find($this->instance->id);

        if (!$instance || ($this->batch() && $this->batch()->cancelled())) {
            return;
        }

        $blueprint = collect($instance->template->command->mapping_blueprint ?? [])
            ->keyBy('key');

        $requestId = $instance->template->source_config['provisioning_request_id']
                  ?? $instance->instance_parameters['provisioning_request_id']
                  ?? null;

        $provisioningRequest = $requestId ? \App\Modules\Operations\Models\ProvisioningRequest::with('reimbursement')->find($requestId) : null;
        $isManyMany = $provisioningRequest?->reimbursement?->distribution_mode === 'MANY_MANY';

        $dir = $this->dir;
        $sourcePath = Storage::path("{$dir}/source.csv");

        if (!file_exists($sourcePath)) {
            throw new \Exception("Source CSV file not found at {$sourcePath}");
        }

        $reader = Reader::createFromPath($sourcePath, 'r');
        $reader->setHeaderOffset(0);

        $stmt = Statement::create()
            ->offset($this->offset)
            ->limit($this->limit);

        $records = $stmt->process($reader);

        $successFile = fopen(Storage::path("{$dir}/results_success.csv"), 'a');
        $failedFile  = fopen(Storage::path("{$dir}/results_failed.csv"), 'a');

        $localSuccess = 0;
        $localFailed = 0;
        $uncommittedProcessed = 0;

        try {
            foreach ($records as $row) {
                $rowStartTime = microtime(true);
                $uncommittedProcessed++;

                try {
                    // Parameter Mapping
                    $resolvedParams = [];
                    $mapping = $instance->template->column_mapping ?? [];
                    foreach ($mapping as $paramName => $config) {
                        $val = ($config['mode'] ?? 'static') === 'dynamic'
                            ? ($row[$config['value']] ?? null)
                            : ($config['value'] ?? null);

                        if ($val !== null && isset($blueprint[$paramName])) {
                            $expectedType = strtolower($blueprint[$paramName]['type'] ?? '');

                            if (in_array($expectedType, ['integer', 'int', 'i4']) && is_numeric($val)) {
                                $val = (int) $val;
                            } elseif (in_array($expectedType, ['boolean', 'bool'])) {
                                $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                            }
                        }

                        $resolvedParams[$paramName] = $val;
                    }

                    $nestedParams = [];
                    foreach ($resolvedParams as $key => $value) {
                        Arr::set($nestedParams, $key, $value);
                    }

                    $targetProviderId = (int) $instance->template->provider_instance_id;
                    $targetCommandId  = (int) $this->commandId;

                    // DYNAMIC RESOLUTION FOR MANY_MANY MODE
                    if ($isManyMany) {
                        $offerId = $nestedParams['offerId'] ?? $row['offer_id'] ?? $row['offerId'] ?? null;

                        if (!$offerId) {
                            throw new \Exception("Missing required offerId in batch record.");
                        }

                        $profile = $profileResolver->resolveForOfferId($offerId);
                        $targetProviderId = $profile->provisioning_provider_instance_id;
                        $targetCommandId  = $profile->provisioning_command_id;
                    }

                    // Execution via CommandExecutor
                    $logEntry = $executor->execute(
                        $targetProviderId,
                        $targetCommandId,
                        $nestedParams,
                        (int) ($instance->user_id ?? $instance->template->user_id ?? 1),
                        $instance->id,
                        $this->traceId
                    );

                    if ($logEntry->is_successful) {
                        $localSuccess++;
                        $this->appendLocked($successFile, array_merge($row, [
                            'command_log_id' => $logEntry->command_key ?? 'N/A',
                            'response_code'  => $logEntry->response_code
                        ]));
                    } else {
                        $localFailed++;
                        $this->appendLocked($failedFile, array_merge($row, [
                            'command_log_id' => $logEntry->command_key ?? 'N/A',
                            'response_code'  => $logEntry->response_code,
                            'error_message'  => $logEntry->response_payload['message'] ?? 'Provider execution failed'
                        ]));
                    }

                } catch (\Throwable $e) {
                    $localFailed++;
                    $this->appendLocked($failedFile, array_merge($row, [
                        'command_log_id' => 'EXCEPTION',
                        'response_code'  => 500,
                        'error_message'  => $e->getMessage()
                    ]));
                }

                // --- SELF THROTTLING & HEARTBEAT ---
                if ($this->targetIntervalMs > 0) {
                    $elapsedMs = (microtime(true) - $rowStartTime) * 1000;
                    $remainingSleep = $this->targetIntervalMs - $elapsedMs;

                    if ($remainingSleep > 0) {
                        usleep((int) ($remainingSleep * 1000));
                    }
                }

                if ($uncommittedProcessed >= $this->heartbeat) {
                    $instance->increment('processed_records', $uncommittedProcessed);
                    $uncommittedProcessed = 0;
                }
            }
        } finally {
            if ($successFile) {
                fclose($successFile);
            }
            if ($failedFile) {
                fclose($failedFile);
            }

            // Flush active stateful sessions at the end of chunk execution
            BaseProvider::closeActiveSessions();
        }

        // Final increments & completion sync
        if ($uncommittedProcessed > 0) {
            $instance->increment('processed_records', $uncommittedProcessed);
        }
        $instance->increment('success_records', $localSuccess);
        $instance->increment('failed_records', $localFailed);

        $instance->refresh();
        if ($instance->processed_records >= $instance->total_records) {
            (new \App\Modules\Connectors\Services\BatchOrchestrator())->finalize($instance, 'completed', $this->traceId);
        }
    }

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