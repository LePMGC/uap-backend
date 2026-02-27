<?php

namespace App\Modules\Core\Dashboard\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ActivityService
{
    /**
     * Get consolidated list of recent activities.
     * Batches come from job_instances joined with job_templates.
     * Singles come from command_logs where job_instance_id is null.
     */
    public function getRecentActivities(int $limit = 10): array
    {
        // 1. Fetch Recent Batch Instances with their Template Name
        $batches = DB::table('job_instances as ji')
            ->join('job_templates as jt', 'ji.job_template_id', '=', 'jt.id')
            ->select([
                'ji.id',
                DB::raw("'Batch' as type"),
                'jt.name as name',
                // If it was started by a schedule vs manual template trigger
                DB::raw("CASE WHEN jt.is_scheduled = true THEN 'Scheduled' ELSE 'Manual Trigger' END as trigger"),
                'ji.status',
                'ji.total_records as rows',
                'ji.processed_records as success',
                'ji.started_at as timestamp'
            ])
            ->whereNull('ji.deleted_at')
            ->orderBy('ji.started_at', 'desc')
            ->limit($limit)
            ->get();

        // 2. Fetch Recent Single Commands (not part of a batch)
        $singles = DB::table('command_logs')
            ->select([
                'id',
                DB::raw("'Single' as type"),
                'command_name as name',
                DB::raw("'Manual Trigger' as trigger"), // Single logs in your schema have a required user_id
                DB::raw("CASE WHEN is_successful = true THEN 'Completed' ELSE 'Failed' END as status"),
                DB::raw("1 as rows"),
                DB::raw("CASE WHEN is_successful = true THEN 1 ELSE 0 END as success"),
                'started_at as timestamp'
            ])
            ->whereNull('job_instance_id') // Crucial: only pick standalone commands
            ->whereNull('deleted_at')
            ->orderBy('started_at', 'desc')
            ->limit($limit)
            ->get();

        // 3. Merge, Sort by timestamp, and Limit
        $combined = $batches->concat($singles)
            ->sortByDesc('timestamp')
            ->take($limit);

        // 4. Transform to the requested JSON payload structure
        return $combined->map(function ($item) {
            $dt = Carbon::parse($item->timestamp);
            
            return [
                'id'          => (string) $item->id,
                'type'        => $item->type,
                'name'        => $this->formatName($item->name),
                'trigger'     => $item->trigger,
                'status'      => ucfirst($item->status),
                'status_type' => $this->mapStatusToType($item->status),
                'rows'        => number_format($item->rows),
                'success'     => number_format($item->success),
                'time'        => $dt->diffForHumans(),
                'timestamp'   => $dt->toIso8601String(),
            ];
        })->values()->toArray();
    }

    /**
     * Map database status strings to UI bootstrap-style types
     */
    private function mapStatusToType(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'success' => 'success',
            'failed', 'danger'     => 'danger',
            'pending', 'processing' => 'info',
            'partial'              => 'warning',
            default                => 'secondary',
        };
    }

    /**
     * Clean up command names (e.g. "GetBalance" -> "Get Balance")
     */
    private function formatName(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        return ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $name));
    }
}