<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Models\CommandLog;
use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Providers\ProviderFactory;
use Exception;

class CommandExecutor
{
    /**
     * Execute a command using its Database ID.
     */
    public function execute(
        int $instanceId, 
        int $commandId,     // Changed from string $commandName
        array|string $userInput, 
        int $userId, 
        ?string $jobInstanceId = null,
        ?string $traceId = null
    ): CommandLog {
         
        $instance = ProviderInstance::findOrFail($instanceId);
        
        // 1. Fetch the Blueprint by ID
        $command = Command::findOrFail($commandId);

        // Security Check: Ensure the command category matches the instance category
        if ($command->category_slug !== $instance->category_slug) {
            throw new Exception("Command ID [{$commandId}] does not belong to category [{$instance->category_slug}].");
        }

        $log = CommandLog::create([
            'user_id' => $userId,
            'provider_instance_id' => $instanceId,
            'job_instance_id' => $jobInstanceId, 
            'command_id'           => $command->id,
            'command_name' => $command->command_key,
            'category_slug' => $instance->category_slug,
            'started_at' => now(),
            'ip_address' => request()->ip() ?? '127.0.0.1',
        ]);

        $startTime = microtime(true);

        try {
            // 2. Prepare Payload (Form vs Raw)
            $payload = $this->preparePayload($command, $userInput, $instance);

            // 3. Execute via Provider
            $provider = ProviderFactory::make($instance);
            $response = $provider->send($payload);

            $executionTime = round((microtime(true) - $startTime) * 1000);

            // 4. Update Log with results
            $log->update([
                'request_payload' => is_array($payload) ? $payload : ['raw' => $payload],
                'response_payload' => $response,
                'is_successful' => $response['success'] ?? false,
                'execution_time_ms' => $executionTime,
                'ended_at' => now(),
            ]);

            return $log;

        } catch (Exception $e) {
            $log->update([
                'raw_response' => $e->getMessage(), 
                'is_successful' => false,
                'ended_at' => now()
            ]);
            throw $e;
        }
    }

    protected function preparePayload(Command $command, array|string $userInput, $instance): string|array
    {
        // Technical User: Raw string payload (XML/JSON)
        if (is_string($userInput)) {
            return $userInput;
        }

        // Non-Technical User: Form Data
        $systemParams = $this->resolveSystemParams($command->system_params ?? [], $instance);
        $mergedData = array_merge($systemParams, $userInput);

        // If template exists, swap {{vars}}
        if ($command->payload_template) {
            return $this->compileTemplate($command->payload_template, $mergedData);
        }

        return $mergedData;
    }

    protected function compileTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                $template = str_replace("{{{$key}}}", (string)$value, $template);
            }
        }
        return $template;
    }

    protected function resolveSystemParams(array $params, $instance): array
    {
        $resolved = [];
        foreach ($params as $key => $value) {
            $resolved[$key] = match ($value) {
                '{host_name}' => $instance->host,
                '{auto_gen_id}' => uniqid(),
                '{auto_gen_iso8601}' => now()->toIso8601String(),
                default => $value
            };
        }
        return $resolved;
    }
}