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
    public function execute(JobInstance $instance, ?string $traceId = null): void
    {
        // 1. Eager load template and its associated command metadata
        $instance->load('template.command');
        $command = $instance->template->command;

        if (!$command) {
            throw new Exception("Batch template is not linked to a valid Command Blueprint.");
        }

        UapLogger::info('BatchEngine', 'JOB_STARTED', [
            'instance_id' => $instance->id,
            'template_id' => $instance->job_template_id,
            'command_id'  => $command->id,
            'command_key' => $command->command_key
        ], $traceId);

        $instance->update(['status' => 'processing', 'started_at' => now()]);
        $dir = "jobs/{$instance->id}";
        Storage::makeDirectory($dir);

        try {
            // 2. Ingest & Prepare Data
            $totalRecords = $this->ingestToLocalFile($instance, $dir);
            $headers = $this->getCsvHeaders($dir);
            $this->initializeResultFiles($dir, $headers);

            // 3. Pre-Flight Validation: Check CSV Mapping vs Command Blueprint
            $this->validateMapping($instance->template);

            // 4. Prepare Chunks
            $reader = Reader::createFromPath(Storage::path("{$dir}/source.csv"), 'r');
            $reader->setHeaderOffset(0);
            
            // To handle very large files efficiently, we chunk the iterator
            $chunks = array_chunk(iterator_to_array($reader->getRecords()), 500);

            $jobs = [];
            foreach ($chunks as $chunk) {
                // Pass the specific commandId to the worker job
                $jobs[] = new ProcessBatchChunk($instance, $chunk, $command->id, $traceId);
            }

            // 5. Dispatch the Batch Pipeline
            Bus::batch($jobs)
                ->name("Batch_Job_{$instance->id}")
                ->then(function ($batch) use ($instance, $traceId) {
                    $service = app(BatchOrchestrator::class);
                    $service->finalize($instance, 'completed', $traceId);
                })
                ->catch(function ($batch, Throwable $e) use ($instance, $traceId) {
                    UapLogger::error('BatchEngine', 'BATCH_CRITICAL_FAILURE', [
                        'instance_id' => $instance->id,
                        'error' => $e->getMessage()
                    ], $traceId);
                    
                    $instance->update([
                        'status' => 'failed', 
                        'error_message' => $e->getMessage()
                    ]);
                })
                ->dispatch();

        } catch (Exception $e) {
            $instance->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Validates that the user's column mapping covers all mandatory 
     * parameters defined in the Command Blueprint.
     */
    protected function validateMapping(JobTemplate $template): void
    {
        // Fetch only parameters marked as mandatory in the database
        $requiredParams = $template->command->parameters()
            ->where('is_mandatory', true)
            ->pluck('name')
            ->toArray();

        // The keys of column_mapping represent the Command parameters
        $mappedCommandKeys = array_keys($template->column_mapping);
        $missing = array_diff($requiredParams, $mappedCommandKeys);

        if (!empty($missing)) {
            throw new Exception("Mapping validation failed. The following mandatory command parameters are not mapped: " . implode(', ', $missing));
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
            'is_successful', 
            'error_message'
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

    public function finalize(JobInstance $instance, string $status, ?string $traceId = null): void
    {
        $instance->refresh();
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
}