<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\DataSource;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use League\Csv\Reader;
use Illuminate\Support\Facades\Log;
use App\Modules\Core\Auditing\Services\UapLogger;

class BatchSchemaService
{
    /**
     * Direct discovery for manual file uploads.
     */
    public function getSchemaFromUpload($file, int $limit = 5): array
    {
        try {
            $csv = Reader::createFromPath($file->getRealPath(), 'r');
            $csv->setHeaderOffset(0);

            $headers = $csv->getHeader();
            $rows = [];

            $records = $csv->getRecords();
            $count = 0;
            foreach ($records as $record) {
                if ($count >= $limit) {
                    break;
                }
                $rows[] = $record;
                $count++;
            }

            return [
                'headers' => $headers,
                'rows'    => $rows
            ];
        } catch (\Exception $e) {
            Log::error("Upload Schema Discovery Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Discovers schema (headers + sample rows) from a remote DataSource (DB, SFTP, API).
     */
    /**
         * Discovers schema (headers + sample rows) from a remote DataSource.
         */
    public function discoverSchema(DataSource $dataSource, array $requestConfig, int $limit = 5): array
    {
        try {
            \App\Modules\Core\Auditing\Services\UapLogger::info('SchemaService', 'REMOTE_SCHEMA_DISCOVERY_STARTED', [
                'source_type' => $dataSource->type,
                'source_id'   => $dataSource->id
            ]);

            $connector = DataSourceFactory::make($dataSource->type);

            $connectionSettings = is_array($dataSource->connection_settings)
                ? $dataSource->connection_settings
                : json_decode($dataSource->connection_settings ?? '{}', true);

            // 1. Handle Database Mode logic specifically
            if ($dataSource->type === 'database') {
                $mode = $requestConfig['mode'] ?? 'table';

                if ($mode === 'query' && empty($requestConfig['query'])) {
                    throw new \Exception("SQL Query is required when mode is set to 'query'.");
                }

                if ($mode === 'table' && empty($requestConfig['table'])) {
                    throw new \Exception("Table name is required when mode is set to 'table'.");
                }
            }

            // 2. Merge credentials with the specific request config
            $fullConfig = array_merge($connectionSettings, $requestConfig);

            // 3. Fetch data stream
            $iterator = $connector->fetchData($fullConfig);

            $headers = [];
            $rows = [];
            $count = 0;

            foreach ($iterator as $row) {
                // Convert row to associative array
                $rowData = json_decode(json_encode($row), true);

                if (empty($headers)) {
                    $headers = array_keys($rowData);
                }

                if ($count < $limit) {
                    $rows[] = $rowData;
                    $count++;
                } else {
                    break;
                }
            }

            if (empty($headers)) {
                throw new \Exception("No data found to discover headers. Ensure the table/query returns results.");
            }

            return [
                'headers' => $headers,
                'rows'    => $rows
            ];

        } catch (\Exception $e) {
            \App\Modules\Core\Auditing\Services\UapLogger::error('SchemaService', 'REMOTE_SCHEMA_DISCOVERY_FAILED', [
                'type'  => $dataSource->type,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
