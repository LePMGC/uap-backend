<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        
        // 1. Check if the application is in Local Auth Mode
        $isLocalMode = config('auth.uap_mode', 'local') === 'local';

        // 2. Only enforce if we are in Local Mode AND the user has the flag
        if ($isLocalMode && $user && $user->must_change_password) {
            
            $allowedRoutes = [
                'api/auth/change-password',
                'api/auth/logout',
                'api/auth/me'
            ];

            if (!$request->is($allowedRoutes)) {
                return response()->json([
                    'status' => 'FORCE_PASSWORD_CHANGE',
                    'message' => 'You must change your temporary password before accessing the system.'
                ], 403);
            }
        }

        return $next($request);
    }
}