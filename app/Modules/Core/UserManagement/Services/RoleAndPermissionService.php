<?php

namespace App\Modules\Core\UserManagement\Services;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Collection;

class RoleAndPermissionService
{
    public function getAllRoles(): Collection
    {
        return Role::with('permissions')->get();
    }

    public function getAllPermissions(): Collection
    {
        return Permission::all();
    }

    public function createRole(string $name, array $permissions = []): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'api']);
        if (!empty($permissions)) {
            $role->syncPermissions($permissions);
        }
        return $role;
    }

    public function updateRolePermissions(int $roleId, array $permissions): Role
    {
        $role = Role::findOrFail($roleId);
        
        // Get current state for "diff" logging
        $oldPermissions = $role->permissions()->pluck('name')->toArray();

        if ($role->name !== 'admin') {
            $role->syncPermissions($permissions);
            
            // LOG: Privilege change audit
            \App\Modules\Connectors\Services\UapLogger::info('Security', 'ROLE_PERMISSIONS_SYNCED', [
                'actor' => auth()->user()->username ?? 'SYSTEM',
                'role' => $role->name,
                'added' => array_diff($permissions, $oldPermissions),
                'removed' => array_diff($oldPermissions, $permissions)
            ], 'CRITICAL');
        }

        return $role;
    }

    public function deleteRole(int $roleId): bool
    {
        $role = Role::findOrFail($roleId);
        if ($role->name === 'admin' || $role->name === 'operator') {
            throw new \Exception("Cannot delete default system roles.");
        }
        return $role->delete();
    }
}