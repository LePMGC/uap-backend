<?php

namespace App\Modules\Connectors\Providers;

use Exception;

class CaiProvider extends BaseProvider
{
    protected bool $isStateful = true;
    protected array $statusRegistry;
    private $connection;

    public function __construct(array $config, array $blueprint)
    {
        parent::__construct($config, $blueprint);
        // Load the CAI specific codes
        $this->statusRegistry = require __DIR__ . '/../Config/cai_codes.php';
    }

    protected function login(): void
    {
        $this->connection = fsockopen($this->config['host'], $this->config['port'], $errno, $errstr, 10);

        if (!$this->connection) {
            throw new Exception("CAI Connection failed: $errstr ($errno)");
        }

        $loginCmd = "LOGIN:{$this->config['username']}:{$this->config['password']};";
        $response = $this->send($loginCmd);

        // We check for code 0 specifically for login
        if (!$this->isResponseSuccessful($response)) {
            throw new Exception("CAI Authentication Failed: " . $response);
        }

        $this->authenticated = true;
    }

    protected function send(string $payload): string
    {
        fwrite($this->connection, $payload . "\n");

        $buffer = "";
        // MML responses usually end with a semicolon
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
        if ($this->connection) {
            $this->send("LOGOUT;");
            fclose($this->connection);
            $this->authenticated = false;
        }
    }

    /**
     * Overrides BaseProvider to handle MML string construction
     * from the database sample payload.
     */
    protected function buildPayload(array $commandDef, array $params): string
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
        // Extract the code using Regex from "RESP:0;" or "RESP:101:MSISDN..."
        preg_match('/RESP:(\d+)/', $rawResponse, $matches);
        $code = isset($matches[1]) ? (int)$matches[1] : null;

        $isSuccessful = ($code === 0);
        $message = $this->statusRegistry['responses'][$code] ?? "Unknown CAI Code ($code)";

        // TELECOM LOGGING
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

    /**
     * Helper to verify success for internal steps (login/logout)
     */
    private function isResponseSuccessful(string $rawResponse): bool
    {
        return str_contains($rawResponse, 'RESP:0');
    }

    /**
     * Parses the CAI string into a Key-Value array
     * From: RESP:0:MSISDN,24206123:IMSI,62910...
     * To: ['MSISDN' => '24206123', 'IMSI' => '62910...']
     */
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
            // Attempt a full login sequence to verify credentials and protocol
            $this->login();
            $this->logout();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
        * Generates a mapping blueprint by parsing the MML sample payload.
        * Unlike UCIP, CAI/MML is flat, so all parameters are Level 0.
    */
    public function getMappingBlueprint(string $rawSample): array
    {
        $blueprint = [];

        if (empty($rawSample)) {
            return $blueprint;
        }

        // 1. Parse: "SET:HLRSUB:MSISDN,242064678080:MCA,0"
        $parsed = $this->parseSamplePayload($rawSample);
        $params = $parsed['params'] ?? [];

        // 2. Build flat blueprint
        foreach ($params as $key => $sampleValue) {
            $blueprint[] = [
                'key'         => $key,
                'type'        => $this->inferMmlType($sampleValue),
                'level'       => 0,
                'isParent'    => false,
                'is_required' => true,
                'value'       => $sampleValue, // This is what the user sees in the form as a guide
            ];
        }

        return $blueprint;
    }

    /**
     * Helper to guess the type from the MML string value.
     */
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
            // Regex for KEY,VALUE patterns in MML
            if (preg_match("/{$key},([^:;,\s]+)/i", $rawPayload, $matches)) {
                $detected[$key] = trim($matches[1]);
            }
        }

        return $detected;
    }

    public function parseSamplePayload(string $rawPayload): array
    {
        // Example: "SET:MSISDN,24206...:KEY,VAL;"
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
            // Matches MSISDN, followed by a comma, then captures digits/chars until the next colon, semicolon, or space
            if (preg_match('/MSISDN,([^:;,\s]+)/i', $rawPayload, $matches)) {
                return $matches[1];
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }


}
