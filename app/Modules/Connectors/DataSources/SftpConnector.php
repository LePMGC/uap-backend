<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class SftpConnector implements DataSourceInterface
{
    public function testConnection(array $config): bool
    {
        try {
            // Force the driver to sftp so Storage::build knows what to do
            $config['driver'] = 'sftp';
            
            return Storage::build($config)->exists('.');
        } catch (\Exception $e) {
            \Log::error("SFTP connection test failed: " . $e->getMessage());
            return false;
        }
    }

    public function fetchData(array $config): \Generator
    {
        $config['driver'] = 'sftp';
        
        $disk = Storage::build($config);
        $stream = $disk->readStream($config['file_path']);
        
        $csv = Reader::createFromStream($stream);
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $record) {
            yield $record;
        }

        if (is_resource($stream)) fclose($stream);
    }
}