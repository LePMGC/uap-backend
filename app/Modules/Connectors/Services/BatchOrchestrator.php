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
        $instance->load('template.command');
        $command = $instance->template->command;

        if (!$command) {
            throw new Exception("Batch template is not linked to a valid Command Blueprint.");
        }

        UapLogger::info('BatchEngine', 'JOB_STARTED', [
            'instance_id' => $instance->id,
            'template_id' => $instance->job_template_id,
            'command_id'  => $command->id,
        ], $traceId);

        $instance->update(['status' => 'processing', 'started_at' => now()]);
        $dir = "jobs/{$instance->id}";
        Storage::makeDirectory($dir);

        try {
            $this->ingestToLocalFile($instance, $dir);
            $headers = $this->getCsvHeaders($dir);
            $this->initializeResultFiles($dir, $headers);

            // 1. Updated Validation to handle JSON structure
            $this->validateMapping($instance->template);

            $reader = Reader::createFromPath(Storage::path("{$dir}/source.csv"), 'r');
            $reader->setHeaderOffset(0);

            // We convert to array for chunking.
            // Note: For multi-million row files, consider a custom ChunkIterator to save memory.
            $chunks = array_chunk(iterator_to_array($reader->getRecords()), 500);

            $jobs = [];
            foreach ($chunks as $chunk) {
                // We pass the instance and the raw chunk.
                // The ProcessBatchChunk job will resolve the JSON mapping for each row.
                $jobs[] = new ProcessBatchChunk($instance, $chunk, $command->id, $traceId);
            }

            Bus::batch($jobs)
                ->name("Batch_Job_{$instance->id}")
                ->then(function ($batch) use ($instance, $traceId) {
                    $service = app(BatchOrchestrator::class);
                    $service->finalize($instance, 'completed', $traceId);
                })
                ->catch(function ($batch, Throwable $e) use ($instance, $traceId) {
                    $instance->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
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
            'success'     => $instance->success_records,
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
