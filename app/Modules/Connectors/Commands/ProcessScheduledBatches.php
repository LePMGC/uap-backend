<?php

namespace App\Modules\Connectors\Commands;

use Illuminate\Console\Command;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchOrchestrator;
use Cron\CronExpression;

class ProcessScheduledBatches extends Command
{
    protected $signature = 'batch:process-scheduled';
    protected $description = 'Checks for scheduled job templates and triggers due instances';

    public function handle(BatchOrchestrator $orchestrator)
    {
        $now = now();

        $dueTemplates = JobTemplate::where('is_scheduled', true)
            ->where('is_active', true)        // Global template status
            ->where('schedule_active', true) // Schedule-specific toggle
            ->where('next_run_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->get();

        foreach ($dueTemplates as $template) {
            // CHECK: Has the schedule expired?
            if ($template->ends_at && $now->gt($template->ends_at)) {
                $template->update(['schedule_active' => false]);
                $this->info("Schedule for {$template->name} has expired and was deactivated.");
                continue;
            }

            // ... existing execution logic ...
            $orchestrator->execute($instance);

            // Calculate next run
            $cron = new \Cron\CronExpression($template->cron_expression);
            $nextRun = $cron->getNextRunDate();

            // CHECK: If next run is past the end date, don't schedule it
            if ($template->ends_at && $nextRun > $template->ends_at) {
                $template->update(['next_run_at' => null, 'schedule_active' => false]);
            } else {
                $template->update(['next_run_at' => $nextRun->format('Y-m-d H:i:s')]);
            }
        }
    }
}