<?php

namespace App\Modules\Connectors\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Throwable;

class UapLogger
{
    public static function log(
        string $module, 
        string $event, 
        string $level = 'info', 
        array $context = [], 
        ?string $status = 'SUCCESS'
    ) {
        $data = [
            'timestamp'  => now()->format('Y-m-d H:i:s.u'),
            'module'     => strtoupper($module),
            'event'      => strtoupper($event),
            'status'     => strtoupper($status),
            'user'       => Auth::user()?->username ?? 'SYSTEM',
            'session_id' => request()->header('X-Request-ID') ?? (request()->hasSession() ? session()->getId() : 'N/A'),
            'client_ip'  => request()->ip(),
            'details'    => $context,
        ];

        $payload = json_encode($data);

        try {
            // Force a check if the channel exists to avoid confusing errors
            Log::channel('uap')->log($level, $payload);
        } catch (Throwable $e) {
            // If this triggers, check storage/logs/laravel.log for the reason
            Log::error("UAP_LOGGER_CRITICAL_FAILURE", [
                'reason' => $e->getMessage(),
                'path' => '/var/log/uap/',
                'original_data' => $data
            ]);
        }
    }

    public static function info($module, $event, $context = []) {
        self::log($module, $event, 'info', $context, 'SUCCESS');
    }

    public static function error($module, $event, $context = [], $status = 'FAILURE') {
        self::log($module, $event, 'error', $context, $status);
    }
}