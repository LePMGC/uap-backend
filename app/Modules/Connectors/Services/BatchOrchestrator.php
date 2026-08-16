<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Services\BatchReadinessService;
use App\Modules\Connectors\Exports\JobInstanceExport;
use App\Modules\Core\Auditing\Services\UapLogger;
use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class BatchOrchestrator
{
    /**
     * Entry point for executing a batch job instance with dynamic scaling.
     */
    public function execute(JobInstance $instance, ?string $traceId = null): void
    {
        // Increase memory limit for big batch operations.
        ini_set('memory_limit', '512M');

        Log::info('[BatchOrchestrator] Execute started', [
            'instance_id' => $instance->id,
            'trace_id'    => $traceId,
            'memory_mb'   => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);

        try {
            /*
             * ---------------------------------------------------------
             * 1. Retrieve linked ProvisioningRequest / Reimbursement
             * ---------------------------------------------------------
             */
            $requestId = $instance->template->source_config['provisioning_request_id']
                ?? $instance->instance_parameters['provisioning_request_id']
                ?? null;

            $request = $requestId
                ? \App\Modules\Operations\Models\ProvisioningRequest::with('reimbursement')
                    ->find($requestId)
                : null;

            $isManyMany = $request?->reimbursement?->distribution_mode === 'MANY_MANY';

            /*
             * ---------------------------------------------------------
             * 2. Platform readiness check
             * ---------------------------------------------------------
             */
            Log::info('[BatchOrchestrator] Running readiness check');

            $readiness = app(BatchReadinessService::class)->check(
                false,
                $isManyMany ? null : $instance->template->provider_instance_id,
                $isManyMany ? null : $instance->template->command_id,
                $instance->template->data_source_id
            );

            if (!$readiness['ready']) {
                Log::error('[BatchOrchestrator] Readiness check failed', [
                    'instance_id' => $instance->id,
                    'checks'      => $readiness['checks'],
                    'trace_id'    => $traceId,
                ]);

                $instance->update([
                    'status'         => 'failed',
                    'completed_at'   => now(),
                    'failure_reason' => 'Platform readiness check failed.',
                ]);

                throw new Exception(
                    'Batch cannot start because platform prerequisites are not satisfied.'
                );
            }

            /*
             * ---------------------------------------------------------
             * 3. Load template relations
             * ---------------------------------------------------------
             */
            Log::info('[BatchOrchestrator] Loading template relations');

            $instance->load(
                'template.command',
                'template.providerInstance'
            );

            $command  = $instance->template->command;
            $provider = $instance->template->providerInstance;

            if (!$command || !$provider) {
                throw new Exception(
                    'Batch template is missing Command or Provider Instance.'
                );
            }

            /*
             * ---------------------------------------------------------
             * 4. Mark instance as processing
             * ---------------------------------------------------------
             */
            $instance->update([
                'status'     => 'processing',
                'started_at' => now(),
            ]);

            /*
             * ---------------------------------------------------------
             * 5. Prepare batch directory
             * ---------------------------------------------------------
             */
            $dir = "jobs/{$instance->id}";

            Log::info('[BatchOrchestrator] Preparing batch directory', [
                'relative_path' => $dir,
                'group'         => $this->getBatchFilesGroup(),
            ]);

            if (!Storage::makeDirectory($dir)) {
                throw new RuntimeException(
                    "Failed to create batch directory: {$dir}"
                );
            }

            $fullPath = Storage::path($dir);

            $this->prepareBatchDirectory($fullPath);

            Log::info('[BatchOrchestrator] Batch directory ready', [
                'path'  => $fullPath,
                'group' => $this->getBatchFilesGroup(),
                'mode'  => $this->getPathMode($fullPath),
            ]);

            /*
             * ---------------------------------------------------------
             * 6. Ingest source file
             * ---------------------------------------------------------
             */
            Log::info('[1] Starting ingestToLocalFile()');

            $totalRecords = $this->ingestToLocalFile(
                $instance,
                $dir
            );

            Log::info('[2] ingestToLocalFile() complete', [
                'total_records' => $totalRecords,
            ]);

            /*
             * ---------------------------------------------------------
             * 7. Handle empty source
             * ---------------------------------------------------------
             */
            if ($totalRecords === 0) {
                $headers = $this->getCsvHeaders($dir);

                $this->initializeResultFiles(
                    $dir,
                    $headers
                );

                Log::info('[BatchOrchestrator] Source contains no records', [
                    'instance_id' => $instance->id,
                ]);

                $instance->update([
                    'success_records' => 0,
                    'failed_records'  => 0,
                ]);

                $this->finalize(
                    $instance,
                    'completed',
                    $traceId
                );

                return;
            }

            /*
             * ---------------------------------------------------------
             * 8. Calculate dynamic scaling parameters
             * ---------------------------------------------------------
             */
            $chunkSize = $this->calculateDynamicChunkSize(
                $totalRecords
            );

            $heartbeat = $this->calculateDynamicHeartbeat(
                $totalRecords
            );

            $tpsLimit = max(
                (float) ($provider->tps_limit ?? 50),
                1
            );

            $targetIntervalMs = 1000 / $tpsLimit;

            Log::info('[BatchOrchestrator] Scaling dynamically calculated', [
                'total_records'      => $totalRecords,
                'chunk_size'         => $chunkSize,
                'heartbeat'          => $heartbeat,
                'tps_limit'          => $tpsLimit,
                'target_interval_ms' => $targetIntervalMs,
            ]);

            /*
             * ---------------------------------------------------------
             * 9. Initialize result files
             * ---------------------------------------------------------
             */
            $headers = $this->getCsvHeaders($dir);

            $this->initializeResultFiles(
                $dir,
                $headers
            );

            /*
             * ---------------------------------------------------------
             * 10. Build lightweight ProcessBatchChunk jobs
             * ---------------------------------------------------------
             */
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
                    (int) $targetIntervalMs
                );

                $offset += $chunkSize;
            }

            Log::info('[6] Finished building jobs', [
                'total_records' => $totalRecords,
                'jobs_created'  => count($jobs),
                'memory_mb'     => round(
                    memory_get_usage(true) / 1024 / 1024,
                    2
                ),
            ]);

            /*
             * ---------------------------------------------------------
             * 11. Dispatch Laravel batch
             * ---------------------------------------------------------
             */
            Log::info('[7] Dispatching Bus::batch()', [
                'instance_id' => $instance->id,
                'job_count'   => count($jobs),
            ]);

            Bus::batch($jobs)
                ->then(function ($batch) use ($instance, $traceId) {
                    Log::info('[8] Batch completed callback', [
                        'instance_id' => $instance->id,
                        'batch_id'    => $batch->id ?? null,
                        'trace_id'    => $traceId,
                    ]);

                    (new self())->finalize(
                        $instance,
                        'completed',
                        $traceId
                    );
                })
                ->allowFailures()
                ->name("Batch-{$instance->id}")
                ->dispatch();

            Log::info('[9] Bus::batch dispatched successfully', [
                'instance_id' => $instance->id,
            ]);
        } catch (Throwable $e) {
            Log::error('[BatchOrchestrator] Exception during execute()', [
                'instance_id' => $instance->id,
                'message'     => $e->getMessage(),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
                'trace_id'    => $traceId,
            ]);

            $instance->update([
                'status'       => 'failed',
                'completed_at' => now(),
            ]);

            UapLogger::error(
                'BatchEngine',
                'JOB_INIT_FAILED',
                [
                    'instance_id' => $instance->id,
                    'error'       => $e->getMessage(),
                    'file'        => $e->getFile(),
                    'line'        => $e->getLine(),
                ],
                $traceId
            );

            throw $e;
        }
    }

    /**
     * Scale chunk size so we don't create millions of tiny jobs
     * for Horizon to manage.
     */
    protected function calculateDynamicChunkSize(int $total): int
    {
        if ($total > 100000) {
            return 1000;
        }

        if ($total > 10000) {
            return 500;
        }

        return 100;
    }

    /**
     * Scale heartbeat so the DB isn't hammered by concurrent workers.
     */
    protected function calculateDynamicHeartbeat(int $total): int
    {
        if ($total > 100000) {
            return 100;
        }

        if ($total > 10000) {
            return 50;
        }

        return 10;
    }

    /**
     * Validates that the user's column mapping covers all mandatory
     * parameters defined in the Command Blueprint.
     */
    protected function validateMapping(JobTemplate $template): void
    {
        $mapping = $template->column_mapping;

        if (empty($mapping)) {
            throw new Exception(
                'Mapping validation failed: No columns have been mapped.'
            );
        }

        foreach ($mapping as $param => $config) {
            if (
                !isset($config['mode']) ||
                !isset($config['value'])
            ) {
                throw new Exception(
                    "Mapping validation failed: Parameter '{$param}' has an invalid structure."
                );
            }
        }
    }

    /**
     * Prepare a batch directory for cooperative access by PHP-FPM and Horizon workers.
     */
    protected function prepareBatchDirectory(string $path): void
    {
        if (!is_dir($path)) {
            throw new RuntimeException(
                "Batch directory does not exist: {$path}"
            );
        }

        $group = $this->getBatchFilesGroup();

        if (!chmod($path, 02775)) {
            throw new RuntimeException(
                "Failed to set permissions 2775 on {$path}"
            );
        }

        $this->applyGroupOwnership($path, $group);

        /*
         * Re-apply chmod after chgrp as a defensive measure.
         * This keeps the setgid/group-write policy explicit.
         */
        if (!chmod($path, 02775)) {
            throw new RuntimeException(
                "Failed to re-apply permissions 2775 on {$path}"
            );
        }
    }

    /**
     * Prepare a batch file for cooperative access by PHP-FPM and Horizon workers.
     */
    protected function prepareBatchFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException(
                "Batch file does not exist: {$path}"
            );
        }

        $group = $this->getBatchFilesGroup();

        if (!chmod($path, 0664)) {
            throw new RuntimeException(
                "Failed to set permissions 0664 on {$path}"
            );
        }

        $this->applyGroupOwnership($path, $group);

        /*
         * Re-apply chmod after chgrp as a defensive measure.
         */
        if (!chmod($path, 0664)) {
            throw new RuntimeException(
                "Failed to re-apply permissions 0664 on {$path}"
            );
        }
    }

    /**
     * Safely apply group ownership without halting execution if the group does not exist on the host system.
     */
    protected function applyGroupOwnership(string $path, string $group): void
    {
        if (empty($group)) {
            return;
        }

        try {
            if (function_exists('posix_getgrnam') && posix_getgrnam($group) === false) {
                Log::warning("[BatchOrchestrator] OS group '{$group}' does not exist on this system. Skipping chgrp for path: {$path}");
                return;
            }

            @chgrp($path, $group);
        } catch (Throwable $e) {
            Log::warning("[BatchOrchestrator] Could not set group '{$group}' on {$path}: " . $e->getMessage());
        }
    }

    /**
     * Initializes the result files with original headers + audit metadata.
     */
    protected function initializeResultFiles(
        string $dir,
        array $headers
    ): void {
        $resHeaders = array_merge(
            $headers,
            [
                'command_log_id',
                'response_code',
                'error_message',
            ]
        );

        $headerLine = implode(',', $resHeaders) . PHP_EOL;

        $successRel = "{$dir}/results_success.csv";
        $failedRel  = "{$dir}/results_failed.csv";

        /*
         * Create files.
         */
        if (!Storage::put($successRel, $headerLine)) {
            throw new RuntimeException(
                "Failed to create {$successRel}"
            );
        }

        if (!Storage::put($failedRel, $headerLine)) {
            throw new RuntimeException(
                "Failed to create {$failedRel}"
            );
        }

        /*
         * Enforce group and permissions.
         */
        $successPath = Storage::path($successRel);
        $failedPath  = Storage::path($failedRel);

        $this->prepareBatchFile($successPath);
        $this->prepareBatchFile($failedPath);

        Log::info('[BatchOrchestrator] Result files initialized', [
            'success_file' => $successPath,
            'failed_file'  => $failedPath,
            'group'        => $this->getBatchFilesGroup(),
            'mode'         => $this->getPathMode($successPath),
        ]);
    }

    /**
     * Moves the source file to the job's permanent directory
     * and updates permissions.
     */
    protected function ingestToLocalFile(
        JobInstance $instance,
        string $dir
    ): int {
        $template     = $instance->template;
        $sourceConfig = $template->source_config;
        $tempPath     = $sourceConfig['temporary_path'] ?? null;

        if (
            !$tempPath ||
            !Storage::exists($tempPath)
        ) {
            throw new Exception(
                'Source file not found at: ' .
                ($tempPath ?? 'NULL')
            );
        }

        $destination = "{$dir}/source.csv";

        if (!Storage::copy($tempPath, $destination)) {
            throw new RuntimeException(
                "Failed to copy source file to {$destination}"
            );
        }

        $sourcePath = Storage::path($destination);

        /*
         * Make source.csv readable/writable by workers.
         */
        $this->prepareBatchFile($sourcePath);

        Log::info('[BatchOrchestrator] Source file prepared', [
            'path'  => $sourcePath,
            'group' => $this->getBatchFilesGroup(),
            'mode'  => $this->getPathMode($sourcePath),
        ]);

        $reader = Reader::createFromPath(
            $sourcePath,
            'r'
        );

        if ($sourceConfig['has_header'] ?? true) {
            $reader->setHeaderOffset(0);
        }

        $count = 0;

        foreach ($reader->getRecords() as $_) {
            $count++;
        }

        $instance->update([
            'total_records' => $count,
        ]);

        return $count;
    }

    /**
     * Get CSV headers from source.csv.
     */
    protected function getCsvHeaders(string $dir): array
    {
        $path = Storage::path(
            "{$dir}/source.csv"
        );

        if (!file_exists($path)) {
            throw new RuntimeException(
                "Source CSV does not exist: {$path}"
            );
        }

        $reader = Reader::createFromPath(
            $path,
            'r'
        );

        $reader->setHeaderOffset(0);

        return $reader->getHeader();
    }

    /**
     * Finalize batch execution status.
     */
    public function finalize(
        JobInstance $instance,
        string $status = 'completed',
        ?string $traceId = null
    ): void {
        if ($instance->status === 'completed') {
            return;
        }

        $instance->update([
            'status'       => $status,
            'completed_at' => now(),
        ]);

        UapLogger::info(
            'BatchEngine',
            'JOB_COMPLETED',
            [
                'instance_id' => $instance->id,
                'total'       => $instance->total_records,
                'success'     => $instance->success_records,
                'failed'      => $instance->failed_records,
            ],
            $traceId
        );

        $this->syncProvisioningRequestStatus(
            $instance,
            $status
        );
    }

    /**
     * Synchronize ProvisioningRequest status.
     */
    protected function syncProvisioningRequestStatus(
        JobInstance $instance,
        string $status
    ): void {
        $requestId = $instance->template->source_config['provisioning_request_id']
            ?? $instance->instance_parameters['provisioning_request_id']
            ?? null;

        if (!$requestId) {
            return;
        }

        $request = \App\Modules\Operations\Models\ProvisioningRequest::find(
            $requestId
        );

        if (!$request) {
            return;
        }

        $isSuccess = (
            $status === 'completed' &&
            $instance->failed_records === 0
        );

        if ($isSuccess) {
            $request->update([
                'status'         => 'SUCCESS',
                'execution_step' => 'COMPLETED',
                'completed_at'   => now(),
            ]);

            if ($request->reimbursement) {
                $request->reimbursement->update([
                    'provisioning_status' => 'SUCCESS',
                ]);
            }

            return;
        }

        $request->update([
            'status'        => 'FAILED',
            'error_message' => sprintf(
                'Batch completed with %d failed records.',
                $instance->failed_records
            ),
            'completed_at' => now(),
        ]);

        if ($request->reimbursement) {
            $request->reimbursement->update([
                'provisioning_status' => 'FAILED',
            ]);
        }
    }

    /**
     * Generate a report for the job instance.
     */
    public function generateReport(
        JobInstance $instance,
        string $format
    ) {
        $fileName = "Report_Job_{$instance->id}.{$format}";

        $export = new JobInstanceExport($instance);

        $writerType = match ($format) {
            'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
            'pdf'  => \Maatwebsite\Excel\Excel::DOMPDF,
            default => \Maatwebsite\Excel\Excel::CSV,
        };

        return Excel::download(
            $export,
            $fileName,
            $writerType
        );
    }

    /**
     * Parses the failure CSV and returns an aggregated
     * count of error codes/messages.
     */
    public function analyzeErrorFile(
        JobInstance $instance
    ): array {
        $dir = "jobs/{$instance->id}";

        $path = Storage::path(
            "{$dir}/results_failed.csv"
        );

        if (
            !file_exists($path) ||
            filesize($path) === 0
        ) {
            return [];
        }

        try {
            $csv = Reader::createFromPath(
                $path,
                'r'
            );

            $csv->setHeaderOffset(0);

            $analysis = [];

            foreach ($csv->getRecords() as $record) {
                $errorCode =
                    $record['response_code']
                    ?? $record['status']
                    ?? $record['error_message']
                    ?? 'Unknown Error';

                if (!isset($analysis[$errorCode])) {
                    $analysis[$errorCode] = 0;
                }

                $analysis[$errorCode]++;
            }

            return collect($analysis)
                ->map(function ($count, $code) {
                    return [
                        'code'  => $code,
                        'count' => $count,
                    ];
                })
                ->values()
                ->toArray();
        } catch (Throwable $e) {
            Log::warning(
                '[BatchOrchestrator] Failed to analyze error CSV',
                [
                    'instance_id' => $instance->id,
                    'path'        => $path,
                    'message'     => $e->getMessage(),
                ]
            );

            return [
                [
                    'code'  => 'Analysis Error',
                    'count' => $instance->failed_records,
                ],
            ];
        }
    }

    /**
     * Return filesystem permissions in octal format.
     */
    protected function getPathMode(string $path): string
    {
        if (!file_exists($path)) {
            return 'unknown';
        }

        return substr(
            sprintf('%o', fileperms($path)),
            -4
        );
    }

    /**
     * Get the operating-system group used for batch files.
     * Checks config/filesystems.php first, then fallback to config/batch.php, then .env
     */
    protected function getBatchFilesGroup(): string
    {
        return config(
            'filesystems.batch_files_group',
            config('batch.files_group', env('BATCH_FILES_GROUP', 'www-data'))
        );
    }
}