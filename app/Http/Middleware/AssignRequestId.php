<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssignRequestId
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Check if the frontend sent an ID, otherwise generate a UUID
        $requestId = $request->header('X-Request-ID') ?? (string) Str::uuid();

        // 2. Add it to the request headers so it's accessible globally via request()
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);

        // 3. Return it in the response for frontend debugging
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
