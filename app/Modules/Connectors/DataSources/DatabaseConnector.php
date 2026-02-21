<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

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

    // Inside DatabaseConnector.php -> fetchData
    public function fetchData(array $config): \Generator
    {
        $connection = $this->getTempConnection($config);
        
        // Ensure 'table' comes from the Template's config passed through
        $table = $config['table'] ?? throw new \Exception("Database table not specified.");

        foreach ($connection->table($table)->cursor() as $row) {
            yield (array) $row;
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