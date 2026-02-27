<?php

namespace App\Modules\Core\Dashboard\Services;

use App\Modules\Connectors\Models\CommandLog;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardStatsService
{
    /**
     * Get aggregate statistics for the dashboard KPI cards.
     */
    public function getGlobalStats(): array
    {
        $yesterday = Carbon::now()->subDay();
        $dayBefore = Carbon::now()->subDays(2);

        // 1. SUCCESS (24H)
        $currentSuccess = CommandLog::where('is_successful', true)
            ->where('started_at', '>=', $yesterday)->count();
        $prevSuccess = CommandLog::where('is_successful', true)
            ->where('started_at', '>=', $dayBefore)
            ->where('started_at', '<', $yesterday)->count();

        // 2. FAILED (24H)
        $currentFailed = CommandLog::where('is_successful', false)
            ->where('started_at', '>=', $yesterday)->count();
        $prevFailed = CommandLog::where('is_successful', false)
            ->where('started_at', '>=', $dayBefore)
            ->where('started_at', '<', $yesterday)->count();

        // 3. RECURRING JOBS (Active Scheduled Templates)
        $recurringCount = DB::table('job_templates')
            ->where('is_scheduled', true)
            ->where('schedule_active', true)
            ->whereNull('deleted_at')
            ->count();

        // 4. SCHEDULED (Pending Job Instances)
        $scheduledCount = DB::table('job_instances')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();

        // 5. SUCCESS RATE (%)
        $totalCurrent = $currentSuccess + $currentFailed;
        $successRate = $totalCurrent > 0 ? ($currentSuccess / $totalCurrent) * 100 : 0;
        
        $totalPrev = $prevSuccess + $prevFailed;
        $prevSuccessRate = $totalPrev > 0 ? ($prevSuccess / $totalPrev) * 100 : 0;

        // 6. AVG TIME (ms)
        $avgTime = CommandLog::where('started_at', '>=', $yesterday)
            ->whereNotNull('execution_time_ms')
            ->avg('execution_time_ms') ?? 0;

        return [
            [
                'label' => 'SUCCESS (24H)',
                'value' => number_format($currentSuccess),
                'change' => $this->calculateChange($currentSuccess, $prevSuccess),
                'trend' => $currentSuccess >= $prevSuccess ? 'up' : 'down',
                'sub' => 'successful requests'
            ],
            [
                'label' => 'FAILED (24H)',
                'value' => number_format($currentFailed),
                'change' => $this->calculateChange($currentFailed, $prevFailed),
                'trend' => $currentFailed <= $prevFailed ? 'up' : 'down', // Down is better for failure
                'sub' => 'error responses'
            ],
            [
                'label' => 'RECURRING JOBS',
                'value' => number_format($recurringCount),
                'change' => 'Active',
                'trend' => 'neutral',
                'sub' => 'Configured schedules'
            ],
            [
                'label' => 'SCHEDULED',
                'value' => number_format($scheduledCount),
                'change' => 'Pending',
                'trend' => 'neutral',
                'sub' => 'In execution queue'
            ],
            [
                'label' => 'SUCCESS RATE',
                'value' => number_format($successRate, 1) . '%',
                'change' => number_format($successRate - $prevSuccessRate, 1) . '%',
                'trend' => $successRate >= $prevSuccessRate ? 'up' : 'down',
                'sub' => $successRate > 95 ? 'Healthy' : 'Check Latency'
            ],
            [
                'label' => 'AVG TIME',
                'value' => round($avgTime) . 'ms',
                'change' => 'Latent',
                'trend' => $avgTime < 500 ? 'up' : 'down',
                'sub' => 'Avg response time'
            ]
        ];
    }

    /**
     * Calculate percentage change between two periods.
     */
    private function calculateChange($current, $prev): string
    {
        if ($prev == 0) return $current > 0 ? '+100%' : '0%';
        $diff = (($current - $prev) / $prev) * 100;
        return ($diff >= 0 ? '+' : '') . round($diff) . '%';
    }
}