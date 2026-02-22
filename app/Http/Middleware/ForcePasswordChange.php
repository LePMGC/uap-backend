<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // If user is authenticated and must change password
        if ($user && $user->must_change_password) {
            
            // Allow access ONLY to the password change route and logout
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