<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use App\Modules\Connectors\Services\UapLogger;

class SftpConnector implements DataSourceInterface
{
    public function testConnection(array $config): bool
    {
        try {
            $config['driver'] = 'sftp';
            $status = Storage::build($config)->exists('.');
            
            UapLogger::info('DataSource', 'SFTP_TEST_CONNECTION', [
                'host' => $config['host'],
                'user' => $config['username'],
                'status' => $status ? 'CONNECTED' : 'FAILED'
            ]);

            return $status;
        } catch (\Exception $e) {
            UapLogger::error('DataSource', 'SFTP_CONNECTION_FAILED', [
                'host' => $config['host'] ?? 'N/A',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // Inside SftpConnector.php -> fetchData
    public function fetchData(array $config): \Generator
    {
        $config['driver'] = 'sftp';
        $disk = Storage::build($config);

        // If 'file_path' contains a pattern or we need to find the latest file
        $targetFile = $config['file_path']; 
        
        // Logic to resolve patterns like {Y-m-d} should happen in Orchestrator 
        // but the connector must handle the stream correctly.
        $stream = $disk->readStream($targetFile);
        
        $csv = Reader::createFromStream($stream);
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $record) {
            yield $record;
        }

        if (is_resource($stream)) fclose($stream);
    }
}