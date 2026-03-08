<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Providers\ProviderFactory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Services\PermissionEvaluator;
use App\Modules\Connectors\Models\Command;

class ProviderInstanceController extends Controller  implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            // Only allow viewing if they have 'view_providers'
            new Middleware('permission:view_providers', only: ['index', 'show']),
            // Only allow management if they have 'manage_providers' (or specific ones)
            new Middleware('permission:create_providers', only: ['store']),
            new Middleware('permission:edit_providers', only: ['update']),
            new Middleware('permission:delete_providers', only: ['destroy']),
            new Middleware('permission:test_connectivity', only: ['manualPing']),
            new Middleware('permission:view_providers', only: ['getCommands', 'getCategories']),
        ];
    }

    /**
     * Display a listing of the provider instances with optional filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProviderInstance::query();

        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('category') && !empty($request->category)) {
            $query->where('category_slug', $request->category);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json($query->latest()->paginate(15));
    }

    /**
     * Store a new provider instance with validation for connection settings.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_slug' => 'required|string',
            'connection_settings' => 'required|array',
            'connection_settings.host' => 'required|string',
            'connection_settings.port' => 'required|integer',
            'connection_settings.username' => 'required|string',
            'connection_settings.password' => 'required|string',
            'connection_settings.user_agent' => 'nullable|string|max:255', // Added User Agent
        ]);

        $instance = ProviderInstance::create($validated);

        \App\Modules\Core\Auditing\Services\UapLogger::info('SystemAudit', 'PROVIDER_INSTANCE_CREATED', [
            'user_id' => auth()->id(),
            'provider_name' => $instance->name,
            'category' => $instance->category_slug
        ]);

        return response()->json([
            'message' => 'Provider instance created successfully',
            'data' => $instance
        ], 201);
    }

    /**
     * Display the specified instance or return 404.
     */
    public function show($id): JsonResponse
    {
        $instance = ProviderInstance::find($id);

        if (!$instance) {
            return response()->json(['message' => 'Provider instance not found'], 404);
        }

        return response()->json($instance);
    }

    /**
     * Update the specified instance.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $instance = ProviderInstance::find($id);

        \App\Modules\Core\Auditing\Services\UapLogger::info('SystemAudit', 'PROVIDER_CONFIG_UPDATE_ATTEMPT', [
            'instance_id' => $id,
            'updated_fields' => array_keys($request->all())
        ]);

        if (!$instance) {
            return response()->json(['message' => 'Provider instance not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'connection_settings' => 'sometimes|array',
            'connection_settings.host' => 'sometimes|string',
            'connection_settings.port' => 'sometimes|integer',
            'connection_settings.username' => 'sometimes|string',
            'connection_settings.password' => 'sometimes|string',
            'connection_settings.user_agent' => 'nullable|string|max:255', // Added User Agent
        ]);

        $instance->update($validated);

        return response()->json([
            'message' => 'Provider instance updated successfully',
            'data' => $instance
        ], 200);
    }

    /**
     * Remove the specified instance or return 404.
     */
    public function destroy($id): JsonResponse
    {
        $instance = ProviderInstance::find($id);

        if (!$instance) {
            return response()->json(['message' => 'Provider instance not found'], 404);
        }

        $instance->delete();

        return response()->json(['message' => 'Provider instance deleted successfully'], 200);
    }

    /**
     * Manual Health Check (Ping) triggered from UI
     */
    public function manualPing($id): JsonResponse
    {
        $instance = ProviderInstance::find($id);

        if (!$instance) {
            return response()->json(['message' => 'Provider instance not found'], 404);
        }

        try {
            $blueprint = config("providers.{$instance->category_slug}");
            
            if (!$blueprint) {
                return response()->json(['message' => 'Blueprint configuration not found for this category'], 500);
            }

            $provider = ProviderFactory::make($instance->connection_settings, $blueprint);
            
            // 1. Execute heartbeat (this updates the DB internally)
            $provider->heartbeat($instance->id);
            $instance->refresh();

            // 2. Log the RESULT of the ping
            $logMethod = $instance->is_active ? 'info' : 'error';
            
            \App\Modules\Core\Auditing\Services\UapLogger::$logMethod('NetworkAudit', 'MANUAL_CONNECTIVITY_TEST', [
                'provider_name' => $instance->name,
                'category'      => $instance->category_slug,
                'host'          => $instance->connection_settings['host'] ?? 'N/A',
                'result'        => $instance->is_active ? 'SUCCESS' : 'FAILED',
                'error_details' => $instance->last_error_message ?? 'None'
            ]);

            if (!$instance->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Node is unreachable',
                    'error'   => $instance->last_error_message,
                ], 503); // Service Unavailable
            }

            return response()->json([
                'success' => true,
                'message' => 'Node is reachable',
            ]);

        } catch (\Exception $e) {
            \App\Modules\Core\Auditing\Services\UapLogger::error('NetworkAudit', 'CONNECTIVITY_TEST_CRASH', [
                'provider_id' => $id,
                'exception'   => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Check failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all commands available for a specific provider instance.
     */
    public function getCommands(int $id): JsonResponse
    {
        $instance = ProviderInstance::findOrFail($id);

        // Fetch commands from DB based on the instance category
        $commands = Command::where('category_slug', $instance->category_slug)
            ->where(function ($query) {
                // Include Global commands OR those created by the current user
                $query->where('is_custom', false)
                      ->orWhere('created_by', auth()->id());
            })
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'command_key', 'description', 'is_custom']);

        return response()->json([
            'instance_name' => $instance->name,
            'category' => $instance->category_slug,
            'commands' => $commands
        ]);
    }

    /**
     * Get list of provider categories with counts for dropdowns and filters.
     */
    public function getCategories()
    {
        // 1. Load the blueprints config
        $blueprints = config('blueprints');

        // If the config isn't registered in the standard Laravel config directory, 
        // you may need to load it manually:
        if (!$blueprints) {
            $blueprints = require app_path('Modules/Connectors/Config/blueprints.php');
        }

        $categories = [];

        foreach ($blueprints as $slug => $data) {
            $categories[] = [
                'slug'            => $slug,
                'name'            => $data['name'] ?? ucwords(str_replace('-', ' ', $slug)),
                'response_format' => $data['response_format'] ?? 'json',
                'command_count'   => count($data['commands'] ?? []),
            ];
        }

        return response()->json([
            'data' => $categories
        ]);
    }


    /**
     * Test connectivity using raw settings (Pre-save check).
     */
    public function testConnection(Request $request): JsonResponse
    {
        $request->validate([
            'category_slug'       => 'required|string',
            'connection_settings' => 'required|array',
        ]);

        $category = $request->category_slug;
        $settings = $request->connection_settings;
        $rules = [
            'host'     => 'required|string',
            'port'     => 'required|integer',
            'username' => 'required|string',
            'password' => 'required|string',
        ];

        if ($category === 'snmp-provider') {
            $rules['version'] = 'required|in:v2c,v3';
            $rules['community'] = 'required_if:version,v2c';
        } elseif ($category === 'web-service') {
            $rules['protocol'] = 'required|in:http,https';
        }

        $validator = \Validator::make($settings, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $blueprint = config("providers.{$category}");
            if (!$blueprint) {
                return response()->json(['message' => 'Blueprint not found'], 400);
            }

            $provider = ProviderFactory::make($settings, $blueprint);
            $isReachable = $provider->checkConnectivity(); 
            
            \App\Modules\Core\Auditing\Services\UapLogger::info('NetworkAudit', 'PRE_SAVE_CONNECTIVITY_TEST', [
                'category' => $category,
                'host'     => $settings['host'],
                'result'   => $isReachable ? 'SUCCESS' : 'FAILED'
            ]);

            if (!$isReachable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to reach host. Please check network settings, credentials, and firewall rules.',
                ], 503); // Service Unavailable
            }

            return response()->json([
                'success' => true,
                'message' => 'Connection established successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ], 500);
        }
    }
}