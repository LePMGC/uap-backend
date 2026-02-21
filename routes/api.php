<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Core\UserManagement\Controllers\AuthController;
use App\Modules\Core\UserManagement\Controllers\RoleAndPermissionController;
use App\Modules\Core\UserManagement\Controllers\UserController;
use App\Modules\Connectors\Controllers\DataSourceController;
use App\Modules\Connectors\Controllers\ProviderInstanceController;
use App\Modules\Connectors\Controllers\CommandLogController;
use App\Modules\Connectors\Controllers\BatchJobController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    
    // Auth & Profile
    Route::prefix('auth')->group(function () {
        Route::get('/me', function () { return response()->json(auth()->user()); });
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Management (RBAC & Provider Instances)
    Route::prefix('management')->group(function () {
        // Roles & Permissions ... (your existing routes)
        Route::get('/roles', [RoleAndPermissionController::class, 'index']);
        Route::post('/roles', [RoleAndPermissionController::class, 'store']);
        Route::delete('/roles/{id}', [RoleAndPermissionController::class, 'destroy']);
        Route::get('/permissions', [RoleAndPermissionController::class, 'listPermissions']);
        Route::put('/roles/{id}/permissions', [RoleAndPermissionController::class, 'updatePermissions']);

        // User Management ... (your existing routes)
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::patch('/users/{id}/block', [UserController::class, 'block']);
        Route::patch('/users/{id}/unblock', [UserController::class, 'unblock']);

        // Provider Instances (New)
        Route::prefix('instances')->group(function () {
            Route::get('/', [ProviderInstanceController::class, 'index']);
            Route::post('/', [ProviderInstanceController::class, 'store']);
            Route::get('/{id}', [ProviderInstanceController::class, 'show']);
            Route::put('/{id}', [ProviderInstanceController::class, 'update']);
            Route::delete('/{id}', [ProviderInstanceController::class, 'destroy']);
            Route::post('/{id}/ping', [ProviderInstanceController::class, 'manualPing']);
            Route::get('/{id}/commands', [ProviderInstanceController::class, 'getCommands']);
        });
    });

    // Data Sources
    Route::prefix('data-sources')->group(function () {
        Route::get('/', [DataSourceController::class, 'index']);
        Route::post('/', [DataSourceController::class, 'store']);
        Route::get('/{dataSource}', [DataSourceController::class, 'show']);
        Route::put('/{dataSource}', [DataSourceController::class, 'update']);
        Route::post('/test', [DataSourceController::class, 'testConnection']);
        Route::delete('/{dataSource}', [DataSourceController::class, 'destroy']);
    });

    // routes/api.php inside the management prefix
    Route::prefix('command-logs')->group(function () {
        Route::get('/', [CommandLogController::class, 'index']);
        Route::get('/{id}', [CommandLogController::class, 'show']);
        Route::post('/', [CommandLogController::class, 'store']); // This handles new & cloned runs
    });


    Route::prefix('batch')->group(function () {

        /**
         * 1. PRE-CREATION / DISCOVERY
         * Used by the FE to fetch headers from a source before saving the template.
         */
        Route::post('/discover-headers', [BatchJobController::class, 'discoverHeaders']);

        /**
         * 2. TEMPLATE MANAGEMENT
         * CRUD and Scheduling controls for the "Permanent Contracts".
         */
        Route::prefix('templates')->group(function () {
            Route::get('/', [BatchJobController::class, 'indexTemplates']); // List all templates
            Route::post('/', [BatchJobController::class, 'storeTemplate']); // Save the mapping & schedule
            
            Route::prefix('{id}')->group(function () {
                Route::get('/', [BatchJobController::class, 'showTemplate']);
                Route::delete('/', [BatchJobController::class, 'destroyTemplate']);
                
                // Manual Trigger
                Route::post('/run', [BatchJobController::class, 'runJob']);
                
                // Schedule Controls
                Route::patch('/schedule', [BatchJobController::class, 'updateSchedule']);
                Route::post('/toggle', [BatchJobController::class, 'toggleSchedule']); 
                Route::post('/terminate', [BatchJobController::class, 'terminateSchedule']);
            });
        });

        /**
         * 3. EXECUTION INSTANCES & MONITORING
         * Tracking the progress of specific runs and downloading results.
         */
        Route::prefix('instances')->group(function () {
            Route::get('/', [BatchJobController::class, 'indexInstances']); // Dashboard list
            
            Route::prefix('{instanceId}')->group(function () {
                Route::get('/status', [BatchJobController::class, 'getInstanceStatus']); 
                
                // Results: 'success' (CSV), 'failed' (CSV), or 'all' (Zip)
                Route::get('/download/{type}', [BatchJobController::class, 'downloadFile']);
                
                // Optional: Terminate an active running batch
                Route::post('/cancel', [BatchJobController::class, 'cancelInstance']);
            });
        });

    });
});