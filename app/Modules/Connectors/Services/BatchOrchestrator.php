<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use App\Modules\Connectors\Jobs\ProcessBatchChunk;
use Illuminate\Support\Facades\{Bus, Storage};
use League\Csv\{Reader, Writer};

class BatchOrchestrator
{
    public function execute(JobInstance $instance): void
    {
        // UX: Start Stage
        $instance->update(['status' => 'loading_data', 'started_at' => now()]);
        
        $dir = config('connectors.batch.storage_path', 'jobs') . "/{$instance->id}";
        Storage::makeDirectory($dir);

        $totalRecords = $this->ingestToLocalFile($instance, $dir);
        
        // UX: Dispatching Stage
        $instance->update(['status' => 'dispatching', 'total_records' => $totalRecords]);
        $this->dispatchChunks($instance, $dir);
    }

    protected function ingestToLocalFile(JobInstance $instance, string $dir): int
    {
        $template = $instance->template;
        $connector = DataSourceFactory::make($template->dataSource->type);
        $localPath = Storage::path("{$dir}/source.csv");
        $writer = Writer::createFromPath($localPath, 'w+');
        
        $count = 0;
        foreach ($connector->fetchData(array_merge($template->dataSource->connection_settings, $template->job_specific_config)) as $row) {
            $rowData = (array) $row;
            
            if ($count === 0) {
                // PROFESSIONAL EXPORTS: Initialize headers for result files
                $headers = array_keys($rowData);
                $resHeaders = array_merge($headers, ['batch_status_code', 'batch_is_successful', 'batch_error_message']);
                $headerStr = implode(',', $resHeaders) . PHP_EOL;
                
                Storage::put("{$dir}/results_success.csv", $headerStr);
                Storage::put("{$dir}/results_failed.csv", $headerStr);
                $writer->insertOne($headers);
            }
            $writer->insertOne($rowData);
            $count++;
        }
        return $count;
    }

    protected function dispatchChunks(JobInstance $instance, string $dir): void
    {
        $csv = Reader::createFromPath(Storage::path("{$dir}/source.csv"), 'r');
        $csv->setHeaderOffset(0);
        $chunks = [];
        
        foreach (array_chunk(iterator_to_array($csv->getRecords()), config('connectors.batch.chunk_size')) as $chunk) {
            $chunks[] = new ProcessBatchChunk($instance->id, $chunk);
        }

        Bus::batch($chunks)
            ->then(fn($b) => $this->finalize($instance))
            ->catch(fn($b, $e) => $instance->update(['status' => 'failed']))
            ->dispatch();

        // UX: Processing Stage
        $instance->update(['status' => 'processing']);
    }

    protected function finalize(JobInstance $instance): void
    {
        // UX: Finalizing Stage
        $instance->update(['status' => 'finalizing']);

        // RESOURCE MANAGEMENT: Delete the raw source file
        // Storage::delete(config('connectors.batch.storage_path') . "/{$instance->id}/source.csv");

        $instance->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * RELIABILITY: Placeholder Resolution for Dynamic Configurations
     */
    protected function resolvePlaceholders(array $config): array
    {
        $placeholders = [
            '{Y-m-d}' => now()->format('Y-m-d'),
            '{yesterday}' => now()->subDay()->format('Y-m-d'),
            // Add more as needed
        ];

        return array_map(function ($value) use ($placeholders) {
            return is_string($value) ? strtr($value, $placeholders) : $value;
        }, $config);
    }
}