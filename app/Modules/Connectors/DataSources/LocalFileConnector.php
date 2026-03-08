<?php

namespace App\Modules\Connectors\DataSources;

use App\Modules\Core\Auditing\Services\UapLogger;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class LocalFileConnector implements DataSourceInterface
{
    public function testConnection(array $config): bool
    {
        try {
            $path = $config['file_path'] ?? 'N/A';
            $exists = Storage::disk('local')->exists($path);

            UapLogger::info('DataSource', 'LOCAL_FILE_TEST', [
                'path' => $path,
                'exists' => $exists
            ]);

            return $exists;
        } catch (\Exception $e) {
            UapLogger::error('DataSource', 'LOCAL_FILE_TEST_FAILED', [
                'path' => $config['file_path'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function fetchData(array $config): \Generator
    {
        $disk = Storage::disk('local');
        $filePath = $config['file_path'] ?? 'N/A';
        
        if (!$disk->exists($filePath)) {
            UapLogger::error('DataSource', 'FILE_NOT_FOUND', ['path' => $filePath]);
            throw new \Exception("File not found: " . $filePath);
        }

        UapLogger::info('DataSource', 'LOCAL_FILE_STREAM_STARTED', ['path' => $filePath]);

        try {
            $path = $disk->path($filePath);
            $csv = Reader::createFromPath($path, 'r');
            $csv->setHeaderOffset(0);

            foreach ($csv->getRecords() as $record) {
                yield $record;
            }
        } catch (\Exception $e) {
            UapLogger::error('DataSource', 'CSV_PARSING_FAILED', [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}