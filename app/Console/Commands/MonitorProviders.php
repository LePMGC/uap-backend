<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Providers\ProviderFactory;
use Exception;
use App\Modules\Connectors\Services\BlueprintService;

class MonitorProviders extends Command
{
    protected $signature = 'telecom:monitor-health';
    protected $description = 'Check connectivity for all telecom provider nodes and update status/latency';

    public function handle()
    {
        $this->info("Starting Health Check for all Provider Instances...");

        $instances = ProviderInstance::all();

        if ($instances->isEmpty()) {
            $this->warn("No provider instances found in the database.");
            return;
        }

        foreach ($instances as $instance) {
            $this->comment("Checking Node: {$instance->name}...");

            try {
                // 1. Get blueprint from config
                $bluePrintService = new BlueprintService();
                $blueprint = $bluePrintService->getCategoryBlueprint($instance->category_slug);

                if (!$blueprint) {
                    $this->error("Blueprint missing for category: {$instance->category_slug}");
                    continue;
                }

                // 2. Prepare Config for the Driver
                // We inject 'instance_id' so the BaseProvider can update the DB internally
                $config = $instance->connection_settings;
                $config['instance_id'] = $instance->id;

                // 3. Get the driver from the Factory
                $provider = ProviderFactory::make($config, $blueprint);

                /** * 4. Trigger the connectivity check
                 * Note: Based on your BaseProvider.php, the method is checkConnection().
                 * This method now handles Level 1-3 checks AND updates latency_ms in the DB.
                 */
                $isOnline = $provider->checkConnectivity();

                // 5. Visual Feedback in Console
                $instance->refresh();
                if ($isOnline) {
                    $this->info("Result: [ONLINE] | Latency: {$instance->latency_ms}ms | Node: {$instance->name}");
                } else {
                    $this->error("Result: [OFFLINE] | Node: {$instance->name}");
                }

            } catch (Exception $e) {
                $this->error("Critical error checking {$instance->name}: " . $e->getMessage());

                $instance->update([
                    'is_active' => false,
                    'last_heartbeat_at' => now(),
                    'latency_ms' => null
                ]);
            }
        }

        $this->info("Health Check process completed.");
    }
}
