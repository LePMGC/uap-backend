<?php

namespace App\Modules\Core\Dashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

class HealthService
{
    /**
     * Get the real-time health status of platform infrastructure.
     */
    public function getPlatformServices(): array
    {
        return [
            $this->checkDatabaseHealth(),
            $this->checkRedisHealth(),
            $this->checkQueueHealth(),
        ];
    }

    private function checkDatabaseHealth(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $start) * 1000);

            return [
                'name' => 'Database Cluster',
                'status' => "{$latency}ms Latency",
                'status_type' => $latency > 500 ? 'warning' : 'healthy',
                'message' => $latency > 500 ? 'High latency detected' : null
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Database Cluster',
                'status' => 'Disconnected',
                'status_type' => 'danger',
                'message' => 'Cannot connect to the primary database'
            ];
        }
    }

    private function checkRedisHealth(): array
    {
        try {
            Redis::ping();
            return [
                'name' => 'Redis Cache',
                'status' => 'Operational',
                'status_type' => 'healthy',
                'message' => null
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Redis Cache',
                'status' => 'Unavailable',
                'status_type' => 'danger',
                'message' => 'Redis service is down'
            ];
        }
    }

    private function checkQueueHealth(): array
    {
        try {
            $size = Queue::size();
            return [
                'name' => 'Queue Worker',
                'status' => $size . ' Pending Jobs',
                'status_type' => $size > 1000 ? 'warning' : 'healthy',
                'message' => $size > 1000 ? 'Queue backlog is growing' : null
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Queue Worker',
                'status' => 'Offline',
                'status_type' => 'danger',
                'message' => 'Queue driver connection failed'
            ];
        }
    }
}