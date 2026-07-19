<?php

namespace App\Modules\Core\Dashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HealthService
{
    /**
     * Get the real-time health status of platform infrastructure.
     */
    public function getPlatformServices(): array
    {
        $services = [
            $this->checkDatabaseHealth(),
            $this->checkRedisHealth(),
            $this->checkQueueHealth(),
            $this->checkHorizonHealth(),
        ];

        $services[] = $this->checkBatchProcessing($services);

        return $services;
    }


    public function checkDatabaseHealth(): array
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

    public function checkRedisHealth(): array
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

    public function checkQueueHealth(): array
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

    public function checkHorizonHealth(): array
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            if (count($masters) === 0) {
                return [
                    'name' => 'Laravel Horizon',
                    'status' => 'Offline',
                    'status_type' => 'danger',
                    'message' => 'No active Horizon supervisors found.'
                ];
            }

            return [
                'name' => 'Laravel Horizon',
                'status' => count($masters) . ' Supervisor(s)',
                'status_type' => 'healthy',
                'message' => null
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'Laravel Horizon',
                'status' => 'Unavailable',
                'status_type' => 'danger',
                'message' => $e->getMessage()
            ];
        }
    }


    public function checkBatchProcessing(array $services): array
    {
        $database = collect($services)->firstWhere('name', 'Database Cluster');
        $redis    = collect($services)->firstWhere('name', 'Redis Cache');
        $queue    = collect($services)->firstWhere('name', 'Queue Worker');
        $horizon  = collect($services)->firstWhere('name', 'Laravel Horizon');

        if (
            $database['status_type'] === 'danger' ||
            $redis['status_type'] === 'danger' ||
            $queue['status_type'] === 'danger' ||
            $horizon['status_type'] === 'danger'
        ) {
            return [
                'name' => 'Batch Processing',
                'status' => 'Unavailable',
                'status_type' => 'danger',
                'message' => 'Background processing is unavailable.'
            ];
        }

        if (
            $database['status_type'] === 'warning' ||
            $queue['status_type'] === 'warning'
        ) {
            return [
                'name' => 'Batch Processing',
                'status' => 'Degraded',
                'status_type' => 'warning',
                'message' => 'Jobs may experience delays.'
            ];
        }

        return [
            'name' => 'Batch Processing',
            'status' => 'Ready',
            'status_type' => 'healthy',
            'message' => null
        ];
    }

}
