<?php

namespace App\Http\Middleware;

use Closure;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Services\PermissionEvaluator;
use App\Modules\Connectors\Models\ProviderInstance;

class CheckCommandPermission
{
    public function handle($request, Closure $next)
    {
        $instanceId = $request->input('instance_id');
        $commandName = $request->input('command_name');

        $instance = ProviderInstance::findOrFail($instanceId);
        $executor = new CommandExecutor();
        
        // Get the blueprint to check the 'action'
        $blueprint = $executor->getBlueprint($instance->category_slug, $commandName);

        if (!PermissionEvaluator::canUserAccessCommand(auth()->user(), $instance->category_slug, $commandName, $blueprint)) {
            return response()->json([
                'success' => false,
                'message' => "Forbidden: You do not have permission to run '{$commandName}'"
            ], 403);
        }

        return $next($request);
    }
}