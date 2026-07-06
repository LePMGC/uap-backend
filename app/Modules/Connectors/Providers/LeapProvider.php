<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\Facades\Http;
use Exception;

class LeapProvider extends BaseProvider
{
    protected bool $isStateful = false; // REST HTTP implementation is stateless
    protected array $statusRegistry;

    public function __construct(array $config, array $blueprint)
    {
        parent::__construct($config, $blueprint);
        // Load custom operational response maps
        $this->statusRegistry = require __DIR__ . '/../Config/leap_codes.php';
    }

    protected function login(): void
    {
    }
    protected function logout(): void
    {
    }

    /**
     * Build the raw URL path parameter list string.
     */
    protected function buildPayload(array $commandDef, array $params): string
    {
        // 1. Establish hardcoded baseline system metadata context
        $pool = [
            'requestType' => 'Self',
            'bNumber'     => 'NA',
            'extRequest'  => 'YES',
            'channelName' => $this->config['channel_name'] ?? 'BSS',
        ];

        // 2. Map and bind explicit parameters passed down from automation/user forms
        foreach ($commandDef['user_params'] as $key) {
            if (isset($params[$key])) {
                $pool[$key] = $params[$key];
            }
        }

        // 3. Construct the clean target absolute URL with sorted query arguments
        $baseUrl = rtrim($this->config['base_url'] ?? $this->config['host'], '/');

        return $baseUrl . '?' . http_build_query($pool);
    }

    /**
     * Dispatch the HTTP payload directly against the server.
     */
    protected function send(string $payload): string
    {
        // For LEAP, the payload string itself IS the absolute request URL with parameters attached
        $response = Http::timeout(15)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->get($payload);

        if ($response->failed()) {
            throw new Exception("LEAP Server Connection Failure: HTTP Status Code " . $response->status());
        }

        return $response->body();
    }

    /**
     * Evaluate incoming response data packages against the codes registry.
     */
    protected function parseResponse(array $commandDef, string $rawResponse, array $userParams): array
    {
        $decoded = json_decode($rawResponse, true);

        if (!$decoded) {
            return [
                'status'  => 'FAILED',
                'code'    => 'UNKNOWN_FORMAT',
                'message' => 'Provisioning target returned invalid JSON response data structures.',
                'raw'     => $rawResponse
            ];
        }

        $responseCode = $decoded['responseCode'] ?? 'UNKNOWN';
        $isSuccess = ($responseCode === 'S200');

        return [
            'status'         => $isSuccess ? 'SUCCESS' : 'FAILED',
            'code'           => $responseCode,
            'message'        => $this->statusRegistry['responses'][$responseCode] ?? 'Unmapped LEAP API Response Exception',
            'transaction_id' => $decoded['responseDescription']['transactionId'] ?? ($userParams['transactionId'] ?? null),
            'details'        => $decoded['responseDescription'] ?? []
        ];
    }

    /**
     * Automate system health heartbeat checks.
     */
    public function checkHealth(): bool
    {
        try {
            $targetUrl = $this->config['base_url'] ?? null;
            if (!$targetUrl) {
                $baseUrl = rtrim($this->config['host'], '/');
                $port = isset($this->config['port']) ? ':' . $this->config['port'] : '';
                $targetUrl = $baseUrl . $port;
            }

            // Fire a lightweight probe to see if the web server port responds
            $response = Http::timeout(3)->get($targetUrl, [
                'MSISDN' => '0000000000'
            ]);

            return $response->status() < 500;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Parse system parameter tokens out of a raw request URL string.
     */
    public function extractSystemParams(string $rawPayload): array
    {
        $query = parse_url($rawPayload, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);

        return array_intersect_key($params, array_flip(['requestType', 'bNumber', 'extRequest', 'channelName']));
    }

    /**
     * Convert a raw string back into operational array parameters for FE execution test components.
     */
    public function parseSamplePayload(string $rawPayload): array
    {
        $query = parse_url($rawPayload, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);

        return [
            'method' => 'GET',
            'params' => array_diff_key($params, array_flip(['requestType', 'bNumber', 'extRequest', 'channelName']))
        ];
    }

    /**
     * Retrieve the unique targeted telephone subscriber entity.
     */
    public function extractIdentifier(string $rawPayload): ?string
    {
        $query = parse_url($rawPayload, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);

        return $params['MSISDN'] ?? null;
    }
}
