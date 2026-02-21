<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use Illuminate\Support\Facades\{Bus, Storage};
use League\Csv\Reader;
use League\Csv\Writer;

class BatchOrchestrator
{
    public function execute(JobInstance $instance): void
    {
        $instance->update(['status' => 'loading_data', 'started_at' => now()]);
        
        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$instance->id}";
        Storage::makeDirectory($dir);

        try {
            // 1. Prepare the local source.csv file
            $totalRecords = $this->ingestToLocalFile($instance, $dir);
            
            // 2. Validate the specific file against the Template Mapping (Contract)
            $this->validateInstanceSchema($instance, $dir);

            $instance->update([
                'status' => 'dispatching', 
                'total_records' => $totalRecords
            ]);

            $this->dispatchChunks($instance, $dir);

        } catch (\Exception $e) {
            $instance->update(['status' => 'failed', 'error_log' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Ingests data from the external source to a local CSV file, returning the total record count.
     */
    protected function ingestToLocalFile(JobInstance $instance, string $dir): int
    {
        $template = $instance->template;
        $source = $template->dataSource; // Relationship to public.data_sources
        $localPath = Storage::path("{$dir}/source.csv");

        // Case 1: Manual Upload
        if ($source->type === 'upload') {
            // If the instance already has the file in its directory, we use it directly.
            // Otherwise, we copy the base_source from the template.
            $templatePath = "templates/{$template->id}/base_source.csv";
            if (Storage::exists($templatePath)) {
                Storage::copy($templatePath, "{$dir}/source.csv");
            }
            
            $reader = Reader::createFromPath($localPath, 'r');
            $reader->setHeaderOffset(0);
            $this->initializeResultFiles($dir, $reader->getHeader());
            return count($reader);
        }

        // Case 2: External Sources (SFTP, DB, API)
        $connector = DataSourceFactory::make($source->type);
        $writer = Writer::createFromPath($localPath, 'w+');
        
        // Resolve credentials from DataSource and patterns from Template
        $mergedConfig = array_merge(
            json_decode($source->connection_settings, true) ?? [],
            $template->source_config ?? [],
            $template->job_specific_config ?? []
        );

        $resolvedConfig = $this->resolvePlaceholders($mergedConfig);

        $count = 0;
        foreach ($connector->fetchData($resolvedConfig) as $row) {
            $rowData = (array) $row;
            if ($count === 0) {
                $headers = array_keys($rowData);
                $this->initializeResultFiles($dir, $headers);
                $writer->insertOne($headers);
            }
            $writer->insertOne($rowData);
            $count++;
        }

        return $count;
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

    protected function dispatchChunks(JobInstance $instance, string $dir): void
    {
        $csvPath = Storage::path("{$dir}/source.csv");
        $csv = Reader::createFromPath($csvPath, 'r');
        $csv->setHeaderOffset(0); 

        $chunk = [];
        foreach ($csv->getRecords() as $record) {
            $chunk[] = $record;
            
            if (count($chunk) === 100) {
                ProcessBatchChunk::dispatch($instance->id, $chunk);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            ProcessBatchChunk::dispatch($instance->id, $chunk);
        }
    }

    protected function initializeResultFiles(string $dir, array $headers): void
    {
        $resHeaders = array_merge($headers, ['batch_status_code', 'batch_is_successful', 'batch_error_message']);
        $headerStr = implode(',', $resHeaders) . PHP_EOL;
        
        Storage::put("{$dir}/results_success.csv", $headerStr);
        Storage::put("{$dir}/results_failed.csv", $headerStr);
    }

    protected function resolvePlaceholders(array $config): array
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
}