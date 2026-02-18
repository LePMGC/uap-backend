<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Providers\ProviderFactory;
use Exception;

class MonitorProviders extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'telecom:monitor-health';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'Check connectivity for all telecom provider nodes and update status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting Health Check for all Provider Instances...");

        // 1. Fetch all instances from the database
        $instances = ProviderInstance::all();

        if ($instances->isEmpty()) {
            $this->warn("No provider instances found in the database.");
            return;
        }

        foreach ($instances as $instance) {
            $this->comment("Checking Node: {$instance->name} ({$instance->category_slug})...");

            try {
                // 2. Load the corresponding blueprint from backend config
                $blueprint = config("providers.{$instance->category_slug}");

                if (!$blueprint) {
                    $this->error("Blueprint missing for category: {$instance->category_slug}");
                    continue;
                }

                // 3. Get the driver from the Factory
                $provider = ProviderFactory::make(
                    $instance->connection_settings, 
                    $blueprint
                );

                // 4. Trigger the heartbeat logic (defined in BaseProvider)
                // This method internally calls checkHealth() and updates the DB
                $provider->heartbeat($instance->id);

                // Reload instance to see updated status
                $instance->refresh();

                if ($instance->is_active) {
                    $this->info("Result: [ONLINE] for {$instance->name}");
                } else {
                    $this->error("Result: [OFFLINE] for {$instance->name}");
                }

            } catch (Exception $e) {
                $this->error("Critical error checking {$instance->name}: " . $e->getMessage());
                
                // Force status to inactive on exception
                $instance->update([
                    'is_active' => false,
                    'last_heartbeat_at' => now()
                ]);
            }
        }

        $this->info("Health Check process completed.");
    }
}