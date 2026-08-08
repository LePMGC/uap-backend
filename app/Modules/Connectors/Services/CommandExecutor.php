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
        string $mode = 'form'
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
        $requestData = is_array($userInput) ? $userInput : ['mode' => 'raw'];
        $requestRaw = is_string($userInput) ? $userInput : null;

        try {
            $bluePrintService = new BlueprintService();
            $bluePrint = $bluePrintService->getCategoryBlueprint($instance->category_slug);
            
            // Resolve dynamic operator identity
            $operatorId = $this->resolveOperatorIdentifier($userId, $jobInstanceId);

            // Inject it cleanly into connection settings array temporarily for this provider's lifecycle
            $connectionSettings = $instance->connection_settings;
            $connectionSettings['dynamic_operator_id'] = $operatorId;

            $provider = ProviderFactory::make($connectionSettings, $bluePrint);

            if ($mode === 'raw' && is_string($userInput)) {
                $injectedRaw = $provider->injectSystemParams($userInput);
                $requestRaw = $injectedRaw;
                $result = $provider->executeRaw($command->command_key, $injectedRaw);

                $requestData = ['mode' => 'raw'];
                $requestRaw = $result['request_raw'] ?? $requestRaw;
                $response = $result['response'];
            } else {
                $requestData = is_array($userInput) ? $userInput : [];

                // Pre-compile the raw protocol string so it is captured even if network execution fails
                try {
                    $compiled = $this->preparePayload($command, $requestData, $instance);
                    $requestRaw = is_string($compiled) ? $compiled : json_encode($compiled);
                } catch (\Throwable $e) {
                    $requestRaw = null;
                }

                // Pass the userInput to the provider to execute
                $result = $provider->execute($command->command_key, $requestData);

                $requestRaw = $result['request_raw'] ?? $requestRaw;
                $response = $result['response'];
            }

            $executionTime = round((microtime(true) - $startTime) * 1000);

            $log->update([
                'request_payload' => [
                    'data' => $requestData,
                    'raw'  => $requestRaw ?? '',
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
                    'data' => $requestData,
                    'raw'  => $requestRaw ?? '',
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
        if (is_string($userInput)) {
            return $userInput;
        }

        $systemParams = $this->resolveSystemParams($command->system_params ?? [], $instance);
        $mergedData = array_merge($systemParams, $userInput);

        $template = $command->request_payload ?? $command->sample_payload ?? null;

        if ($template) {
            return $this->compileTemplate($template, $mergedData);
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

    protected function resolveOperatorIdentifier(?int $userId, ?string $jobInstanceId): string
    {
        if ($userId && $userId > 0) {
            $user = \App\Modules\Core\UserManagement\Models\User::find($userId);
            if ($user && !empty($user->username)) {
                return strtoupper($user->username);
            }
        }

        if ($jobInstanceId) {
            $instance = \App\Models\BatchJobInstance::where('identifier_id', $jobInstanceId)->first();
            if ($instance && $instance->batchJob && $instance->batchJob->user) {
                return strtoupper($instance->batchJob->user->username);
            }
        }

        return 'UAP_SYSTEM';
    }
}