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

        // 1. Static System Permissions (Cleaned & Unique)
        $permissions = [
            // IAM / User Management
            'view_roles', 'create_roles', 'edit_roles', 'delete_roles', 'assign_permissions',
            'view_users', 'create_users', 'edit_users', 'delete_users', 'reset_user_passwords',

            // Integration Providers & Instances
            'view_providers', 'create_providers', 'edit_providers', 'delete_providers', 'test_connectivity',
            'view_instances', 'create_instances', 'edit_instances', 'delete_instances', 'ping_instances', 'get_instance_commands',

            // Database-driven Command Management (Blueprints)
            'view_all_commands',
            'view_own_commands',
            'manage_all_commands',
            'manage_own_commands',
            'view_provider_categories',
            'view_command_blueprints',

            // Datasources (External DBs for Batch Processing)
            'view_datasources', 'create_datasources', 'edit_datasources', 'delete_datasources', 'test_datasources',

            // Command Execution & Logging
            'execute_commands',
            'execute_all_commands',
            'view_all_command_logs',
            'view_own_command_logs',

            // Template Management
            'view_all_batch_templates',
            'view_own_batch_templates',
            'create_batch_templates',
            'edit_batch_templates',
            'delete_batch_templates',
            'manage_all_batch_templates',
            'manage_own_batch_templates',
            'discover_batch_headers',

            // Execution, Monitoring & Batch Lifecycle
            'run_batch_jobs',
            'view_all_batch_instances',
            'view_own_batch_instances',
            'cancel_batch_instances',
            'download_batch_results',
            'download_batch_report',

            // Scheduling (Cron/Automated Executions)
            'manage_batch_schedules',
            'manage_own_batch_schedules', // Added for operator-level task scheduling

            // Audit & Observability Logs
            'view_audit_logs',
            'view_security_logs',
            'view_trace_timeline',
            'view_connectivity_stats',
            'export_audit_logs',

            // Authentication & Access Control
            'change_own_password',

            // Reimbursements Management
            'view_all_reimbursements',
            'view_own_reimbursements',
            'view_reimbursement_stats',
            'create_single_reimbursement',
            'create_bulk_reimbursements',
            'approve_tier1_reimbursements',
            'approve_tier2_reimbursements',
            'manage_reimbursement_settings',
        ];

        // 2. Dynamic Command Action Permissions
        // Aligned with the database-driven blueprint actions
        $categories = ['ericsson-ucip', 'ericsson-cai', 'smpp'];
        $actions = ['view', 'create', 'update', 'delete', 'run', 'get', 'set']; // Expanded actions

        foreach ($categories as $cat) {
            foreach ($actions as $action) {
                $permissions[] = "{$cat}.{$action}";
            }
        }

        // Create all permissions safely
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
        $admin->syncPermissions(Permission::where('guard_name', 'api')->get());

        // 4. Create Operator Role & Sync Scoped Permissions
        $operator = Role::firstOrCreate([
            'name' => 'operator',
            'guard_name' => 'api',
        ]);

        $operatorPermissions = [
            // Core Identity Lookups
            'view_users',
            'view_roles',
            'view_provider_categories',
            'view_command_blueprints',
            'get_instance_commands',

            // Database Commands (Own Rights)
            'view_own_commands',
            'manage_own_commands',

            // Single Execution Rights
            'execute_commands',
            'view_own_command_logs',

            // Batch Jobs Ecosystem (Own Rights)
            'discover_batch_headers',
            'manage_own_batch_templates',
            'view_own_batch_templates',
            'run_batch_jobs',
            'view_own_batch_instances',
            'manage_own_batch_schedules',
            'download_batch_results',
            'download_batch_report',

            // Protocol Level Operational Rights (Crucial Addition)
            'ericsson-ucip.view',
            'ericsson-ucip.run',
            'ericsson-cai.view',
            'ericsson-cai.run',
            'ericsson-cai.get',
            'ericsson-cai.set',

            // Safe Troubleshooting Observability
            'view_audit_logs',
            'view_trace_timeline',
            'view_connectivity_stats',

            // Authentication & Access Control
            'change_own_password',

            // Reimbursements Management
            'view_own_reimbursements',
            'create_single_reimbursement',
            'create_bulk_reimbursements',
            'approve_tier1_reimbursements',
        ];

        $operator->syncPermissions($operatorPermissions);
    }
}
