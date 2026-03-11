<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Modules\Connectors\Models\Command;
use Symfony\Component\HttpFoundation\Response;

class CheckCommandPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Get the command_id from the request
        $commandId = $request->input('command_id');

        if (!$commandId) {
            return response()->json(['message' => 'Command ID is required'], 400);
        }

        // 2. Fetch the command from the database
        $command = Command::find($commandId);

        if (!$command) {
            return response()->json(['message' => 'Command not found'], 404);
        }

        // 3. Check if the user has permission to execute this specific command key
        // Permission pattern example: "execute_Refill" or "execute_InstallSubscriber"
        $permission = 'execute_' . $command->command_key;

        if (!auth()->user()->can($permission) && !auth()->user()->hasRole('admin')) {
            return response()->json([
                'message' => "You do not have permission to execute the command: {$command->name}"
            ], 403);
        }

        return $next($request);
    }
}