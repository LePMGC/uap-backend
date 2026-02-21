<?php

namespace App\Modules\Connectors\Commands;

use Illuminate\Console\Command;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchOrchestrator;
use Illuminate\Support\Str;

class ProcessScheduledBatches extends Command
{
    protected $signature = 'batch:process-scheduled';
    protected $description = 'Checks for scheduled job templates and triggers due instances';

    public function handle(BatchOrchestrator $orchestrator)
    {
        $now = now();

        $dueTemplates = JobTemplate::where('is_scheduled', true)
            ->where('is_active', true)
            ->where('schedule_active', true)
            ->where('next_run_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')
                      ->orWhere('starts_at', '<=', $now);
            })
            ->get();

        if ($dueTemplates->isEmpty()) {
            $this->info("No scheduled jobs are due at this time.");
            return;
        }

        foreach ($dueTemplates as $template) {
            // 1. CHECK: Has the schedule expired?
            if ($template->ends_at && $now->gt($template->ends_at)) {
                $template->update(['schedule_active' => false]);
                $this->info("Schedule for [{$template->name}] has expired and was deactivated.");
                continue;
            }

            try {
                $this->info("Triggering scheduled job: {$template->name}");

                // 2. CREATE: A new execution instance
                $instance = JobInstance::create([
                    'id' => (string) Str::uuid(),
                    'job_template_id' => $template->id,
                    'status' => 'pending',
                    'trigger_type' => 'scheduled',
                    'user_id' => $template->user_id, // Attributed to the creator
                ]);

                // 3. EXECUTE: Pass the instance to the orchestrator
                $orchestrator->execute($instance);

                // 4. CALCULATE: When should this run next?
                $cron = new \Cron\CronExpression($template->cron_expression);
                $nextRun = $cron->getNextRunDate();

                // 5. UPDATE: The template state
                if ($template->ends_at && $nextRun > $template->ends_at) {
                    $template->update(['next_run_at' => null, 'schedule_active' => false]);
                } else {
                    $template->update(['next_run_at' => $nextRun->format('Y-m-d H:i:s')]);
                }

            } catch (\Exception $e) {
                $this->error("Failed to trigger {$template->name}: " . $e->getMessage());
                // We don't want one failing job to stop the entire loop
                continue;
            }
        }
    }
}