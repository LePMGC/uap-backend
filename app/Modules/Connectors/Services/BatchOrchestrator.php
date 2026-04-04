<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use Illuminate\Support\Facades\{Bus, Storage};
use League\Csv\Reader;
use App\Modules\Core\Auditing\Services\UapLogger;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;
use Exception;

class BatchOrchestrator
{
    /**
     * Entry point for executing a batch job instance.
     */
    /**
         * Entry point for executing a batch job instance.
         */
    public function execute(JobInstance $instance, ?string $traceId = null): void
    {
        $instance->load('template.command');
        $command = $instance->template->command;

        if (!$command) {
            throw new \Exception("Batch template is not linked to a valid Command Blueprint.");
        }

        UapLogger::info('BatchEngine', 'JOB_STARTED', [
            'instance_id' => $instance->id,
            'template_id' => $instance->job_template_id,
            'command_id'  => $command->id,
        ], $traceId);

        // 1. Set status to 'processing' and mark the start time
        $instance->update([
            'status'     => 'processing',
            'started_at' => now()
        ]);

        $dir = "jobs/{$instance->id}";
        Storage::makeDirectory($dir);

        try {
            $this->ingestToLocalFile($instance, $dir);
            $headers = $this->getCsvHeaders($dir);
            $this->initializeResultFiles($dir, $headers);

            // --- NEW DYNAMIC STRATEGY ---
            $totalRows = $instance->total_records;
            $chunkSize = $this->calculateDynamicChunkSize($totalRows);
            $heartbeat = $this->calculateDynamicHeartbeat($totalRows);
            // ----------------------------

            $reader = Reader::createFromPath(Storage::path("{$dir}/source.csv"), 'r');
            $reader->setHeaderOffset(0);

            $jobs = [];
            $chunk = [];

            foreach ($reader->getRecords() as $record) {
                $chunk[] = $record;

                if (count($chunk) === $chunkSize) {
                    // Pass the dynamic heartbeat to the Job
                    $jobs[] = new ProcessBatchChunk($instance, $chunk, $command->id, $traceId, $heartbeat);
                    $chunk = [];
                }
            }

            if (!empty($chunk)) {
                $jobs[] = new ProcessBatchChunk($instance, $chunk, $command->id, $traceId, $heartbeat);
            }

            // Use a Bus Batch for Horizon to monitor
            $batch = Bus::batch($jobs)
                ->then(function ($batch) use ($instance, $traceId) {
                    // Use the ID to find a fresh instance in the worker process
                    $orchestrator = new BatchOrchestrator();
                    $orchestrator->finalize($instance->id, $traceId);
                })
                ->catch(function ($batch, Throwable $e) use ($instance, $traceId) {
                    UapLogger::error('BatchEngine', 'BATCH_FAILED', ['error' => $e->getMessage()], $traceId);
                    JobInstance::where('id', $instance->id)->update(['status' => 'failed']);
                })
                ->name("Batch-{$instance->id}")
                ->dispatch();

        } catch (Throwable $e) {
            // Handle immediate errors (e.g. file system errors, validation errors)
            $instance->update([
                'status'       => 'failed',
                'completed_at' => now()
            ]);

            UapLogger::error('BatchEngine', 'JOB_INITIALIZATION_FAILED', [
                'instance_id' => $instance->id,
                'error'       => $e->getMessage()
            ], $traceId);

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
        return 100;                      // Small jobs -> 100 rows per job
    }

    /**
     * Scale heartbeat so the DB isn't hammered by 40+ concurrent workers.
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

        // Since parameters aren't in the DB, we check if the mapping contains
        // at least the essential keys required to build the payload.
        foreach ($mapping as $param => $config) {
            if (!isset($config['mode']) || !isset($config['value'])) {
                throw new Exception("Mapping validation failed: Parameter '{$param}' has an invalid structure.");
            }
        }
    }

    /**
     * Initializes the result files with original headers + audit metadata.
     */
    protected function initializeResultFiles(string $dir, array $headers): void
    {
        // We add 'command_log_id' so users can trace individual rows in the audit logs
        $resHeaders = array_merge($headers, [
            'command_log_id',
            'response_code',
        ]);

        $headerLine = implode(',', $resHeaders) . PHP_EOL;

        Storage::put("{$dir}/results_success.csv", $headerLine);
        Storage::put("{$dir}/results_failed.csv", $headerLine);
    }

    /**
     * Moves the source file to the job's permanent directory.
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

        $reader = Reader::createFromPath(Storage::path($destination), 'r');
        if ($sourceConfig['has_header'] ?? true) {
            $reader->setHeaderOffset(0);
        }

        $count = count(iterator_to_array($reader->getRecords()));
        $instance->update(['total_records' => $count]);

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
     * Updated finalize to accept ID and strictly update status
     */
    public function finalize(string $instanceId, ?string $traceId = null): void
    {
        $instance = JobInstance::find($instanceId);

        if (!$instance) {
            UapLogger::error('BatchEngine', 'FINALIZE_FAILED_NOT_FOUND', ['id' => $instanceId], $traceId);
            return;
        }

        // Mark as completed and set the end time
        $instance->update([
            'status' => 'completed',
            'completed_at' => now()
        ]);

        UapLogger::info('BatchEngine', 'JOB_COMPLETED', [
            'instance_id' => $instance->id,
            'total' => $instance->total_records,
            'success' => $instance->success_records,
            'failed' => $instance->failed_records
        ], $traceId);
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
                // Based on your 'cat' output, look for 'error_message' or 'status'
                // If you saved the numeric code in the CSV, use that key.
                $errorCode = $record['response_code'] ?? $record['status'] ?? 'Unknown Error';

                if (!isset($analysis[$errorCode])) {
                    $analysis[$errorCode] = 0;
                }
                $analysis[$errorCode]++;
            }

            // Format for Frontend: [['code' => '...', 'count' => ...]]
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
}
