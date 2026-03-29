<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use App\Modules\Core\Auditing\Services\UapLogger;

class DatabaseConnector implements DataSourceInterface
{
    public function testConnection(array $config): bool
    {
        try {
            $connection = $this->getTempConnection($config);
            $connection->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fetch data from the specified database table using a cursor for memory efficiency.
     */
    public function fetchData(array $config): \Generator
    {
        $mode = $config['mode'] ?? 'table';
        $table = $config['table'] ?? null;
        $query = $config['query'] ?? null;

        // Validation based on mode
        if ($mode === 'query' && !$query) {
            throw new \Exception("Database SQL query not specified for query mode.");
        }
        if ($mode === 'table' && !$table) {
            throw new \Exception("Database table name not specified for table mode.");
        }

        \App\Modules\Core\Auditing\Services\UapLogger::info('DataSource', 'DB_FETCH_STARTED', [
            'database' => $config['database'],
            'mode'     => $mode,
            'target'   => $mode === 'query' ? 'Custom SQL' : $table
        ]);

        try {
            $connection = $this->getTempConnection($config);

            $query = rtrim(trim($query), ';');

            // Determine the base query builder instance
            $queryBuilder = ($mode === 'query')
                ? $connection->table(\DB::raw("({$query}) as sub_query"))
                : $connection->table($table);

            // Use cursor for memory-efficient streaming (Generator)
            foreach ($queryBuilder->cursor() as $row) {
                yield (array) $row;
            }

        } catch (\Exception $e) {
            \App\Modules\Core\Auditing\Services\UapLogger::error('DataSource', 'DB_FETCH_FAILED', [
                'mode'  => $mode,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getTempConnection($config)
    {
        $name = 'temp_external_db';
        Config::set("database.connections.$name", [
            'driver' => $config['driver'], // e.g., mysql, pgsql
            'host' => $config['host'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset' => 'utf8mb4',
        ]);
        return DB::connection($name);
    }
}
