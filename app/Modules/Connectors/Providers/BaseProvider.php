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

            return $this->parseResponse($commandDef, $rawResponse, $userParams);
        } finally {
            // Optional: Close session after single command if necessary
            if ($this->isStateful && $this->authenticated) {
                $this->logout();
            }
        }
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
            if (!$host) throw new \Exception("Configuration Error: Missing Host IP");

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

    abstract public function checkHealth(): bool;
}