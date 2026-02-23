<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use Illuminate\Support\Facades\{Bus, Storage};
use League\Csv\Reader;
use App\Modules\Connectors\Services\UapLogger;
use Throwable;

class BatchOrchestrator
{
    public function execute(JobInstance $instance, ?string $traceId = null): void
    {
        UapLogger::info('BatchEngine', 'JOB_STARTED', [
            'instance_id' => $instance->id,
            'template_id' => $instance->job_template_id
        ], $traceId);

        $instance->update(['status' => 'processing', 'started_at' => now()]);
        $dir = "jobs/{$instance->id}";
        Storage::makeDirectory($dir);

        // 1. Ingest & Prepare Data
        $totalRecords = $this->ingestToLocalFile($instance, $dir);
        $this->initializeResultFiles($dir, $this->getCsvHeaders($dir));

        // 2. Define the Chunks
        $reader = Reader::createFromPath(Storage::path("{$dir}/source.csv"), 'r');
        $reader->setHeaderOffset(0);
        $chunks = array_chunk(iterator_to_array($reader->getRecords()), 500);

        $jobs = [];
        foreach ($chunks as $chunk) {
            $jobs[] = new ProcessBatchChunk($instance, $chunk, $traceId);
        }

        // 3. IMPROVEMENT: The Batch Pipeline with Callbacks
        Bus::batch($jobs)
            ->name("Batch_Job_{$instance->id}")
            ->then(function ($batch) use ($instance, $traceId) {
                // All chunks finished successfully
                $service = app(BatchOrchestrator::class);
                $service->finalize($instance, 'completed', $traceId);
            })
            ->catch(function ($batch, Throwable $e) use ($instance, $traceId) {
                // First batch failure detected
                UapLogger::error('BatchEngine', 'BATCH_CRITICAL_FAILURE', [
                    'instance_id' => $instance->id,
                    'error' => $e->getMessage()
                ], $traceId);
                
                $instance->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            })
            ->finally(function ($batch) use ($instance, $traceId) {
                // Cleanup or final logging
            })
            ->dispatch();
    }

    /**
     * Moves the source file from temporary storage to the job's permanent directory.
     * returns the total number of records in the file.
     */
    protected function ingestToLocalFile(JobInstance $instance, string $dir): int
    {
        $template = $instance->template;
        $sourceConfig = $template->source_config;
        
        // 1. Get the path from the template config
        $tempPath = $sourceConfig['temporary_path'] ?? null;

        if (!$tempPath || !Storage::exists($tempPath)) {
            throw new \Exception("Source file not found at: " . ($tempPath ?? 'NULL'));
        }

        // 2. Move to local job directory
        $destination = "{$dir}/source.csv";
        Storage::copy($tempPath, $destination);

        // 3. Count records (subtracting 1 if there is a header)
        $reader = Reader::createFromPath(Storage::path($destination), 'r');
        $hasHeader = $sourceConfig['has_header'] ?? true;
        
        if ($hasHeader) {
            $reader->setHeaderOffset(0);
        }

        $count = count(iterator_to_array($reader->getRecords()));
        
        $instance->update(['total_records' => $count]);
        
        return $count;
    }

    /**
     * Reads the headers from the job's source CSV file.
     */
    protected function getCsvHeaders(string $dir): array
    {
        $path = Storage::path("{$dir}/source.csv");
        
        if (!file_exists($path)) {
            throw new \Exception("Cannot read headers: Source file missing at {$path}");
        }

        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0); // Assumes the first row is the header
        
        return $reader->getHeader();
    }

    /**
     * Finalize the job: Update status and create the downloadable result package.
     */
    public function finalize(JobInstance $instance, string $status, ?string $traceId = null): void
    {
        $instance->refresh(); // Get latest counts from DB
        
        // Update final state
        $instance->update([
            'status' => $status,
            'completed_at' => now(),
        ]);

        UapLogger::info('BatchEngine', 'JOB_FINALIZED', [
            'instance_id' => $instance->id,
            'status'      => $status,
            'processed'   => $instance->processed_records,
            'failed'      => $instance->failed_records
        ], $traceId);

        // Notify user via Webhook or Database Notification
        // $instance->user->notify(new BatchCompletedNotification($instance));
    }

    protected function initializeResultFiles(string $dir, array $headers): void
    {
        $resHeaders = array_merge($headers, ['batch_status_code', 'batch_is_successful', 'batch_error_message']);
        $headerLine = implode(',', $resHeaders) . PHP_EOL;
        
        // We use put() for the first time to create the file with headers
        Storage::put("{$dir}/results_success.csv", $headerLine);
        Storage::put("{$dir}/results_failed.csv", $headerLine);
    }

    public function resolvePlaceholders(array $config): array
    {
        $now = now();
        $placeholders = [
            '{Y-m-d}'    => $now->format('Y-m-d'),
            '{Ymd}'      => $now->format('Ymd'),
            '{yesterday}' => $now->subDay()->format('Y-m-d'),
            '{Y-m}'      => $now->format('Y-m'),
        ];

        return array_map(function ($value) use ($placeholders) {
            if (!is_string($value)) return $value;
            return strtr($value, $placeholders);
        }, $config);
    }


    public function generateReport(JobInstance $instance, string $format)
    {
        $fileName = "Report_Job_{$instance->id}.{$format}";
        
        // Define the export class (we'll create this next)
        $export = new JobInstanceExport($instance);

        $writerType = match ($format) {
            'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
            'pdf'  => \Maatwebsite\Excel\Excel::DOMPDF,
            default => \Maatwebsite\Excel\Excel::CSV,
        };

        return Excel::download($export, $fileName, $writerType);
    }
}