<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\DataSource;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use League\Csv\Reader;

class BatchSchemaService
{
    /**
     * Discovers headers by connecting to the defined DataSource 
     * and applying the specific Template configuration.
     */

    /**
     * Discovers headers by connecting to the defined DataSource 
     * and applying the specific Template configuration.
     */
    public function discoverHeaders(DataSource $source, array $sourceConfig): array
    {
        // Log the start of the discovery process
        \App\Modules\Core\Auditing\Services\UapLogger::info('SchemaService', 'HEADER_DISCOVERY_STARTED', [
            'source_type' => $source->type,
            'source_id'   => $source->id,
            'user_provided_config' => $sourceConfig
        ]);

        try {
            // 1. Get the connector (SftpConnector, DatabaseConnector, etc.)
            $connector = DataSourceFactory::make($source->type);

            // 2. Merge connection details from DB with resource details from UI
            $connectionSettings = is_array($source->connection_settings)
                ? $source->connection_settings
                : json_decode($source->connection_settings ?? '{}', true);

            $fullConfig = array_merge(
                $connectionSettings ?? [],
                $sourceConfig
            );

            // 3. Resolve placeholders if the user used them in the discovery path
            $resolvedConfig = app(BatchOrchestrator::class)->resolvePlaceholders($fullConfig);

            // 4. Fetch the first row and return keys
            $headers = [];
            foreach ($connector->fetchData($resolvedConfig) as $row) {
                $headers = array_keys((array) $row);
                break; // We only need the first row for headers
            }

            if (empty($headers)) {
                \App\Modules\Core\Auditing\Services\UapLogger::error('SchemaService', 'HEADER_DISCOVERY_EMPTY', [
                    'resolved_config' => $resolvedConfig
                ], 'FAILURE');
                return [];
            }

            // Log successful discovery
            \App\Modules\Core\Auditing\Services\UapLogger::info('SchemaService', 'HEADER_DISCOVERY_COMPLETED', [
                'headers_found_count' => count($headers),
                'headers' => $headers
            ]);

            return $headers;

        } catch (\Exception $e) {
            // Log the failure with exception details
            \App\Modules\Core\Auditing\Services\UapLogger::error('SchemaService', 'HEADER_DISCOVERY_FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'FAILURE');
            
            return [];
        }
    }

    /**
     * Direct discovery for manual file uploads during template creation.
     */
    public function getHeadersFromUpload($file): array
    {
        $csv = Reader::createFromPath($file->getRealPath(), 'r');
        $csv->setHeaderOffset(0);
        return $csv->getHeader();
    }
}