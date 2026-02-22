<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Models\CommandLog;
use App\Modules\Connectors\Providers\ProviderFactory;
use Illuminate\Support\Facades\File;
use Exception;

class CommandExecutor
{
    /**
     * Execute a command against a provider instance.
     */
    public function execute(
        int $instanceId, 
        string $commandName, 
        array $userInput, 
        int $userId, 
        ?string $jobInstanceId = null,
        ?string $traceId = null
    ): CommandLog {
         
        $instance = ProviderInstance::findOrFail($instanceId);
        
        // 1. Get the blueprint array
        $blueprint = $this->getBlueprint($instance->category_slug, $commandName);

        // Create the log entry immediately
        $log = CommandLog::create([
            'user_id' => $userId,
            'provider_instance_id' => $instanceId,
            'job_instance_id' => $jobInstanceId, 
            'command_name' => $commandName,
            'category_slug' => $instance->category_slug,
            'started_at' => now(),
            'ip_address' => request()->ip() ?? '127.0.0.1',
        ]);

        $startTime = microtime(true);

        try {
            // 2. Prepare Payload
            $finalParams = $this->preparePayload($blueprint, $userInput, $instance);

            // 3. Log the prepared request with Trace ID
            UapLogger::info('ProviderInterface', 'API_REQUEST_PREPARED', [
                'job_instance_id' => $jobInstanceId,
                'command' => $commandName,
                'provider' => $instance->name,
                'msisdn' => $userInput['msisdn'] ?? 'N/A'
            ], $traceId);

            // 4. Provider Factory: Create the specific driver (e.g., Rest, Soap, etc.)
            $provider = ProviderFactory::make(
                $instance->connection_settings, 
                config("providers.{$instance->category_slug}")
            );
            
            // 5. Execute
            $response = $provider->execute($commandName, $finalParams);

            $duration = round((microtime(true) - $startTime) * 1000);

            // 6. Update Log
            $log->update([
                'response_payload'  => $response['data'] ?? [], 
                'raw_response'      => $response['raw'] ?? null,
                'is_successful'     => $response['success'] ?? false,
                'response_code'     => $response['code'] ?? null,
                'ended_at'          => now(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            UapLogger::info('ProviderInterface', 'API_RESPONSE_RECEIVED', [
                'job_instance_id' => $jobInstanceId,
                'status_code' => $response['code'] ?? null,
                'success' => $response['success'] ?? false
            ], $traceId);

            return $log;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            
            $log->update([
                'response_message' => "System Error: " . $e->getMessage(),
                'is_successful' => false,
                'completed_at' => now(),
                'duration_ms' => $duration,
            ]);

            UapLogger::error('ProviderInterface', 'EXECUTION_FAILED', [
                'job_instance_id' => $jobInstanceId,
                'error' => $e->getMessage()
            ], $traceId);

            throw $e;
        }
    }

        /**
     * Refined Payload Preparation
     * Ensures system params act as defaults, but user input (from mapping) takes priority.
     */
    protected function preparePayload(array $blueprint, array $userInput, $instance): array
    {
        $systemParams = $blueprint['system_params'] ?? [];
        
        foreach ($systemParams as $key => $value) {
            $systemParams[$key] = match ($value) {
                '{auto_gen_id}'      => now()->format('YmdHis') . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
                '{auto_gen_iso8601}' => now()->format('Ymd\\TH:i:s+0000'),
                '{host_name}'        => $instance->name,
                '{origin_node_type}' => 'EXT',
                default              => $value
            };
        }

        // Priority: User Input (mapped columns) > System Params (defaults)
        return array_merge($systemParams, array_filter($userInput, fn($v) => !is_null($v)));
    }

    /**
     * Defensive Check: Ensure the batch job doesn't send incomplete requests
     */
    protected function validateRequiredParams(array $blueprint, array $payload): void
    {
        $required = $blueprint['required_params'] ?? [];
        $missing = [];

        foreach ($required as $param) {
            if (!isset($payload[$param]) || $payload[$param] === '') {
                $missing[] = $param;
            }
        }

        if (!empty($missing)) {
            throw new Exception("Missing required blueprint parameters: " . implode(', ', $missing));
        }
    }

    /**
     * Original helper to load blueprints from the directory
     */
    public function getBlueprint(string $slug, string $command): array
    {
        $path = app_path("Modules/Connectors/Blueprints/{$slug}/{$command}.php");

        if (!file_exists($path)) {
            throw new Exception("Blueprint for command [{$command}] not found in category [{$slug}].");
        }

        return include $path;
    }
}