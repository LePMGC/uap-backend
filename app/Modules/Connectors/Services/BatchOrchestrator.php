<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use Illuminate\Support\Facades\{Bus, Storage};
use League\Csv\Reader;
use League\Csv\Writer;
use Illuminate\Bus\Batchable;
use App\Modules\Connectors\Exports\JobInstanceExport;
use Maatwebsite\Excel\Facades\Excel;

class BatchOrchestrator
{
    /**
     * The main entry point for executing a batch job instance.
     */
    /**
     * The main entry point for executing a batch job instance.
     */
    public function execute(JobInstance $instance): void
    {
        // 1. Log job start
        UapLogger::info('BatchEngine', 'JOB_STARTED', [
            'instance_id' => $instance->id,
            'template_id' => $instance->job_template_id,
            'trigger'     => $instance->trigger_type
        ]);

        $instance->update(['status' => 'loading_data', 'started_at' => now()]);
        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$instance->id}";
        Storage::makeDirectory($dir);

        try {
            // 2. Ingest Data (Move from Temp/Upload to Job folder)
            $totalRecords = $this->ingestToLocalFile($instance, $dir);
            
            UapLogger::info('BatchEngine', 'DATA_INGESTED', [
                'instance_id' => $instance->id,
                'total_records' => $totalRecords,
                'directory' => $dir
            ]);

            // 3. Validate Contract (Ensure CSV columns match Template mapping)
            $this->validateContract($instance, $dir);

            // 4. Initialize Result Files (Create empty CSVs with headers for logging success/failures)
            $csvPath = Storage::path("{$dir}/source.csv");
            $reader = Reader::createFromPath($csvPath, 'r');
            $reader->setHeaderOffset(0);
            $this->initializeResultFiles($dir, $reader->getHeader());

            // 5. Update status to Dispatching
            $instance->update([
                'status' => 'dispatching',
                'total_records' => $totalRecords
            ]);

            // 6. Split data into chunks and send to Worker Queues
            $this->dispatchChunks($instance, $dir);

            UapLogger::info('BatchEngine', 'JOB_DISPATCHED', [
                'instance_id' => $instance->id,
                'chunks_count' => ceil($totalRecords / 100)
            ]);

        } catch (\Exception $e) {
            // Handle failure and log it to our custom UAP audit log
            $instance->update([
                'status' => 'failed',
                'error_log' => $e->getMessage(),
                'completed_at' => now()
            ]);

            UapLogger::error('BatchEngine', 'JOB_FAILED', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Validates that the ingested data contains all columns required by the Template's mapping configuration.
     */
    protected function validateContract(JobInstance $instance, string $dir): void
    {
        $template = $instance->template;
        $path = Storage::path("{$dir}/source.csv");
        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(0);
        
        $actualHeaders = $csv->getHeader();
        $requiredHeaders = array_keys($template->column_mapping);

        $missing = array_diff($requiredHeaders, $actualHeaders);

        if (!empty($missing)) {
            throw new \Exception("Data inconsistency: The source is missing columns required by the template mapping: " . implode(', ', $missing));
        }
    }

    /**
     * Ingests data from the external source to a local CSV file, returning the total record count.
     */
    protected function ingestToLocalFile(JobInstance $instance, string $dir): int
    {
        $template = $instance->template;
        $config = $template->source_config;

        // 1. Identify where the source file is
        if (!isset($config['file_path'])) {
            throw new \Exception("Template configuration error: 'file_path' is missing in source_config.");
        }

        $sourcePath = $config['file_path']; // e.g., 'templates/discovery_1_1771639662.csv'
        $destinationPath = "{$dir}/source.csv"; // e.g., 'jobs/uuid/source.csv'

        // 2. Copy the file from templates to the working job directory
        if (Storage::exists($sourcePath)) {
            Storage::copy($sourcePath, $destinationPath);
        } else {
            throw new \Exception("Source file not found at: {$sourcePath}");
        }

        // 3. Count records for the progress bar
        $fullPath = Storage::path($destinationPath);
        $csv = Reader::createFromPath($fullPath, 'r');
        $csv->setHeaderOffset(0);

        return count($csv);
    }

    /**
     * Ensures the ingested file matches the Template's expected mapping
     */
    protected function validateInstanceSchema(JobInstance $instance, string $dir): void
    {
        $template = $instance->template;
        $csvPath = Storage::path("{$dir}/source.csv");
        
        $reader = Reader::createFromPath($csvPath, 'r');
        $reader->setHeaderOffset(0);
        
        $actualHeaders = $reader->getHeader();
        $requiredHeaders = array_keys($template->column_mapping ?? []);
        
        $missing = array_diff($requiredHeaders, $actualHeaders);

        if (!empty($missing)) {
            throw new \Exception("The data source is missing columns required by the template mapping: " . implode(', ', $missing));
        }
    }

    /**
     * Dispatches processing jobs for each chunk of the source data.
     */
    protected function dispatchChunks(JobInstance $instance, string $dir): void
    {
        $csvPath = Storage::path("{$dir}/source.csv");
        $csv = Reader::createFromPath($csvPath, 'r');
        $csv->setHeaderOffset(0);

        // 1. Collect all records into an array
        $allRecords = [];
        foreach ($csv->getRecords() as $record) {
            $allRecords[] = $record;
        }

        if (empty($allRecords)) {
            throw new \Exception("No records found in source file to process.");
        }

        // 2. Initialize the Batch with Callbacks
        $batch = Bus::batch([])
            ->then(function (\Illuminate\Bus\Batch $batch) use ($instance) {
                // 1. Update Database Status
                $instance->update([
                    'status' => 'completed',
                    'completed_at' => now()
                ]);

                // 2. TELECOM LOGGING: Log successful completion of the entire batch
                \App\Modules\Connectors\Services\UapLogger::info('BatchEngine', 'JOB_COMPLETED', [
                    'instance_id'   => $instance->id,
                    'total_records' => $instance->total_records,
                    'duration_sec'  => now()->diffInSeconds($instance->started_at),
                ]);
            })
            ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($instance) {
                // 1. Update Database Status
                $instance->update([
                    'status' => 'failed',
                    'error_log' => $e->getMessage(),
                    'completed_at' => now()
                ]);

                // 2. TELECOM LOGGING: Log the batch-level failure
                \App\Modules\Connectors\Services\UapLogger::error('BatchEngine', 'BATCH_PROCESSING_FAILED', [
                    'instance_id' => $instance->id,
                    'error'       => $e->getMessage()
                ]);
            })
            ->name("Batch Job: {$instance->id}")
            ->dispatch();


        // 3. Add jobs to the batch in chunks of 100
        foreach (array_chunk($allRecords, 100) as $chunk) {
            $batch->add(new \App\Modules\Connectors\Jobs\ProcessBatchChunk($instance->id, $chunk));
        }
    }

    protected function initializeResultFiles(string $dir, array $headers): void
    {
        $resHeaders = array_merge($headers, ['batch_status_code', 'batch_is_successful', 'batch_error_message']);
        $headerStr = implode(',', $resHeaders) . PHP_EOL;
        
        Storage::put("{$dir}/results_success.csv", $headerStr);
        Storage::put("{$dir}/results_failed.csv", $headerStr);
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