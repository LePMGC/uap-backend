<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Core\UserManagement\Controllers\AuthController;
use App\Modules\Core\UserManagement\Controllers\RoleAndPermissionController;
use App\Modules\Core\UserManagement\Controllers\UserController;
use App\Modules\Connectors\Controllers\DataSourceController;
use App\Modules\Connectors\Controllers\ProviderInstanceController;
use App\Modules\Connectors\Controllers\CommandLogController;
use App\Modules\Connectors\Controllers\BatchJobController;
use App\Modules\Core\Dashboard\Controllers\DashboardController;
use App\Modules\Core\Auditing\Controllers\AuditLogController;
use App\Modules\Connectors\Controllers\CommandController;
use App\Modules\Connectors\Controllers\ProviderCategoryController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Routes
Route::post('/login', [AuthController::class, 'login'])->name('login');

// Authenticated Routes
Route::middleware('auth:api')->group(function () {

    /**
     * AUTH & PROFILE
     * These routes are accessible even if must_change_password is true
     * so the user can complete the mandatory password update.
     */
    Route::prefix('auth')->group(function () {
        Route::get('/me', function () {
            return response()->json(auth()->user());
        });
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    /**
     * SECURE FUNCTIONAL ROUTES
     * All routes inside this group are blocked if 'must_change_password' is true.
     */
    Route::middleware(['force_pwd_change'])->group(function () {

        // Management (RBAC & User Administration)
        Route::prefix('management')->group(function () {

            // Roles & Permissions
            Route::get('/roles', [RoleAndPermissionController::class, 'index']);
            Route::post('/roles', [RoleAndPermissionController::class, 'store']);
            Route::get('/roles/{id}', [RoleAndPermissionController::class, 'show']);
            Route::put('/roles/{id}', [RoleAndPermissionController::class, 'update']);
            Route::delete('/roles/{id}', [RoleAndPermissionController::class, 'destroy']);
            Route::get('/permissions', [RoleAndPermissionController::class, 'listPermissions']);
            Route::put('/roles/{id}/permissions', [RoleAndPermissionController::class, 'updatePermissions']);

            // User Management
            Route::prefix('users')->group(function () {
                Route::get('/', [UserController::class, 'index']);
                Route::post('/', [UserController::class, 'store']); // System generates temp password here
                Route::get('/{id}', [UserController::class, 'show']);
                Route::put('/{id}', [UserController::class, 'update']);
                Route::delete('/{id}', [UserController::class, 'destroy']);
                Route::patch('/{id}/block', [UserController::class, 'block']);
                Route::patch('/{id}/unblock', [UserController::class, 'unblock']);
                Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
            });

            // Provider Instances
            Route::prefix('instances')->group(function () {
                Route::get('/', [ProviderInstanceController::class, 'index']);
                Route::post('/', [ProviderInstanceController::class, 'store']);
                Route::get('/categories', [ProviderInstanceController::class, 'getCategories']);
                Route::get('/{id}', [ProviderInstanceController::class, 'show']);
                Route::put('/{id}', [ProviderInstanceController::class, 'update']);
                Route::delete('/{id}', [ProviderInstanceController::class, 'destroy']);
                Route::post('/{id}/ping', [ProviderInstanceController::class, 'manualPing']);
                Route::get('/{id}/commands', [ProviderInstanceController::class, 'getCommands']);
                Route::post('/test-connection', [ProviderInstanceController::class, 'testConnection']);
            });


            Route::prefix('commands')->group(function () {
                Route::get('/', [CommandController::class, 'index']);
                Route::get('/tree', [ProviderCategoryController::class, 'tree']);
                Route::get('/{id}', [CommandController::class, 'show']);
                Route::post('/', [CommandController::class, 'store']);
                Route::put('/{id}', [CommandController::class, 'update']);
                Route::delete('/{id}', [CommandController::class, 'destroy']);
            });
        });

        //Provider categories
        Route::prefix('provider-categories')->group(function () {
            Route::get('/', [ProviderCategoryController::class, 'index']);
            Route::get('/{slug}/blueprints', [ProviderCategoryController::class, 'blueprints']);
            Route::get('/{slug}/blueprints/{command}', [ProviderCategoryController::class, 'showBlueprint']);
        });

        // Data Sources
        Route::prefix('data-sources')->group(function () {
            Route::get('/', [DataSourceController::class, 'index']);
            Route::post('/', [DataSourceController::class, 'store']);
            Route::get('/types', [DataSourceController::class, 'getTypes']);
            Route::get('/{dataSource}', [DataSourceController::class, 'show']);
            Route::put('/{dataSource}', [DataSourceController::class, 'update']);
            Route::post('/test', [DataSourceController::class, 'testConnection']);
            Route::delete('/{dataSource}', [DataSourceController::class, 'destroy']);
        });

        // Command Execution Logs
        Route::prefix('command-logs')->group(function () {
            Route::get('/', [CommandLogController::class, 'index']);
            Route::get('/{id}', [CommandLogController::class, 'show']);
            Route::post('/', [CommandLogController::class, 'store']);
        });

        // Batch Engine
        Route::prefix('batch')->group(function () {
            Route::post('/discover-headers', [BatchJobController::class, 'discoverHeaders']);

            Route::prefix('templates')->group(function () {
                Route::get('/', [BatchJobController::class, 'indexTemplates']);
                Route::post('/', [BatchJobController::class, 'storeTemplate']);
                Route::post('/preview-mapping', [BatchJobController::class, 'previewMapping']);

                Route::prefix('{id}')->group(function () {
                    Route::post('/run', [BatchJobController::class, 'runJob']);
                    Route::delete('/', [BatchJobController::class, 'destroyTemplate']);
                    Route::post('/toggle', [BatchJobController::class, 'toggleSchedule']);
                    Route::post('/terminate', [BatchJobController::class, 'terminateSchedule']);
                });
            });

            Route::prefix('instances')->group(function () {
                Route::get('/', [BatchJobController::class, 'indexInstances']);
                Route::get('/{instanceId}/status', [BatchJobController::class, 'getInstanceStatus']);
                Route::get('/{instanceId}/download/{type}', [BatchJobController::class, 'downloadFile']);
                Route::post('/{instanceId}/cancel', [BatchJobController::class, 'cancelInstance']);
                Route::get('/{instanceId}/report', [BatchJobController::class, 'downloadReport']);
            });
        });

        Route::group(['prefix' => 'dashboard'], function () {
            Route::get('/stats', [DashboardController::class, 'getStats']);
            Route::get('/platform-health', [DashboardController::class, 'getPlatformHealth']);
            Route::get('/providers-health', [DashboardController::class, 'getProvidersHealth']);
            Route::get('/recent-activities', [DashboardController::class, 'getRecentActivities']);
        });


        Route::group(['prefix' => 'audit-logs'], function () {
            Route::get('/', [AuditLogController::class, 'index']);
            Route::get('/trace/{traceId}', [AuditLogController::class, 'showTrace']);
            Route::get('/stats/connectivity', [AuditLogController::class, 'connectivityStats']);
            Route::get('/security', [AuditLogController::class, 'securityLogs']);
            Route::get('/export', [AuditLogController::class, 'export']);
        });
    });
});
