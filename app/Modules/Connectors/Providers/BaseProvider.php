<?php

namespace App\Modules\Connectors\Providers;

abstract class BaseProvider
{
    protected array $config;
    protected array $blueprint;
    protected bool $isStateful = false;
    protected bool $authenticated = false;

    public function __construct(array $config, array $blueprint)
    {
        $this->config = $config;
        $this->blueprint = $blueprint;
    }

    public function execute(string $commandName, array $userParams): array
    {
        $commandDef = $this->blueprint['commands'][$commandName]
            ?? throw new \Exception("Command {$commandName} not found.");

        try {
            // Handle Login for stateful protocols like CAI/EDA
            if ($this->isStateful && !$this->authenticated) {
                $this->login();
            }

            $payload = $this->buildPayload($commandDef, $userParams);
            $rawResponse = $this->send($payload);

            return [
                'request_raw' => $payload, // Capture the string sent
                'response' => $this->parseResponse($commandDef, $rawResponse, $userParams)
            ];
        } finally {
            // Optional: Close session after single command if necessary
            if ($this->isStateful && $this->authenticated) {
                $this->logout();
            }
        }
    }

    /**
     * Handles execution when the user provides the full raw string.
     */
    public function executeRaw(string $commandName, string $rawPayload): array
    {
        $commandDef = $this->blueprint['commands'][$commandName]
            ?? throw new \Exception("Command {$commandName} not found.");

        try {
            if ($this->isStateful && !$this->authenticated) {
                $this->login();
            }

            $rawResponse = $this->send($rawPayload);

            return [
                'request_raw' => $rawPayload, // The injected raw string
                'response' => $this->parseResponse($commandDef, $rawResponse, [])
            ];
        } finally {
            if ($this->isStateful && $this->authenticated) {
                $this->logout();
            }
        }
    }

    /**
     * Replaces placeholders in raw text with live instance/system values.
     */
    public function injectSystemParams(string $rawPayload): string
    {
        $systemParams = [
            '{host_name}'          => $this->config['host'] ?? '',
            '{auto_gen_id}'        => $this->generateTransactionId(), // Now safe to call
            '{auto_gen_iso8601}'   => now()->format('Ymd\TH:i:sO'),
            '{origin_node_type}'   => $this->config['origin_node_type'] ?? 'EXT',
        ];

        foreach ($systemParams as $placeholder => $value) {
            // If generateTransactionId returns null, we don't want to replace the string with nothing
            // unless the user specifically put the placeholder there.
            $rawPayload = str_replace($placeholder, (string)($value ?? $placeholder), $rawPayload);
        }

        return $rawPayload;
    }

    /**
     * Default implementation returns null.
     * Child classes (like UCIP) should override this.
     */
    protected function generateTransactionId(): ?string
    {
        return null;
    }

    abstract protected function login(): void;
    abstract protected function logout(): void;
    abstract protected function buildPayload(array $commandDef, array $params): string;
    abstract protected function send(string $payload): string;
    abstract protected function parseResponse(array $commandDef, string $rawResponse, array $userParams): array;

    /**
     * The Pre-flight check sequence:
     * 1. Ping (ICMP)
     * 2. Port Check (TCP)
     * 3. Protocol Check (Application)
     */
    /**
     * Layered Heartbeat Logic
     */
    public function heartbeat(int $instanceId): void
    {
        $status = false;
        $errorMessage = null;
        $host = $this->config['host'] ?? null;
        $port = $this->config['port'] ?? null;

        try {
            if (!$host) {
                throw new \Exception("Configuration Error: Missing Host IP");
            }

            // Level 1: Ping (ICMP)
            if (!$this->ping($host)) {
                throw new \Exception("Network Level: Host Unreachable (Ping Failed)");
            }

            // Level 2: Telnet/Port Check (TCP)
            if ($port && !$this->isPortOpen($host, $port)) {
                throw new \Exception("Transport Level: Port $port Refused (Service Down)");
            }

            // Level 3: Protocol/Application Check
            if (!$this->checkHealth()) {
                throw new \Exception("Application Level: Protocol Handshake Failed");
            }

            $status = true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $status = false;
        }

        // Update the Database with the specific error message
        \App\Modules\Connectors\Models\ProviderInstance::where('id', $instanceId)
            ->update([
                'is_active' => $status,
                'last_error_message' => $errorMessage,
                'last_heartbeat_at' => now(),
            ]);
    }

    protected function ping(string $host): bool
    {
        // Executes a single ping with a 1-second timeout
        $command = PHP_OS_FAMILY === 'Windows'
            ? "ping -n 1 -w 1000 $host"
            : "ping -c 1 -W 1 $host";

        exec($command, $output, $result);
        return $result === 0;
    }

    protected function isPortOpen(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    /**
     * Perform a stateless connectivity check without database side-effects.
     * Useful for testing settings before saving a provider instance.
     */
    public function checkConnectivity(): bool
    {
        $host = $this->config['host'] ?? null;
        $port = $this->config['port'] ?? null;
        $instanceId = $this->config['instance_id'] ?? null;

        $startTime = microtime(true);
        $isAlive = false;

        try {
            if (!$host) {
                throw new \Exception("Host configuration missing.");
            }

            // Level 1-3 Checks
            if ($this->ping($host) &&
                (!$port || $this->isPortOpen($host, $port)) &&
                $this->checkHealth()) {
                $isAlive = true;
            }
        } catch (\Exception $e) {
            $isAlive = false;
        }

        $latencyMs = round((microtime(true) - $startTime) * 1000);

        // Update the DB if instanceId is provided
        if ($instanceId) {
            $this->persistMetrics($instanceId, $isAlive, $latencyMs);
        }

        return $isAlive;
    }

    /**
 * Internal method to handle the Rolling Health Score logic.
 */
    protected function persistMetrics(int $id, bool $success, int $latency): void
    {
        $instance = \App\Modules\Connectors\Models\ProviderInstance::find($id);
        if (!$instance) {
            return;
        }

        $currentScore = $instance->health_score ?? 100;

        if ($success) {
            // Recovery: +2 points for every successful heartbeat (up to 100)
            $newScore = min(100, $currentScore + 2);
        } else {
            // Penalty: -15 points for every failed heartbeat
            $newScore = max(0, $currentScore - 15);
        }

        $instance->update([
            'is_active' => $success,
            'latency_ms' => $success ? $latency : null,
            'health_score' => $newScore,
            'last_heartbeat_at' => now(),
        ]);
    }

    abstract public function checkHealth(): bool;

    abstract public function extractSystemParams(string $rawPayload): array;

    abstract public function parseSamplePayload(string $rawPayload): array;

    /**
     * Extract the primary identifier (e.g., MSISDN) from a raw request payload.
     */
    abstract public function extractIdentifier(string $rawPayload): ?string;
}
