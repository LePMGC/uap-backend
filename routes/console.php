<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/*
|--------------------------------------------------------------------------
| Console Routes & Scheduler
|--------------------------------------------------------------------------
*/

// Schedule the Telecom Monitoring Health Check
Schedule::command('telecom:monitor-health')
    ->everyFiveMinutes()
    ->appendOutputTo(storage_path('logs/telecom_monitor.log'))
    ->runInBackground();
