<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Models\CommandLog;
use App\Modules\Connectors\Providers\ProviderFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Exception;

class CommandExecutor
{
    /**
     * Execute a command against a provider instance.
     */
    public function execute(int $instanceId, string $commandName, array $userInput, int $userId): CommandLog
    {
        $instance = ProviderInstance::findOrFail($instanceId);
        $blueprint = $this->getBlueprint($instance->category_slug, $commandName);

        $log = CommandLog::create([
            'user_id' => $userId,
            'provider_instance_id' => $instanceId,
            'command_name' => $commandName,
            'category_slug' => $instance->category_slug,
            'started_at' => now(),
            'ip_address' => request()->ip(),
        ]);

        $startTime = microtime(true);

        try {
            $finalParams = $this->preparePayload($blueprint, $userInput, $instance);
            $log->update(['request_payload' => $finalParams]);

            $provider = ProviderFactory::make(
                $instance->connection_settings, 
                config("providers.{$instance->category_slug}")
            );

            $result = $provider->execute($commandName, $finalParams);

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
     * Scans the directory for all available command names in a category.
     */
    public function getAvailableCommandNames(string $slug): array
    {
        $directory = app_path("Modules/Connectors/Blueprints/{$slug}");

        if (!File::isDirectory($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->map(fn($file) => $file->getBasename('.php'))
            ->toArray();
    }

    /**
     * Retrieves all blueprints for a category as an associative array.
     * Useful for bulk permission checking.
     */
    public function getAllBlueprints(string $slug): array
    {
        $names = $this->getAvailableCommandNames($slug);
        $blueprints = [];

        foreach ($names as $name) {
            $blueprints[$name] = $this->getBlueprint($slug, $name);
        }

        return $blueprints;
    }

    /**
     * Resolves system placeholders and merges with user data.
     */
    protected function preparePayload(array $blueprint, array $userInput, $instance): array
    {
        $systemParams = $blueprint['system_params'] ?? [];
        
        foreach ($systemParams as $key => $value) {
            $systemParams[$key] = match ($value) {
                '{auto_gen_id}'      => now()->format('YmdHis') . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
                '{auto_gen_iso8601}' => now()->format('Ymd\TH:i:s+0000'),
                '{host_name}'        => $instance->name,
                '{origin_node_type}' => 'EXT',
                default              => $value
            };
        }

        return array_merge($systemParams, $userInput);
    }

    /**
     * Loads the specific command file from the directory structure.
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