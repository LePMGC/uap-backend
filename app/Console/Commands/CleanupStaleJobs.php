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
        // Fail jobs stuck in 'processing' for more than 6 hours
        $affected = JobInstance::where('status', 'processing')
            ->where('updated_at', '<', now()->subHours(6))
            ->update([
                'status' => 'failed',
                'completed_at' => now()
            ]);

        $this->info("Cleaned up {$affected} stale batch jobs.");
    }
}
