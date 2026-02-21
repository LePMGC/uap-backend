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

    public function discoverHeaders(DataSource $source, array $sourceConfig): array
    {
        // 1. Get the connector (SftpConnector, DatabaseConnector, etc.)
        $connector = DataSourceFactory::make($source->type);

        // 2. Merge connection details from DB with resource details from UI
        $fullConfig = array_merge(
            json_decode($source->connection_settings, true),
            $sourceConfig
        );

        // 3. Resolve placeholders if the user used them in the discovery path
        // Note: We might need to resolve {Y-m-d} even during discovery!
        $resolvedConfig = app(BatchOrchestrator::class)->resolvePlaceholders($fullConfig);

        // 4. Fetch the first row and return keys
        foreach ($connector->fetchData($resolvedConfig) as $row) {
            return array_keys((array) $row);
        }

        return [];
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