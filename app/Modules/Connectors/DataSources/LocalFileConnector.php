<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class LocalFileConnector implements DataSourceInterface
{
    public function testConnection(array $config): bool
    {
        try {
            // Check if 'file_path' key exists in the payload
            if (!isset($config['file_path'])) {
                return false;
            }

            // We use the default 'local' disk which points to storage/app
            return Storage::disk('local')->exists($config['file_path']);
        } catch (\Exception $e) {
            \Log::error("Local File Test Error: " . $e->getMessage());
            return false;
        }
    }

    public function fetchData(array $config): \Generator
    {
        $disk = Storage::disk('local');
        
        if (!$disk->exists($config['file_path'])) {
            throw new \Exception("File not found: " . $config['file_path']);
        }

        $path = $disk->path($config['file_path']);
        $csv = Reader::createFromPath($path, 'r');
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $record) {
            yield $record;
        }
    }
}