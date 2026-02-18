<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Static System Permissions
        $permissions = [
            'view_roles', 'create_roles', 'edit_roles', 'delete_roles', 'assign_permissions', 'manage_users',
            'view_providers', 'create_providers', 'edit_providers', 'delete_providers', 'test_connectivity',
            'view_instances', 'create_instances', 'edit_instances', 'delete_instances', 'ping_instances', 'get_instance_commands',
            'view_datasources', 'create_datasources', 'edit_datasources', 'delete_datasources', 'test_datasources',
            'execute_commands', 'execute_all_commands', // Added execute_all_commands for super-admins
            'view_all_command_logs', 'view_own_command_logs',
        ];

        // 2. Dynamic Command Action Permissions
        // These are based on the 'action' key in your blueprints
        $categories = ['ericsson-ucip', 'ericsson-cai'];
        $actions = ['view', 'create', 'update', 'delete', 'run'];

        foreach ($categories as $cat) {
            foreach ($actions as $action) {
                $permissions[] = "{$cat}.{$action}";
            }
        }

        // Create all permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        // 3. Create Admin Role & Sync EVERYTHING
        $admin = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'api',
        ]);
        
        // Admins get all permissions including all category actions (create, update, delete, run)
        $admin->syncPermissions(Permission::where('guard_name', 'api')->get());

        // 4. Create Operator Role & Sync LIMITED permissions
        $operator = Role::firstOrCreate([
            'name' => 'operator',
            'guard_name' => 'api',
        ]);

        // Operators can execute commands generally, but only specific actions
        $operatorPermissions = [
            'execute_commands',
            'view_own_command_logs',
            'get_instance_commands',
            'ericsson-ucip.view', // Can see "Get" commands
            'ericsson-cai.view',
        ];

        $operator->syncPermissions($operatorPermissions);
    }
}