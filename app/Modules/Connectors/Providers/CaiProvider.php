<?php

namespace App\Modules\Connectors\Providers;

use Exception;

class CaiProvider extends BaseProvider
{
    protected bool $isStateful = true;
    protected array $statusRegistry;
    private $connection;

    /**
     * Static connection pools across instantiations in the same CLI process
     */
    protected static array $socketPool = [];
    protected static array $authenticatedPool = [];

    public function __construct(array $config, array $blueprint)
    {
        parent::__construct($config, $blueprint);
        $this->statusRegistry = require __DIR__ . '/../Config/cai_codes.php';
    }

    protected function login(): void
    {
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 0;
        $connectionKey = "{$host}:{$port}";

        // 1. Reuse existing persistent TCP socket if active
        if (isset(self::$socketPool[$connectionKey]) && is_resource(self::$socketPool[$connectionKey])) {
            $this->connection = self::$socketPool[$connectionKey];
        } else {
            $this->connection = @pfsockopen($host, $port, $errno, $errstr, 10);

            if (!$this->connection) {
                throw new Exception("CAI Connection failed: $errstr ($errno)");
            }

            self::$socketPool[$connectionKey] = $this->connection;
            self::$authenticatedPool[$connectionKey] = false;
        }

        // 2. Skip LOGIN command if session is already authenticated on this persistent socket
        if (!empty(self::$authenticatedPool[$connectionKey])) {
            $this->authenticated = true;
            return;
        }

        $loginCmd = "LOGIN:{$this->config['username']}:{$this->config['password']};";
        $response = $this->send($loginCmd);

        if (!$this->isResponseSuccessful($response)) {
            throw new Exception("CAI Authentication Failed: " . $response);
        }

        $this->authenticated = true;
        self::$authenticatedPool[$connectionKey] = true;
    }

    protected function send(string $payload): string
    {
        if (!$this->connection || !is_resource($this->connection)) {
            throw new Exception("Cannot send command: TCP connection is not active.");
        }

        fwrite($this->connection, $payload . "\n");

        $buffer = "";
        while (!str_contains($buffer, ';')) {
            $chunk = fgets($this->connection, 4096);
            if ($chunk === false) {
                break;
            }
            $buffer .= $chunk;
        }
        return trim($buffer);
    }

    protected function logout(): void
    {
        // Suppress teardown on individual commands during batch execution
        if (!empty($this->config['job_instance_id']) || $this->inBatchSession) {
            return;
        }

        $this->forceLogout();
    }

    /**
     * Sends LOGOUT and closes TCP socket connection
     */
    public function forceLogout(): void
    {
        $host = $this->config['host'] ?? '';
        $port = $this->config['port'] ?? 0;
        $connectionKey = "{$host}:{$port}";

        $conn = self::$socketPool[$connectionKey] ?? $this->connection;

        if ($conn && is_resource($conn)) {
            try {
                fwrite($conn, "LOGOUT;\n");
            } catch (\Throwable $e) {
                // Suppress teardown socket errors
            }
            fclose($conn);
        }

        $this->connection = null;
        $this->authenticated = false;
        unset(self::$socketPool[$connectionKey], self::$authenticatedPool[$connectionKey]);
    }

    /**
     * Closes all active pooled sessions when ProcessBatchChunk completes
     */
    public static function closeActiveSessions(): void
    {
        foreach (self::$socketPool as $key => $conn) {
            if ($conn && is_resource($conn)) {
                try {
                    fwrite($conn, "LOGOUT;\n");
                } catch (\Throwable $e) {
                    // Suppress teardown errors
                }
                fclose($conn);
            }
        }
        self::$socketPool = [];
        self::$authenticatedPool = [];
    }

    public function beginSession(): void
    {
        $this->inBatchSession = true;
        $this->login();
    }

    public function endSession(): void
    {
        try {
            $this->forceLogout();
        } finally {
            $this->inBatchSession = false;
        }
    }

    protected function buildPayload(array $commandDef, array $params, ?string $operatorId = null): string
    {
        $payload = $commandDef['request_payload'] ?? '';

        if (empty($payload)) {
            throw new Exception("No MML payload template found in the command definition.");
        }

        foreach ($params as $key => $value) {
            $pattern = "/\b" . preg_quote($key, '/') . ",[^:;]*/";
            $replacement = $key . "," . $value;

            $payload = preg_replace($pattern, $replacement, $payload);
        }

        return rtrim(trim($payload), ';') . ';';
    }

    public function parseResponse(array $commandDef, string $rawResponse, array $userParams): array
    {
        preg_match('/RESP:(\d+)/', $rawResponse, $matches);
        $code = isset($matches[1]) ? (int)$matches[1] : null;

        $isSuccessful = ($code === 0);
        $message = $this->statusRegistry['responses'][$code] ?? "Unknown CAI Code ($code)";

        \App\Modules\Core\Auditing\Services\UapLogger::log(
            'EricssonCAI',
            'PROVIDER_RESPONSE',
            $isSuccessful ? 'info' : 'error',
            [
                'code' => $code,
                'message' => $message,
                'msisdn' => $this->parseCaiData($rawResponse)['MSISDN'] ?? 'N/A'
            ],
            $isSuccessful ? 'SUCCESS' : 'FAILURE'
        );

        return [
            'success' => $isSuccessful,
            'code'    => $code,
            'message' => $message,
            'data'    => $this->parseCaiData($rawResponse),
            'raw'     => $rawResponse
        ];
    }

    private function isResponseSuccessful(string $rawResponse): bool
    {
        return str_contains($rawResponse, 'RESP:0');
    }

    private function parseCaiData(string $raw): array
    {
        \Log::debug("Parsing CAI Response Data", ['raw' => $raw]);

        $data = [];
        $clean = rtrim(trim($raw), ';');
        $parts = explode(':', $clean);

        foreach ($parts as $index => $part) {
            if (str_contains($part, ',')) {
                [$key, $value] = explode(',', $part, 2);
                $data[trim($key)] = trim($value);
            } else {
                if ($index === 0) {
                    $data['COMMAND'] = $part;
                } else {
                    $data['STATUS'] = $part;
                }
            }
        }

        \Log::debug("Parsed CAI Data", ['parsed' => $data]);

        return $data;
    }

    public function checkHealth(): bool
    {
        try {
            $this->login();
            $this->forceLogout();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getMappingBlueprint(string $rawSample): array
    {
        $blueprint = [];

        if (empty($rawSample)) {
            return $blueprint;
        }

        $parsed = $this->parseSamplePayload($rawSample);
        $params = $parsed['params'] ?? [];

        foreach ($params as $key => $sampleValue) {
            $blueprint[] = [
                'key'         => $key,
                'type'        => $this->inferMmlType($sampleValue),
                'level'       => 0,
                'isParent'    => false,
                'is_required' => true,
                'value'       => $sampleValue,
            ];
        }

        return $blueprint;
    }

    protected function inferMmlType(mixed $value): string
    {
        if (is_numeric($value)) {
            return str_contains((string)$value, '.') ? 'Double' : 'Integer';
        }

        $upperVal = strtoupper((string)$value);
        if (in_array($upperVal, ['TRUE', 'FALSE', 'YES', 'NO', 'ON', 'OFF'])) {
            return 'Boolean';
        }

        return 'String';
    }

    public function extractSystemParams(string $rawPayload): array
    {
        $detected = [];
        $keys = ['originHostName', 'originTransactionID'];

        foreach ($keys as $key) {
            if (preg_match("/{$key},([^:;,\s]+)/i", $rawPayload, $matches)) {
                $detected[$key] = trim($matches[1]);
            }
        }

        return $detected;
    }

    public function parseSamplePayload(string $rawPayload): array
    {
        $parts = explode(':', rtrim($rawPayload, ';'));
        $method = array_shift($parts);
        $params = [];

        foreach ($parts as $pair) {
            $kv = explode(',', $pair);
            if (count($kv) === 2) {
                $params[$kv[0]] = $kv[1];
            }
        }

        return ['method' => $method, 'params' => $params];
    }

    public function extractIdentifier(string $rawPayload): ?string
    {
        try {
            if (preg_match('/MSISDN,([^:;,\s]+)/i', $rawPayload, $matches)) {
                return $matches[1];
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }
}