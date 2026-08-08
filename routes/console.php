<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use App\Models\JobInstance;

/*
|--------------------------------------------------------------------------
| Console Commands
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// 1. Process Scheduled Batches (Core Engine Trigger)
Schedule::command('batches:process-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// 2. Stale Job Recovery (Reliability Guard)
Schedule::command('batch:cleanup-stale')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// 3. Monitor Provider Instances
Schedule::command('telecom:monitor-health')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/telecom_monitor.log'))
    ->runInBackground();

// 4. File Retention Cleanup (Unused Uploads & Orphaned Storage)
Schedule::command('uploads:cleanup --hours=24')
    ->dailyAt('02:00')
    ->onOneServer()
    ->runInBackground();

// 5. Old Job File Retention Cleanup
Schedule::call(function () {
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