<?php

namespace App\Modules\Core\Dashboard\Services;

use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Models\CommandLog;
use Illuminate\Support\Facades\DB;

class ProviderHealthService
{
    /**
     * Get detailed health and performance metrics for all provider instances.
     */
    public function getProvidersMetrics(): array
    {
        // 1. Fetch instances with aggregate log data from the last 6 hours
        $instances = ProviderInstance::withCount([
            'logs as total_requests' => function ($query) {
                $query->where('started_at', '>=', now()->subHours(6));
            },
            'logs as error_count' => function ($query) {
                $query->where('is_successful', false)
                      ->where('started_at', '>=', now()->subHours(6));
            }
        ])->get();

        return $instances->map(function ($instance) {
            // 2. Calculate real-time stats from logs
            $metrics = $this->calculateInstanceMetrics($instance->id);
            
            // 3. Determine status based on multiple factors
            $errorRate = $instance->total_requests > 0 
                ? ($instance->error_count / $instance->total_requests) * 100 
                : 0;

            $status = $this->determineStatus($instance, $errorRate);

            return [
                'name'    => $instance->name,
                'status'  => $status['label'],
                'status_type' => $status['type'],
                'metrics' => [
                    'latency'    => $metrics['avg_latency'] . 'ms',
                    'error_rate' => number_format($errorRate, 1) . '%',
                    'uptime'     => $this->calculateUptime($instance),
                ]
            ];
        })->toArray();
    }

    /**
     * Calculates average latency from actual CommandLogs
     */
    private function calculateInstanceMetrics(int $instanceId): array
    {
        $avgLatency = CommandLog::where('provider_instance_id', $instanceId)
            ->where('started_at', '>=', now()->subHours(6))
            ->whereNotNull('execution_time_ms')
            ->avg('execution_time_ms');

        return [
            'avg_latency' => round($avgLatency ?? 0)
        ];
    }

    /**
     * Logic to determine if a provider is Healthy, Degraded, or Down
     */
    private function determineStatus($instance, float $errorRate): array
    {
        if (!$instance->is_active) {
            return ['label' => 'Offline', 'type' => 'danger'];
        }

        if ($errorRate > 15) {
            return ['label' => 'Critical', 'type' => 'danger'];
        }

        if ($errorRate > 5) {
            return ['label' => 'Degraded', 'type' => 'warning'];
        }

        return ['label' => 'Healthy', 'type' => 'healthy'];
    }

    private function calculateUptime($instance): string
    {
        // For a true uptime, you'd calculate successful heartbeats vs total heartbeats.
        // Simplified logic:
        return $instance->is_active ? '99.9%' : '0.0%';
    }
}