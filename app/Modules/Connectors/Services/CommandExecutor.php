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
        int $commandId,
        array|string $userInput, // This is the 'payload' from request
        int $userId,
        ?string $jobInstanceId = null,
        ?string $traceId = null,
        string $mode = 'form' // Add mode parameter
    ): CommandLog {
        $instance = ProviderInstance::findOrFail($instanceId);
        $command = Command::findOrFail($commandId);

        if ($command->category_slug !== $instance->category_slug) {
            throw new Exception("Command ID [{$commandId}] does not belong to category [{$instance->category_slug}].");
        }

        $log = CommandLog::create([
            'user_id' => $userId,
            'provider_instance_id' => $instanceId,
            'command_id' => $command->id,
            'command_name' => $command->command_key,
            'category_slug' => $instance->category_slug,
            'started_at' => now(),
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'job_instance_id' => $jobInstanceId,
        ]);

        $startTime = microtime(true);

        try {
            $bluePrintService = new BlueprintService();
            $bluePrint = $bluePrintService->getCategoryBlueprint($instance->category_slug);
            $provider = ProviderFactory::make($instance->connection_settings, $bluePrint);

            if ($mode === 'raw' && is_string($userInput)) {
                $injectedRaw = $provider->injectSystemParams($userInput);
                $result = $provider->executeRaw($command->command_key, $injectedRaw);

                $requestData = ['mode' => 'raw']; // No structured data in raw mode
                $requestRaw = $result['request_raw'];
                $response = $result['response'];
            } else {
                $payloadData = $this->preparePayload($command, $userInput, $instance);
                $result = $provider->execute($command->command_key, is_array($userInput) ? $userInput : []);

                $requestData = $payloadData;
                $requestRaw = $result['request_raw'];
                $response = $result['response'];
            }

            $executionTime = round((microtime(true) - $startTime) * 1000);

            $log->update([
                'request_payload' => [
                    'data' => $requestData,
                    'raw'  => $requestRaw,
                ],
                'response_payload' => $response,
                'is_successful'    => $response['success'] ?? false,
                'execution_time_ms' => $executionTime,
                'ended_at'         => now(),
                'response_code'     => $response['code'] ?? null,
            ]);

            return $log;

        } catch (\Exception $e) {
            $log->update([
                'is_successful' => false,
                'request_payload' => [
                    'data' => is_array($userInput) ? $userInput : ['mode' => 'raw'],
                    'raw'  => is_string($userInput) ? $userInput : null,
                ],
                'response_payload' => [
                    'success' => false,
                    'code' => 503,
                    'message' => "Execution Error",
                    'data' => [$e->getMessage()],
                    'raw' => "SYSTEM_ERROR: " . $e->getMessage()
                ],
                'status' => 'failed',
                'ended_at' => now(),
                'response_code' => 503,
            ]);
            return $log;
        }
    }


    protected function preparePayload(Command $command, array|string $userInput, $instance): string|array
    {
        // If it's already a raw string, return it
        if (is_string($userInput)) {
            return $userInput;
        }

        // Resolve system params (host_name, timestamp, etc.)
        $systemParams = $this->resolveSystemParams($command->system_params ?? [], $instance);

        // Merged data contains both System Params and the Dynamic/Static data from the Batch Job
        $mergedData = array_merge($systemParams, $userInput);

        // If the command has a request_payload template (e.g., XML/JSON with {{vars}})
        if ($command->request_payload) {
            return $this->compileTemplate($command->request_payload, $mergedData);
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
                '{host_name}' => 'UAP',
                '{auto_gen_id}' => uniqid(),
                '{auto_gen_iso8601}' => now()->toIso8601String(),
                default => $value
            };
        }
        return $resolved;
    }
}
