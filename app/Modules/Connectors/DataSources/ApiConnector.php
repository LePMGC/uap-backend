<?php

namespace App\Modules\Connectors\DataSources;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiConnector implements DataSourceInterface
{
    /**
     * Test the API connection.
     */
    public function testConnection(array $config): bool
    {
        try {
            $endpoint = $config['endpoint'] ?? null;

            if (!$endpoint) {
                return false;
            }

            // We use a short timeout (5s) so the UI doesn't hang if the API is down
            $response = Http::withHeaders($config['headers'] ?? [])
                            ->timeout(5)
                            ->get($endpoint);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("API Connection Test Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch data from the API and yield records.
     */
    public function fetchData(array $config): \Generator
    {
        $endpoint = $config['endpoint'] ?? throw new \Exception("API Endpoint not defined.");
        $dataPath = $config['data_path'] ?? null;

        $response = Http::withHeaders($config['headers'] ?? [])
                        ->get($endpoint, $config['query'] ?? []);

        if (!$response->successful()) {
            throw new \Exception("Failed to fetch data from API: " . $response->status());
        }

        // Get the specific data array based on the path (e.g., 'data.users')
        $data = $response->json($dataPath);

        if (!is_array($data)) {
            Log::warning("API response at path [$dataPath] is not an array for endpoint: $endpoint");
            return;
        }

        foreach ($data as $item) {
            yield $item;
        }
    }
}