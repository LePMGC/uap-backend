<?php

namespace App\Modules\Connectors\Services;

class PermissionEvaluator
{
    /**
     * Centralized logic to check if a user can access a specific command.
     */
    public static function canUserAccessCommand($user, string $category, string $commandName, array $blueprint): bool
    {
        // 1. Super Admin bypass
        if ($user->can('execute_all_commands')) {
            return true;
        }

        // 2. Specific Command Permission (e.g., ericsson-ucip.GetAccumulators)
        if ($user->can("{$category}.{$commandName}")) {
            return true;
        }

        // 3. Action-based Permission (e.g., ericsson-ucip.view)
        $action = $blueprint['action'] ?? 'run';
        $actionPermission = "{$category}.{$action}";

        return $user->can($actionPermission);
    }
}
