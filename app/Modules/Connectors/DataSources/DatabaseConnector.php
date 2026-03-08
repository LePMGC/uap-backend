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
        $table = $config['table'] ?? throw new \Exception("Database table not specified.");
        
        UapLogger::info('DataSource', 'DB_FETCH_STARTED', [
            'database' => $config['database'],
            'table' => $table
        ]);

        try {
            $connection = $this->getTempConnection($config);
            foreach ($connection->table($table)->cursor() as $row) {
                yield (array) $row;
            }
        } catch (\Exception $e) {
            UapLogger::error('DataSource', 'DB_FETCH_FAILED', [
                'table' => $table,
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