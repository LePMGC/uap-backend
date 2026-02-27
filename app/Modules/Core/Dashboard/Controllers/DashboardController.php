<?php

namespace App\Modules\Core\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\CommandLog;
use App\Modules\Connectors\Models\ProviderInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Modules\Core\Dashboard\Services\HealthService;
use App\Modules\Core\Dashboard\Services\ProviderHealthService;
use App\Modules\Core\Dashboard\Services\DashboardStatsService;
use App\Modules\Core\Dashboard\Services\ActivityService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $healthService, $providerHealthService, $statsService, $activityService;

    public function __construct(
        HealthService $healthService, 
        ProviderHealthService $providerHealthService,
        DashboardStatsService $statsService,
        ActivityService $activityService
    ) {
        $this->healthService = $healthService;
        $this->providerHealthService = $providerHealthService;
        $this->statsService = $statsService;
        $this->activityService = $activityService;
    }

    public static function middleware(): array
    {
        return [
        ];
    }

    /**
     * Get aggregate statistics for the last 24 hours.
     */
    public function getStats(): JsonResponse
    {
        return response()->json([
            'stats' => $this->statsService->getGlobalStats()
        ]);
    }

    /**
     * Get the health status of platform infrastructure.
     */
    public function getPlatformHealth(): JsonResponse
    {
        return response()->json([
            'services' => $this->healthService->getPlatformServices()
        ]);
    }

    /**
     * Get specific metrics for each Provider Instance.
     */
    public function getProvidersHealth(): JsonResponse
    {
        return response()->json([
            'providers' => $this->providerHealthService->getProvidersMetrics()
        ]);
    }

    private function calculateChange($current, $prev): string
    {
        if ($prev == 0) return '+0%';
        $diff = (($current - $prev) / $prev) * 100;
        return ($diff >= 0 ? '+' : '') . round($diff) . '%';
    }

    public function getRecentActivities(Request $request): JsonResponse
    {
        // Default to 10 activities if no limit is provided
        $limit = $request->query('limit', 5);

        return response()->json([
            'activities' => $this->activityService->getRecentActivities((int)$limit)
        ]);
    }
}