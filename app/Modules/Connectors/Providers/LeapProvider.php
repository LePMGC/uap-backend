<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\Facades\Http;
use Exception;

class LeapProvider extends BaseProvider
{
    protected bool $isStateful = false;

    protected function login(): void
    {
    }

    protected function logout(): void
    {
    }

    /**
     * For LEAP REST provider, the payload returned here represents the query parameters
     * serialized ready for dispatch.
     */
    protected function buildPayload(array $commandDef, array $params): string
    {
        \Log::info('LEAP commandDef', $commandDef);

        // 1. System Parameter Pool - containing optional systemic overrides
        $pool = [
            'transactionId' => $this->generateTransactionId(),
            'channelName'   => $this->config['channel_name'] ?? 'UAP-Server',
        ];

        // 2. Resolve allowed system parameters assigned to this command
        $allowedSystemKeys = $commandDef['system_params'] ?? [];
        if (isset($commandDef['meta']['system_keys'])) {
            $allowedSystemKeys = array_fill_keys($commandDef['meta']['system_keys'], true);
        }

        $authorizedSystemParams = [];
        foreach ($allowedSystemKeys as $key => $placeholder) {
            if (array_key_exists($key, $pool)) {
                $authorizedSystemParams[$key] = $pool[$key];
            }
        }

        // 3. Combine with User parameters (User input overrides system defaults if explicitly provided)
        $finalParams = array_merge($authorizedSystemParams, $params);

        return json_encode($finalParams);
    }

    protected function generateTransactionId(): string
    {
        return 'TXN' . now()->format('YmdHis') . rand(1000, 9999);
    }

    /**
     * Dispatches the HTTP REST API requests.
     */
    protected function send(string $payload): string
    {
        $queryParams = json_decode($payload, true) ?? [];

        // 1. Resolve Target Base URL from instance connection settings
        $providerBaseUrl = rtrim($this->config['base_url'] ?? '', '/');

        if (!$providerBaseUrl) {
            $host = rtrim($this->config['host'] ?? '127.0.0.1', '/');
            if (!str_contains($host, '://')) {
                $host = 'http://' . $host;
            }
            $port = $this->config['port'] ?? '9004';
            $providerBaseUrl = "{$host}:{$port}/app_engine/production";
        }

        if (!str_contains($providerBaseUrl, '://')) {
            $providerBaseUrl = 'http://' . $providerBaseUrl;
        }

        // 2. Extract Application ID targeting both blueprint variations
        $appId = trim(
            $this->currentCommand['command_key']
        ?? $this->currentCommand['name']
        ?? ''
        );

        if (empty($appId)) {
            // Fallback check to parse out from either request_payload or sample_payload
            $sampleUrl = $this->blueprint['request_payload']
                ?? $this->blueprint['sample_payload']
                ?? '';

            if (!empty($sampleUrl)) {
                $pathStr = parse_url($sampleUrl, PHP_URL_PATH) ?? '';
                $pathParts = explode('/', trim($pathStr, '/'));
                $appId = end($pathParts);
            }
        }

        // 3. Assemble dynamic routing path string safely
        $url = rtrim($providerBaseUrl, '/') . '/' . ltrim($appId, '/');

        // Telemetry Verification Log
        \Log::info('LEAP Outbound Connection Probe Target', [
            'generated_url' => $url,
            'query_params'  => $queryParams,
            'command_key' => $this->currentCommand['command_key']
        ]);

        // 4. Dispatch the HTTP Request
        $response = Http::withoutVerifying()
            ->timeout(10)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent'   => $this->config['user_agent'] ?? 'UAP-Server/1.0',
                'Content-Type' => 'application/json',
            ])
            ->get($url, $queryParams);

        return $response->body();
    }

    /**
     * Parses the REST JSON Response dynamically based on structural error configurations.
     */
    protected function parseResponse(array $commandDef, string $rawResponse, array $userParams): array
    {
        try {
            $data = json_decode($rawResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON Response structure received from LEAP engine.");
            }

            // LEAP Status Standard Evaluation
            $errorCode = isset($data['errorCode']) ? (string)$data['errorCode'] : '999';
            $responseMessage = $data['responseMessage'] ?? ($errorCode === '0' ? 'Success' : 'Execution failed');

            $isSuccessful = ($errorCode === '0');

            // AUDITING ENGINE LOGGING
            \App\Modules\Core\Auditing\Services\UapLogger::log(
                'LeapEngineREST',
                'PROVIDER_RESPONSE',
                $isSuccessful ? 'info' : 'error',
                [
                    'errorCode' => $errorCode,
                    'message'   => $responseMessage,
                    'msisdn'    => $userParams['MSISDN'] ?? $userParams['bNumber'] ?? 'N/A',
                ],
                $isSuccessful ? 'SUCCESS' : 'FAILURE'
            );

            return [
                'success' => $isSuccessful,
                'code'    => $errorCode,
                'message' => $responseMessage,
                'data'    => $data,
                'raw'     => $rawResponse
            ];

        } catch (Exception $e) {
            throw new Exception("LEAP Response Processing Error: " . $e->getMessage());
        }
    }

    public function checkHealth(): bool
    {
        try {

            $baseUrl = rtrim(
                $this->config['base_url'] ?? '',
                '/'
            );

            if (!$baseUrl) {
                $host = rtrim($this->config['host'], '/');
                $port = $this->config['port'] ?? '9004';
                $environment = $this->config['environment'] ?? 'production';

                $baseUrl = "{$host}:{$port}/app_engine/{$environment}";
            }

            $url = $baseUrl . '/100000000000000';

            $response = Http::timeout(5)->get($url);

            $data = $response->json();

            return
                $response->status() === 404 &&
                isset($data['code']) &&
                $data['code'] === 'V9007';

        } catch (Exception $e) {
            \Log::error('LEAP health check failed', [
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Scans the sample payload to identify systemic injection parameters.
     */
    public function extractSystemParams(string $rawPayload): array
    {
        $detected = [];

        if (str_contains($rawPayload, 'transactionId=')) {
            $detected['transactionId'] = '{auto_gen_id}';
        }

        if (str_contains($rawPayload, 'channelName=')) {
            $detected['channelName'] = 'UAP-Server';
        }

        return $detected;
    }

    /**
     * Parses a sample url query request into separate parameter structures.
     */
    public function parseSamplePayload(string $rawPayload): array
    {
        try {
            if (empty(trim($rawPayload))) {
                return ['method' => '', 'params' => [], 'system_params' => [], 'raw_payload' => ''];
            }

            $urlInfo = parse_url($rawPayload);
            $pathParts = explode('/', $urlInfo['path'] ?? '');
            $appId = end($pathParts);

            $queryParams = [];
            if (isset($urlInfo['query'])) {
                parse_str($urlInfo['query'], $queryParams);
            }

            $userParams = [];
            $systemParams = [];

            // System values pool configuration mapping
            $systemKeysPool = [
                'transactionId' => '{auto_gen_id}',
                'channelName'   => 'UAP-Server'
            ];

            foreach ($queryParams as $key => $value) {
                if (array_key_exists($key, $systemKeysPool)) {
                    $systemParams[$key] = $systemKeysPool[$key];
                } else {
                    $userParams[$key] = ($value === 'null') ? null : $value;
                }
            }

            return [
                'method'        => $appId,
                'params'        => $userParams,
                'system_params' => $systemParams,
                'raw_payload'   => $rawPayload
            ];
        } catch (Exception $e) {
            throw new Exception("Failed parsing LEAP Sample request details: " . $e->getMessage());
        }
    }

    /**
     * Builds Front-end Schema mapping blueprint with all parameters marked as completely optional.
     */
    public function getMappingBlueprint(string $rawPayload): array
    {
        $parsed = $this->parseSamplePayload($rawPayload);
        $blueprint = [];

        foreach ($parsed['params'] as $key => $value) {
            $blueprint[] = [
                'key'         => $key,
                'type'        => 'String',
                'level'       => 0,
                'isParent'    => false,
                'is_required' => false, // Relaxed requirements across all user variables
                'value'       => $value
            ];
        }

        return $blueprint;
    }

    public function extractIdentifier(string $rawPayload): ?string
    {
        $parsed = $this->parseSamplePayload($rawPayload);
        return $parsed['params']['MSISDN'] ?? $parsed['params']['bNumber'] ?? null;
    }

    /**
     * Schema validation checking for structurally valid target URL strings.
     */
    public function validateSamplePayload(string $payload): array
    {
        $errors = [];
        if (filter_var($payload, FILTER_VALIDATE_URL) === false) {
            $errors[] = "Sample request payload must be a valid absolute REST Endpoint URL format string.";
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Polymorphic custom validation routine making sure the exact matching Application ID
     * is explicitly contained and aligned directly inside the endpoint path sequence.
     */
    public function validateCommandKeyOnPayload(string $commandKey, string $payload): array
    {
        $errors = [];
        $urlInfo = parse_url($payload);
        $pathParts = explode('/', $urlInfo['path'] ?? '');
        $urlAppId = trim(end($pathParts));

        if (empty($urlAppId)) {
            $errors[] = "Unable to resolve the dynamic Application ID variable endpoint segment from the given payload path url.";
        } elseif (strcasecmp($urlAppId, trim($commandKey)) !== 0) {
            $errors[] = sprintf(
                "LEAP Application Key mismatch identifier detected! The configuration key states '%s', but the provided target URL addresses route identifier '%s'.",
                $commandKey,
                $urlAppId
            );
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors
        ];
    }
}
