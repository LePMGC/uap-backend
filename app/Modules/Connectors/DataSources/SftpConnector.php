<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use App\Modules\Core\Auditing\Services\UapLogger;

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
        // Ensure root is set to / so absolute paths from the payload work correctly
        $config['root'] = $config['root'] ?? '/';

        $disk = Storage::build($config);

        // 1. Determine the target file
        $targetFile = $config['file_path'] ?? null;

        // If no direct file_path, search using path and pattern
        if (!$targetFile && isset($config['path'])) {
            $directory = $config['path'];
            $pattern = $config['pattern'] ?? '*';

            // List files in the directory
            $files = $disk->files($directory);

            // Filter files matching the glob pattern (e.g., *.csv)
            $matchingFiles = array_filter($files, function ($file) use ($pattern) {
                return fnmatch($pattern, basename($file));
            });

            if (empty($matchingFiles)) {
                throw new \Exception("No files matching pattern '{$pattern}' found in '{$directory}'");
            }

            // Sort descending to get the "latest" file (lexicographically)
            rsort($matchingFiles);
            $targetFile = $matchingFiles[0];
        }

        if (!$targetFile) {
            throw new \Exception("SFTP source configuration error: Provide 'file_path' or 'path' and 'pattern'.");
        }

        UapLogger::info('DataSource', 'SFTP_FETCH_STARTED', [
            'host' => $config['host'],
            'resolved_file' => $targetFile
        ]);

        // 2. Open Stream and Read
        $stream = $disk->readStream($targetFile);

        if (!$stream) {
            throw new \Exception("Could not open SFTP stream for file: {$targetFile}");
        }

        $csv = Reader::createFromStream($stream);
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $record) {
            yield (array) $record;
        }

        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}
