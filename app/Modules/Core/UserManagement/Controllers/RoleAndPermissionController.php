<?php

namespace App\Modules\Core\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\UserManagement\Services\RoleAndPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\Middleware;

class RoleAndPermissionController extends Controller
{
    protected $service;

    public function __construct(RoleAndPermissionService $service)
    {
        $this->service = $service;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:view_roles', only: ['index', 'listPermissions']),
            new Middleware('permission:create_roles', only: ['store']),
            new Middleware('permission:assign_permissions', only: ['updatePermissions']),
            new Middleware('permission:delete_roles', only: ['destroy']),
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json($this->service->getAllRoles());
    }

    public function listPermissions(): JsonResponse
    {
        return response()->json($this->service->getAllPermissions());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|unique:roles,name']);
        $role = $this->service->createRole($request->name, $request->permissions ?? []);
        return response()->json(['message' => 'Role created', 'data' => $role], 201);
    }

    public function updatePermissions(Request $request, $id): JsonResponse
    {
        $request->validate(['permissions' => 'required|array']);
        $role = Role::findOrFail($id);
        
        // LOG: Permission Escalation/Change
        \App\Modules\Connectors\Services\UapLogger::info('Security', 'ROLE_PERMISSIONS_UPDATED', [
            'admin_id' => auth()->id(),
            'role_name' => $role->name,
            'new_permissions' => $request->permissions
        ], 'CRITICAL');

        $role = $this->service->updateRolePermissions($id, $request->permissions);
        return response()->json(['message' => 'Permissions updated', 'data' => $role]);
    }

    public function destroy($id): JsonResponse
    {
        try {
            // 1. Fetch the role first to check its protected status
            $role = \Spatie\Permission\Models\Role::findOrFail($id);

            // 2. Prevent deletion of system-critical roles
            $protectedRoles = ['admin', 'operator'];
            
            if (in_array(strtolower($role->name), $protectedRoles)) {
                return response()->json([
                    'error' => "The '{$role->name}' role is a system default and cannot be removed."
                ], 403);
            }

            // 3. If safe, proceed to service for deletion
            $this->service->deleteRole($id);

            return response()->json([
                'message' => "Role '{$role->name}' has been successfully deleted."
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Role not found.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}