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
            if ($chunk === false) break;
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

    // app/Modules/Connectors/Providers/CaiProvider.php

    protected function buildPayload(array $commandDef, array $params): string
    {
        // prefix is usually the command keyword (e.g., SET, GET)
        $parts = [$commandDef['prefix']];
        
        foreach ($params as $key => $val) {
            // CAI usually expects parameters as KEY,VALUE
            $parts[] = "{$key},{$val}";
        }
        
        return implode(':', $parts) . ';';
    }

    public function parseResponse(array $commandDef, string $rawResponse, array $userParams): array
    {
        // Extract the code using Regex from "RESP:0;" or "RESP:101:MSISDN..."
        preg_match('/RESP:(\d+)/', $rawResponse, $matches);
        $code = isset($matches[1]) ? (int)$matches[1] : null;

        $isSuccessful = ($code === 0);
        $message = $this->statusRegistry['responses'][$code] ?? "Unknown CAI Code ($code)";

        // TELECOM LOGGING
        \App\Modules\Connectors\Services\UapLogger::log(
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
        $data = [];
        $parts = explode(':', $raw);
        
        foreach ($parts as $part) {
            if (str_contains($part, ',')) {
                $keyValue = explode(',', $part);
                if (count($keyValue) >= 2) {
                    $data[$keyValue[0]] = $keyValue[1];
                }
            }
        }
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
}