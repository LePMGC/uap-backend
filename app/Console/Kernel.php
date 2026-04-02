<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\JobInstance;
use Illuminate\Support\Facades\Storage;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ Process Scheduled Batches (Core Engine Trigger)
        |--------------------------------------------------------------------------
        | Runs every minute and checks for due cron-based templates.
        | withoutOverlapping() prevents duplicate execution.
        */
        $schedule->command('batches:process-scheduled')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer(); // Safe for multi-server deployments


        /*
        |--------------------------------------------------------------------------
        | 2️⃣ Stale Job Recovery (Reliability Guard)
        |--------------------------------------------------------------------------
        | Cleans up jobs stuck in "running" state too long.
        */
        $schedule->command('batch:cleanup-stale')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();


        /*
        |--------------------------------------------------------------------------
        | 3 Monitor Providers Instances
        |--------------------------------------------------------------------------
        | Cleans up jobs stuck in "running" state too long.
        */
        $schedule->command('telecom:monitor-health')
            ->everyMinute(5)
            ->withoutOverlapping()
            ->onOneServer();


        /*
        |--------------------------------------------------------------------------
        | 4 Storage Cleanup (Resource Management)
        |--------------------------------------------------------------------------
        | Deletes job result directories older than configured retention.
        */
        $schedule->call(function () {

            $days = config('connectors.batch.retention_days', 30);
            $expiration = now()->subDays($days);

            JobInstance::whereNotNull('completed_at')
                ->where('completed_at', '<', $expiration)
                ->chunkById(100, function ($jobs) {
                    foreach ($jobs as $job) {
                        Storage::deleteDirectory("jobs/{$job->id}");
                    }
                });

        })
        ->daily()
        ->name('batch:cleanup-old-files')
        ->withoutOverlapping()
        ->onOneServer();
    }
}
