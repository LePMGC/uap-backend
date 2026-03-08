<?php

namespace App\Modules\Core\Auditing\Services;

use App\Modules\Core\Auditing\Jobs\AsyncUapLoggerJob;
use Illuminate\Support\Facades\Auth;
use Throwable;

class UapLogger
{
    /**
     * Dispatches a logging job to the queue to prevent blocking the main process.
     */
    public static function log(
        string $module, 
        string $event, 
        string $level = 'info', 
        array $context = [], 
        ?string $status = 'SUCCESS',
        ?string $manualTraceId = null
    ) {
        // Priority: Manual ID (from Worker) > Request Header (from API) > PID (Fallback)
        $traceId = $manualTraceId ?? request()->header('X-Request-ID') ?? 'CLI-' . getmypid();

        $data = [
            'timestamp'  => now()->format('Y-m-d H:i:s.u'),
            'module'     => strtoupper($module),
            'event'      => strtoupper($event),
            'status'     => strtoupper($status),
            'user'       => Auth::user()?->username ?? 'SYSTEM',
            'trace_id'   => $traceId, 
            'client_ip'  => request()->ip(),
            'details'    => $context,
        ];

        AsyncUapLoggerJob::dispatch($data, $level);
    }

    // Update helper methods to accept the optional traceId
    public static function info($module, $event, $context = [], $traceId = null) {
        self::log($module, $event, 'info', $context, 'SUCCESS', $traceId);
    }

    public static function error($module, $event, $context = []) {
        self::log($module, $event, 'error', $context, 'ERROR');
    }
    
    public static function warning($module, $event, $context = []) {
        self::log($module, $event, 'warning', $context, 'WARNING');
    }
}