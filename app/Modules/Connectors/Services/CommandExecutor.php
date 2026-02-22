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
        ?string $jobInstanceId = null
    ): CommandLog {
        $instance = ProviderInstance::findOrFail($instanceId);
        $blueprint = $this->getBlueprint($instance->category_slug, $commandName);

        // Create the log entry immediately to track the "Started" state
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
            // 1. Prepare Payload: Merge system defaults with user-mapped data
            $finalParams = $this->preparePayload($blueprint, $userInput, $instance);

            // Log the prepared request
            UapLogger::info('ProviderInterface', 'API_REQUEST_PREPARED', [
                'job_instance_id' => $jobInstanceId,
                'command' => $commandName,
                'provider' => $instance->name,
                'msisdn' => $finalParams['msisdn'] ?? $finalParams['subscriberNumber'] ?? $finalParams['MSISDN'] ??  'N/A'
            ]);
            
            // 2. Validate: Ensure all required parameters from the blueprint are present
            $this->validateRequiredParams($blueprint, $finalParams);

            $log->update(['request_payload' => $finalParams]);

            // 3. Provider Factory: Create the specific driver (e.g., Rest, Soap, etc.)
            $provider = ProviderFactory::make(
                $instance->connection_settings, 
                config("providers.{$instance->category_slug}")
            );

            $result = $provider->execute($commandName, $finalParams);

            // Log the response outcome
            UapLogger::log('ProviderInterface', 'API_RESPONSE_RECEIVED', 
                $result['success'] ? 'info' : 'error', [
                'job_instance_id' => $jobInstanceId,
                'status_code' => $result['code'],
                'success' => $result['success']
            ], $result['success'] ? 'SUCCESS' : 'FAILURE');

            // 4. Update Log with standardized response data
            $log->update([
                'response_payload'  => $result['data'] ?? [], 
                'raw_response'      => $result['raw'] ?? null,
                'is_successful'     => $result['success'] ?? false,
                'response_code'     => $result['code'] ?? null,
                'ended_at'          => now(),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
            ]);

            return $log;
        } catch (\Exception $e) {
            $log->update([
                'is_successful' => false,
                'response_payload' => ['error' => $e->getMessage()],
                'ended_at' => now(),
            ]);
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