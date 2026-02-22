<?php
namespace App\Modules\Connectors\DataSources;

use App\Modules\Connectors\Services\UapLogger;
use Illuminate\Support\Facades\Http;

class ApiConnector implements DataSourceInterface
{
    public function testConnection(array $config): bool
    {
        $endpoint = $config['endpoint'] ?? 'N/A';
        
        try {
            $response = Http::withHeaders($config['headers'] ?? [])
                            ->timeout(5)
                            ->get($endpoint);

            $status = $response->successful();

            UapLogger::info('DataSource', 'API_CONNECTION_TEST', [
                'endpoint' => $endpoint,
                'status_code' => $response->status(),
                'success' => $status
            ]);

            return $status;
        } catch (\Exception $e) {
            UapLogger::error('DataSource', 'API_CONNECTION_CRASH', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function fetchData(array $config): \Generator
    {
        $endpoint = $config['endpoint'] ?? throw new \Exception("API Endpoint not defined.");
        $dataPath = $config['data_path'] ?? 'root';

        UapLogger::info('DataSource', 'API_FETCH_DATA_ATTEMPT', [
            'endpoint' => $endpoint,
            'data_path' => $dataPath
        ]);

        $response = Http::withHeaders($config['headers'] ?? [])
                        ->get($endpoint, $config['query'] ?? []);

        if (!$response->successful()) {
            UapLogger::error('DataSource', 'API_FETCH_DATA_HTTP_ERROR', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception("Failed to fetch data from API: " . $response->status());
        }

        $data = $response->json($config['data_path'] ?? null);

        if (!is_array($data)) {
            UapLogger::error('DataSource', 'API_INVALID_RESPONSE_FORMAT', [
                'endpoint' => $endpoint,
                'expected_path' => $dataPath,
                'received_type' => gettype($data)
            ]);
            return;
        }

        UapLogger::info('DataSource', 'API_FETCH_DATA_SUCCESS', [
            'endpoint' => $endpoint,
            'records_found' => count($data)
        ]);

        foreach ($data as $item) {
            yield $item;
        }
    }
}