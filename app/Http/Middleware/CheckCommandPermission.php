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
        $user = auth()->user();

        // 1. Structural session sanity check
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 2. Extract and validate target command payload reference
        $commandId = $request->input('command_id');

        if (!$commandId) {
            return response()->json(['message' => 'Command ID is required.'], 400);
        }

        // 3. Hydrate the command blueprint matrix from database
        $command = Command::find($commandId);

        if (!$command) {
            return response()->json(['message' => 'Target command metadata blueprint not found.'], 404);
        }

        // 4. STEP 1: Global single command execution envelope check
        // User must possess at least one of these high-level execution wrappers
        if (!$user->can('execute_all_commands') && !$user->can('execute_commands')) {
            return response()->json([
                'message' => 'You do not hold execution rights to run terminal commands on this platform.'
            ], 403);
        }

        // 5. STEP 2: Custom blueprint isolation guard (Data multi-tenancy rule)
        // If it's a custom saved template and they can't manage everything, verify they own the record
        if ($command->is_custom && !$user->can('execute_all_commands') && $command->created_by !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access: You are not permitted to run custom templates authored by another engineer.'
            ], 403);
        }

        // 6. STEP 3: Dynamic category protocol action enforcement
        // Builds explicit string validation matching your seeders (e.g., "ericsson-ucip.run")
        $protocolPermission = "{$command->category_slug}.{$command->action}";

        if (!$user->can($protocolPermission)) {
            return response()->json([
                'message' => "Access Denied: Your profile lacks explicit action rights [{$command->action}] on the protocol schema [{$command->category_slug}]."
            ], 403);
        }

        return $next($request);
    }
}
