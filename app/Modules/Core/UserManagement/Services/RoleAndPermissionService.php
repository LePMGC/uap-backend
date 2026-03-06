<?php

namespace App\Modules\Core\UserManagement\Services;

use Spatie\Permission\Models\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Collection;

class RoleAndPermissionService
{
    /**
     * Get all roles with optional filtering, pagination, and user counts.
     */
    public function getAllRoles(array $filters = []): LengthAwarePaginator
    {
        // Start query with user counts and permissions loaded
        $query = Role::query()
            ->withCount('users')
            ->with('permissions');

        // 1. Filter by Search (Role Name)
        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        // 2. Sorting
        // Usually, roles are sorted alphabetically or by newest
        $query->orderBy('name', 'asc');

        // 3. Paginate
        return $query->paginate($filters['per_page'] ?? 15);
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

    // Update role name and permissions
    public function updateRole(int $roleId, string $name, array $permissions): Role
    {
        $role = Role::findOrFail($roleId);
        
        // Get current state for "diff" logging
        $oldPermissions = $role->permissions()->pluck('name')->toArray();
        $oldName = $role->name;

        if ($role->name !== 'admin') {
            $role->name = $name;
            $role->save();
            $role->syncPermissions($permissions);
            
            // LOG: Privilege change audit
            \App\Modules\Connectors\Services\UapLogger::info('Security', 'ROLE_UPDATED', [
                'actor' => auth()->user()->username ?? 'SYSTEM',
                'old_role_name' => $oldName,
                'new_role_name' => $name,
                'added_permissions' => array_diff($permissions, $oldPermissions),
                'removed_permissions' => array_diff($oldPermissions, $permissions)
            ], 'CRITICAL');
        }

        return $role;
    }
}