<?php

namespace App\Modules\Core\Auditing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auditing\Services\LogParserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    protected $parser;

    public function __construct(LogParserService $parser) {
        $this->parser = $parser;
    }

    /**
     * Set up middleware for the controller.
     */
    public static function middleware(): array
    {
        return [
            // General log viewing permissions
            new \Illuminate\Routing\Controllers\Middleware('permission:view_audit_logs', only: ['index']),
            new \Illuminate\Routing\Controllers\Middleware('permission:view_trace_timeline', only: ['showTrace']),
            new \Illuminate\Routing\Controllers\Middleware('permission:view_connectivity_stats', only: ['connectivityStats']),
            new \Illuminate\Routing\Controllers\Middleware('permission:export_audit_logs', only: ['export']),
            
            // Restricted to users who can see sensitive security events
            new \Illuminate\Routing\Controllers\Middleware('permission:view_security_logs', only: ['securityLogs']),
        ];
    }

    /**
     * 1. General Audit Feed (Paginated)
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->parser->getFilteredLogs($request->all()));
    }

    /**
     * 3. Connectivity Statistics (Paginated by Provider)
     */
    public function connectivityStats(Request $request): JsonResponse
    {
        return response()->json($this->parser->getPaginatedStats($request->all()));
    }

    /**
     * 4. Security Logs (Paginated)
     */
    public function securityLogs(Request $request): JsonResponse
    {
        $filters = $request->all();
        // Explicitly filter for security-related modules
        $logs = $this->parser->getRawLogs($filters['date'] ?? now()->format('Y-m-d'))
            ->whereIn('module', ['SECURITY', 'USER MANAGEMENT', 'SYSTEMAUDIT'])
            ->reverse()
            ->values();

        // Use the manual pagination logic
        $perPage = $request->query('per_page', 15);
        $page = $request->query('page', 1);
        
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $logs->forPage($page, $perPage)->values(),
            $logs->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginated);
    }

    /**
     * 2. Trace Timeline (Not paginated, as it's a specific sequence)
     */
    public function showTrace($traceId): JsonResponse
    {
        $logs = $this->parser->getRawLogs(now()->format('Y-m-d'))
            ->merge($this->parser->getRawLogs(now()->subDay()->format('Y-m-d')))
            ->where('trace_id', $traceId)
            ->sortBy('timestamp')
            ->values();
            
        return response()->json(['data' => $logs]);
    }


    // 5. Log Export (CSV)
    public function export(Request $request) {
        $logs = $this->parser->getFilteredLogs($request->all());
        
        return new StreamedResponse(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Timestamp', 'Module', 'Event', 'Status', 'User', 'TraceID', 'Details']);
            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log['timestamp'], $log['module'], $log['event'], 
                    $log['status'], $log['user'], $log['trace_id'], json_encode($log['details'])
                ]);
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit_log_'.now()->format('Ymd').'.csv"',
        ]);
    }
}