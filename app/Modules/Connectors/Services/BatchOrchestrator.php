<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use Illuminate\Support\Facades\{Bus, Storage};
use League\Csv\Reader;
use App\Modules\Core\Auditing\Services\UapLogger;
use App\Modules\Connectors\Services\BatchReadinessService;
use Maatwebsite\Excel\Facades\Excel;
use App\Modules\Connectors\Exports\JobInstanceExport;
use Illuminate\Support\Facades\Log;
use Throwable;
use Exception;

class BatchOrchestrator
{
    /**
     * Entry point for executing a batch job instance with dynamic scaling.
     */
    public function execute(JobInstance $instance, ?string $traceId = null): void
    {
        // Increase memory limit for big batch operations
        ini_set('memory_limit', '512M');

        Log::info('[BatchOrchestrator] Execute started', [
            'instance_id' => $instance->id,
            'trace_id'    => $traceId,
            'memory_mb'   => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);

        // Retrieve linked ProvisioningRequest/Reimbursement
        $requestId = $instance->template->source_config['provisioning_request_id']
                ?? $instance->instance_parameters['provisioning_request_id']
                ?? null;

        $request = $requestId
            ? \App\Modules\Operations\Models\ProvisioningRequest::with('reimbursement')->find($requestId)
            : null;

        $isManyMany = $request?->reimbursement?->distribution_mode === 'MANY_MANY';

        Log::info('[BatchOrchestrator] Running readiness check');

        $readiness = app(BatchReadinessService::class)->check(
            false,
            $isManyMany ? null : $instance->template->provider_instance_id,
            $isManyMany ? null : $instance->template->command_id,
            $instance->template->data_source_id
        );

        if (!$readiness['ready']) {
            Log::error('[BatchOrchestrator] Readiness check failed', ['checks' => $readiness['checks']]);
            $instance->update([
                'status'         => 'failed',
                'completed_at'   => now(),
                'failure_reason' => 'Platform readiness check failed.'
            ]);
            throw new Exception('Batch cannot start because platform prerequisites are not satisfied.');
        }

        Log::info('[BatchOrchestrator] Loading template relations');

        $instance->load('template.command', 'template.providerInstance');
        $command  = $instance->template->command;
        $provider = $instance->template->providerInstance;

        if (!$command || !$provider) {
            throw new Exception("Batch template is missing Command or Provider Instance.");
        }

        $instance->update([
            'status'     => 'processing',
            'started_at' => now()
        ]);

        $dir = "jobs/{$instance->id}";
        Storage::makeDirectory($dir);
        $fullPath = Storage::path($dir);

        @chmod($fullPath, 0775);
        @chgrp($path, $this->getBatchFilesGroup());

        try {
            Log::info('[1] Starting ingestToLocalFile()');

            // Step 1: Ingest file and get total row count
            $totalRecords = $this->ingestToLocalFile($instance, $dir);

            Log::info('[2] ingestToLocalFile() complete', ['total_records' => $totalRecords]);

            // Calculate dynamic scaling parameters based on total records
            $chunkSize = $this->calculateDynamicChunkSize($totalRecords);
            $heartbeat = $this->calculateDynamicHeartbeat($totalRecords);

            $tpsLimit  = $provider->tps_limit ?? 50;
            $latency   = max($provider->latency_ms, 50);
            $targetIntervalMs = 1000 / $tpsLimit;

            Log::info('[BatchOrchestrator] Scaling dynamically calculated', [
                'total_records'      => $totalRecords,
                'chunk_size'         => $chunkSize,
                'heartbeat'          => $heartbeat,
                'target_interval_ms' => $targetIntervalMs,
            ]);

            $headers = $this->getCsvHeaders($dir);
            $this->initializeResultFiles($dir, $headers);

            // Step 2: Build lightweight job descriptors with offset & limit
            $jobs   = [];
            $offset = 0;

            while ($offset < $totalRecords) {
                $jobs[] = new ProcessBatchChunk(
                    $instance,
                    $dir,
                    $offset,
                    $chunkSize,
                    $command->id,
                    $traceId,
                    $heartbeat,
                    (int)$targetIntervalMs
                );

                $offset += $chunkSize;
            }

            Log::info('[6] Finished building jobs', [
                'total_records' => $totalRecords,
                'jobs_created'  => count($jobs),
                'memory_mb'     => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);

            Log::info('[7] Dispatching Bus::batch()');

            Bus::batch($jobs)
                ->then(function ($batch) use ($instance, $traceId) {
                    Log::info('[8] Batch completed callback');
                    (new BatchOrchestrator())->finalize(
                        $instance,
                        'completed',
                        $traceId
                    );
                })
                ->allowFailures()
                ->name("Batch-{$instance->id}")
                ->dispatch();

            Log::info('[9] Bus::batch dispatched successfully');

        } catch (Throwable $e) {
            Log::error('[BatchOrchestrator] Exception during execute()', [
                'message'  => $e->getMessage(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
                'trace_id' => $traceId,
            ]);

            $instance->update([
                'status'       => 'failed',
                'completed_at' => now()
            ]);

            UapLogger::error(
                'BatchEngine',
                'JOB_INIT_FAILED',
                [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ],
                $traceId
            );

            throw $e;
        }
    }

    /**
     * Scale chunk size so we don't create millions of tiny jobs for Horizon to manage.
     */
    protected function calculateDynamicChunkSize(int $total): int
    {
        if ($total > 100000) {
            return 1000;
        } // 100k+ rows -> 1,000 rows per job
        if ($total > 10000) {
            return 500;
        }  // 10k+ rows -> 500 rows per job
        return 100;
    }

    /**
     * Scale heartbeat so the DB isn't hammered by concurrent workers.
     */
    protected function calculateDynamicHeartbeat(int $total): int
    {
        if ($total > 100000) {
            return 100;
        } // Update DB every 100 rows
        if ($total > 10000) {
            return 50;
        }  // Update DB every 50 rows
        return 10;                      // Update DB every 10 rows
    }

    /**
     * Validates that the user's column mapping covers all mandatory
     * parameters defined in the Command Blueprint.
     */
    protected function validateMapping(JobTemplate $template): void
    {
        $mapping = $template->column_mapping;

        if (empty($mapping)) {
            throw new Exception("Mapping validation failed: No columns have been mapped.");
        }

        foreach ($mapping as $param => $config) {
            if (!isset($config['mode']) || !isset($config['value'])) {
                throw new Exception("Mapping validation failed: Parameter '{$param}' has an invalid structure.");
            }
        }
    }

    /**
     * Initializes the result files with original headers + audit metadata,
     * setting group write permissions (0664, uaplog).
     */
    protected function initializeResultFiles(string $dir, array $headers): void
    {
        $resHeaders = array_merge($headers, [
            'command_log_id',
            'response_code',
            'error_message',
        ]);

        $headerLine = implode(',', $resHeaders) . PHP_EOL;

        $successRel = "{$dir}/results_success.csv";
        $failedRel  = "{$dir}/results_failed.csv";

        Storage::put($successRel, $headerLine);
        Storage::put($failedRel, $headerLine);

        // Assign uaplog group & 0664 permissions so workers can open in append mode
        $successPath = Storage::path($successRel);
        $failedPath  = Storage::path($failedRel);

        @chmod($successPath, 0664);
        @chgrp($path, $this->getBatchFilesGroup());

        @chmod($failedPath, 0664);
        @chgrp($path, $this->getBatchFilesGroup());
    }

    /**
     * Moves the source file to the job's permanent directory and updates permissions.
     */
    protected function ingestToLocalFile(JobInstance $instance, string $dir): int
    {
        $template = $instance->template;
        $sourceConfig = $template->source_config;
        $tempPath = $sourceConfig['temporary_path'] ?? null;

        if (!$tempPath || !Storage::exists($tempPath)) {
            throw new Exception("Source file not found at: " . ($tempPath ?? 'NULL'));
        }

        $destination = "{$dir}/source.csv";
        Storage::copy($tempPath, $destination);

        $sourcePath = Storage::path($destination);
        @chmod($sourcePath, 0664);
        @chgrp($path, $this->getBatchFilesGroup());

        $reader = Reader::createFromPath($sourcePath, 'r');
        if ($sourceConfig['has_header'] ?? true) {
            $reader->setHeaderOffset(0);
        }

        $count = 0;

        foreach ($reader->getRecords() as $_) {
            $count++;
        }

        $instance->update([
            'total_records' => $count
        ]);

        return $count;
    }

    protected function getCsvHeaders(string $dir): array
    {
        $path = Storage::path("{$dir}/source.csv");
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);
        return $reader->getHeader();
    }

    /**
     * Finalize batch execution status.
     */
    public function finalize(JobInstance $instance, string $status = 'completed', ?string $traceId = null): void
    {
        if ($instance->status === 'completed') {
            return;
        }

        $instance->update([
            'status'       => $status,
            'completed_at' => now()
        ]);

        UapLogger::info('BatchEngine', 'JOB_COMPLETED', [
            'instance_id' => $instance->id,
            'total'       => $instance->total_records,
            'success'     => $instance->success_records,
            'failed'      => $instance->failed_records
        ], $traceId);

        $this->syncProvisioningRequestStatus($instance, $status);
    }

    protected function syncProvisioningRequestStatus(JobInstance $instance, string $status): void
    {
        $requestId = $instance->template->source_config['provisioning_request_id']
                  ?? $instance->instance_parameters['provisioning_request_id']
                  ?? null;

        if (!$requestId) {
            return;
        }

        $request = \App\Modules\Operations\Models\ProvisioningRequest::find($requestId);
        if (!$request) {
            return;
        }

        $isSuccess = ($status === 'completed') && ($instance->failed_records === 0);

        if ($isSuccess) {
            $request->update([
                'status'         => 'SUCCESS',
                'execution_step' => 'COMPLETED',
                'completed_at'   => now(),
            ]);
            if ($request->reimbursement) {
                $request->reimbursement->update(['provisioning_status' => 'SUCCESS']);
            }
        } else {
            $request->update([
                'status'        => 'FAILED',
                'error_message' => "Batch completed with {$instance->failed_records} failed records.",
                'completed_at'  => now(),
            ]);
            if ($request->reimbursement) {
                $request->reimbursement->update(['provisioning_status' => 'FAILED']);
            }
        }
    }

    public function generateReport(JobInstance $instance, string $format)
    {
        $fileName = "Report_Job_{$instance->id}.{$format}";
        $export = new JobInstanceExport($instance);

        $writerType = match ($format) {
            'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
            'pdf'  => \Maatwebsite\Excel\Excel::DOMPDF,
            default => \Maatwebsite\Excel\Excel::CSV,
        };

        return Excel::download($export, $fileName, $writerType);
    }

    /**
     * Parses the failure CSV and returns an aggregated count of error codes/messages.
     */
    public function analyzeErrorFile(JobInstance $instance): array
    {
        $dir = "jobs/{$instance->id}";
        $path = Storage::path("{$dir}/results_failed.csv");

        if (!file_exists($path) || filesize($path) === 0) {
            return [];
        }

        try {
            $csv = Reader::createFromPath($path, 'r');
            $csv->setHeaderOffset(0);

            $analysis = [];
            foreach ($csv->getRecords() as $record) {
                $errorCode = $record['response_code'] ?? $record['status'] ?? $record['error_message'] ?? 'Unknown Error';

                if (!isset($analysis[$errorCode])) {
                    $analysis[$errorCode] = 0;
                }
                $analysis[$errorCode]++;
            }

            return collect($analysis)->map(function ($count, $code) {
                return [
                    'code' => $code,
                    'count' => $count
                ];
            })->values()->toArray();

        } catch (\Exception $e) {
            return [['code' => 'Analysis Error', 'count' => $instance->failed_records]];
        }
    }

    /**
     * Get the operating-system group used for batch files.
     */
    protected function getBatchFilesGroup(): string
    {
        return config('batch.files_group', 'uaplog');
    }
}