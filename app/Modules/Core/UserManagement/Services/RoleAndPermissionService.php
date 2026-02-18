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
        // Only sync if the role is not the protected 'admin' role
        if ($role->name !== 'admin') {
            $role->syncPermissions($permissions);
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