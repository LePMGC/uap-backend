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
     * Entry point for executing a batch job instance with dynamic scaling.
     */
    public function execute(JobInstance $instance, ?string $traceId = null): void
    {
        $instance->load('template.command', 'template.providerInstance');
        $command = $instance->template->command;
        $provider = $instance->template->providerInstance;

        if (!$command || !$provider) {
            throw new \Exception("Batch template is missing Command or Provider Instance.");
        }

        // --- DYNAMIC DIMENSIONING LOGIC ---
        $tpsLimit = $provider->tps_limit ?? 50;
        $latency  = max($provider->latency_ms, 50); // Floor at 50ms to prevent div by zero
        $health   = $provider->health_score ?? 100;

        // 1. Calculate how many requests one worker can do per second
        $reqPerWorkerPerSec = 1000 / $latency;

        // 2. Adjust TPS Limit based on Health Score (Safety Brake)
        // If health is below 80, we throttle the allowed TPS to 60% of capacity
        if ($health < 80) {
            $tpsLimit = $tpsLimit * 0.6;
        }

        // 3. Determine Concurrency & Throttling Delay
        // We aim to utilize Horizon workers efficiently without exceeding TPS
        // delay_ms = (1000 / (Target TPS / Number of Workers)) - Latency
        // For simplicity in the worker, we calculate the Target Interval per request
        $targetIntervalMs = 1000 / $tpsLimit;

        // 4. Determine Chunk Size & Heartbeat
        // If latency is high (>1s), use small chunks to avoid Horizon timeouts
        $chunkSize = ($latency > 1000) ? 20 : 100;
        $heartbeat = ($tpsLimit > 100) ? 50 : 10; // Frequent updates for slow jobs, less for fast ones
        // ----------------------------------

        UapLogger::info('BatchEngine', 'JOB_STARTED_WITH_DYNAMIC_SCALING', [
            'instance_id' => $instance->id,
            'tps_target'  => $tpsLimit,
            'latency'     => $latency . 'ms',
            'chunk_size'  => $chunkSize
        ], $traceId);

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

            $reader = Reader::createFromPath(Storage::path("{$dir}/source.csv"), 'r');
            $reader->setHeaderOffset(0);

            $jobs = [];
            $currentChunk = [];

            foreach ($reader->getRecords() as $record) {
                $currentChunk[] = $record;

                if (count($currentChunk) === $chunkSize) {
                    $jobs[] = new ProcessBatchChunk(
                        $instance,
                        $currentChunk,
                        $command->id,
                        $traceId,
                        $heartbeat,
                        (int) $targetIntervalMs // Pass target pace to worker
                    );
                    $currentChunk = [];
                }
            }

            if (!empty($currentChunk)) {
                $jobs[] = new ProcessBatchChunk($instance, $currentChunk, $command->id, $traceId, $heartbeat, (int) $targetIntervalMs);
            }

            Bus::batch($jobs)
                ->then(function ($batch) use ($instance, $traceId) {
                    (new BatchOrchestrator())->finalize($instance, 'completed', $traceId);
                })
                ->allowFailures()
                ->name("Batch-{$instance->id}")
                ->dispatch();

        } catch (Throwable $e) {
            $instance->update(['status' => 'failed', 'completed_at' => now()]);
            UapLogger::error('BatchEngine', 'JOB_INIT_FAILED', ['error' => $e->getMessage()], $traceId);
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
    /**
     * Updated finalize to accept the JobInstance object directly
     */
    public function finalize(JobInstance $instance, string $status = 'completed', ?string $traceId = null): void
    {
        // Check if already completed to prevent duplicate logs/updates
        if ($instance->status === 'completed') {
            return;
        }

        // Mark as completed and set the end time
        $instance->update([
            'status' => $status,
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
