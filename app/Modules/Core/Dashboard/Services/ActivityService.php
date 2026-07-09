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
        $user = auth()->user();

        $isAdmin = in_array(
            strtolower($user->role ?? ''),
            ['admin', 'administrator']
        );

        $userId = $user->id;


        /*
        |--------------------------------------------------------------------------
        | 1. Batch Instances
        |--------------------------------------------------------------------------
        */
        $batches = DB::table('job_instances as ji')
            ->join('job_templates as jt', 'ji.job_template_id', '=', 'jt.id')
            ->select([
                'ji.id',
                DB::raw("'Batch' as type"),
                'jt.name as name',

                DB::raw("
                CASE 
                    WHEN jt.is_scheduled = true 
                    THEN 'Scheduled'
                    ELSE 'Manual Trigger'
                END as trigger
            "),

                'ji.status',
                'ji.total_records as rows',
                'ji.processed_records as success',
                'ji.started_at as timestamp'
            ])
            ->whereNull('ji.deleted_at');


        if (!$isAdmin) {
            // Job creator
            $batches->where('jt.user_id', $userId);
        }


        $batches = $batches
            ->orderBy('ji.started_at', 'desc')
            ->limit($limit)
            ->get();



        /*
        |--------------------------------------------------------------------------
        | 2. Single Commands
        |--------------------------------------------------------------------------
        */
        $singles = DB::table('command_logs')
            ->select([
                'id',
                DB::raw("'Single' as type"),
                'command_name as name',

                DB::raw("'Manual Trigger' as trigger"),

                DB::raw("
                CASE 
                    WHEN is_successful = true 
                    THEN 'Completed'
                    ELSE 'Failed'
                END as status
            "),

                DB::raw("1 as rows"),

                DB::raw("
                CASE 
                    WHEN is_successful = true 
                    THEN 1
                    ELSE 0
                END as success
            "),

                'started_at as timestamp'
            ])
            ->whereNull('job_instance_id')
            ->whereNull('deleted_at');


        if (!$isAdmin) {
            $singles->where('user_id', $userId);
        }


        $singles = $singles
            ->orderBy('started_at', 'desc')
            ->limit($limit)
            ->get();



        /*
        |--------------------------------------------------------------------------
        | 3. Reimbursements
        |--------------------------------------------------------------------------
        */
        $reimbursements = DB::table('reimbursements as r')
            ->select([
                'r.id',

                DB::raw("'Reimbursement' as type"),

                DB::raw("
                CONCAT(
                    'Ticket ',
                    r.ticket_id
                ) as name
            "),

                DB::raw("
                CASE
                    WHEN r.reviewed_by_user_id IS NOT NULL
                    THEN 'Reviewed'
                    ELSE 'Created'
                END as trigger
            "),

                'r.status',

                DB::raw("1 as rows"),

                DB::raw("
                CASE
                    WHEN r.status = 'approved'
                    THEN 1
                    ELSE 0
                END as success
            "),

                'r.created_at as timestamp'
            ])
            ->whereNull('r.deleted_at');


        if (!$isAdmin) {
            $reimbursements->where(function ($query) use ($userId) {

                $query
                    ->where('r.requested_by_user_id', $userId)
                    ->orWhere('r.reviewed_by_user_id', $userId);

            });
        }


        $reimbursements = $reimbursements
            ->orderBy('r.created_at', 'desc')
            ->limit($limit)
            ->get();



        /*
        |--------------------------------------------------------------------------
        | 4. Merge Everything
        |--------------------------------------------------------------------------
        */
        $combined = collect()
            ->concat($batches)
            ->concat($singles)
            ->concat($reimbursements)
            ->sortByDesc('timestamp')
            ->take($limit);



        /*
        |--------------------------------------------------------------------------
        | 5. Response Formatting
        |--------------------------------------------------------------------------
        */
        return $combined
            ->map(function ($item) {

                $dt = Carbon::parse($item->timestamp);

                return [
                    'id' => (string) $item->id,

                    'type' => $item->type,

                    'name' => $this->formatName($item->name),

                    'trigger' => $item->trigger,

                    'status' => ucfirst($item->status),

                    'status_type' => $this->mapStatusToType(
                        $item->status
                    ),

                    'rows' => number_format(
                        $item->rows ?? 0
                    ),

                    'success' => number_format(
                        $item->success ?? 0
                    ),

                    'time' => $dt->diffForHumans(),

                    'timestamp' => $dt->toIso8601String(),
                ];
            })
            ->values()
            ->toArray();
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
