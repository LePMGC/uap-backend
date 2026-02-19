<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Connectors\Models\JobInstance;

class CleanupStaleJobs extends Command
{
    protected $signature = 'batch:cleanup-stale';
    protected $description = 'Recover from hung states by failing jobs stuck in processing';

    public function handle()
    {
        // RELIABILITY: Fail jobs stuck in non-terminal states for > 6 hours
        $affected = JobInstance::whereIn('status', ['loading_data', 'dispatching', 'processing', 'finalizing'])
            ->where('updated_at', '<', now()->subHours(6))
            ->update(['status' => 'failed']);

        $this->info("Cleaned up {$affected} stale batch jobs.");
    }
}