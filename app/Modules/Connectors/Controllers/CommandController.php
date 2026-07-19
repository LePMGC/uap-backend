<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Models\CommandParameter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Modules\Connectors\Services\BlueprintService;
use App\Modules\Connectors\Providers\ProviderFactory;
use App\Modules\Connectors\Services\BatchSchemaService;

class CommandController extends Controller
{
    public function __construct(
        protected BlueprintService $blueprintService,
        protected BatchSchemaService $batchService
    ) {
    }

    /**
     * Set up middleware for the controller.
     */
    public static function middleware(): array
    {
        return [
            // Absolute baseline requirement: User must provide a valid API token
            new \Illuminate\Routing\Controllers\Middleware('auth:api'),

            // 1. Structural Management Rights (Admins or high-level managers editing global parameters)
            new \Illuminate\Routing\Controllers\Middleware(
                \Spatie\Permission\Middleware\PermissionMiddleware::using(['manage_all_commands', 'manage_own_commands']),
                only: ['store', 'update', 'destroy']
            ),

            // 2. Structural Query Rights (Viewing lists, blueprints, projection test sheets)
            new \Illuminate\Routing\Controllers\Middleware(
                \Spatie\Permission\Middleware\PermissionMiddleware::using(['view_all_commands', 'view_own_commands']),
                only: ['index', 'show', 'projectPayload']
            ),
        ];
    }


    /**
     * List available commands with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = Command::query();

        // Multi-tenant scope: If operator cannot view ALL commands, restrict query to items they authored
        if (!$user->can('view_all_commands')) {
            // Assumes your table schema tracks creator IDs. If it uses 'created_by' columns:
            $query->where('created_by', $user->id);
        }

        // Apply existing filters
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->query('search') . '%')
                  ->orWhere('command_key', 'ilike', '%' . $request->query('search') . '%');
            });
        }

        if ($request->filled('category')) {
            $query->where('category_slug', $request->category);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Minimal/Dropdown Mode
        if ($request->boolean('minimal')) {
            $commands = $query->orderBy('name', 'asc')
                              ->get(['id', 'name', 'command_key']);

            return response()->json([
                'success' => true,
                'data' => $commands
            ]);
        }

        // Standard Paginated Mode
        $perPage = $request->query('per_page', 15);
        $paginatedCommands = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($paginatedCommands);
    }

    /**
     * Display the specified command.
     */
    public function show($id): JsonResponse
    {
        $command = Command::findOrFail($id);
        $user = auth()->user();

        // Enforce data tenancy security check
        if (!$user->can('view_all_commands') && $command->created_by !== $user->id) {
            abort(403, 'You do not have permission to access this command record.');
        }

        // Initialize Provider via Factory
        $provider = ProviderFactory::make([], $command->toArray());

        // Get the standard parsed data (contains parameters + system_params)
        $parsedData = $provider->parseSamplePayload($command->request_payload);

        // Generate the specific Mapping Blueprint (filtered for user inputs)
        $blueprint = $provider->getMappingBlueprint($command->request_payload);

        $mergedParams = array_merge(
            $parsedData['system_params'] ?? [],
            $parsedData['params'] ?? []
        );

        return response()->json([
            'id'                => $command->id,
            'category_slug'     => $command->category_slug,
            'name'              => $command->name,
            'command_key'       => $command->command_key,
            'action'            => $command->action,
            'description'       => $command->description,
            'request_payload'   => $command->request_payload,
            'system_params'     => $command->system_params,
            'is_custom'         => $command->is_custom,
            'created_at'        => $command->created_at,
            'updated_at'        => $command->updated_at,
            'parameters'        => $mergedParams,
            'meta'              => [
                'method'       => $parsedData['method'] ?? $command->command_key,
                'system_keys'  => array_keys($parsedData['system_params'] ?? [])
            ],
            'mapping_blueprint' => $blueprint
        ]);
    }

    /**
         * Create a custom command (Cloning or New).
         */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_slug'   => 'required|string',
            'name'            => 'required|string',
            'command_key'     => 'required|string',
            'request_payload' => 'required|string',
            'action'          => 'required|string',
        ]);

        $provider = ProviderFactory::make([], [
            'category_slug' => $validated['category_slug']
        ]);

        // 1. Generic structural schema validation
        $validation = $provider->validateSamplePayload($validated['request_payload']);
        if (!$validation['valid']) {
            return response()->json([
                'message' => 'Invalid sample payload structural schema',
                'errors'  => $validation['errors']
            ], 422);
        }

        // 2. Polymorphic command key alignment verification
        $keyValidation = $provider->validateCommandKeyOnPayload(
            $validated['command_key'],
            $validated['request_payload']
        );
        if (!$keyValidation['valid']) {
            return response()->json([
                'message' => 'Command key validation failed',
                'errors'  => $keyValidation['errors']
            ], 422);
        }

        $systemParams = $provider->extractSystemParams($validated['request_payload']);

        $command = Command::create(array_merge($validated, [
            'system_params' => $systemParams,
            'is_custom'     => true,
            'created_by'    => auth()->id(),
        ]));

        return response()->json($command, 201);
    }

    /**
     * Update the specified custom command parameters safely.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $command = Command::findOrFail($id);

        $validated = $request->validate([
            'category_slug'   => 'required|string',
            'name'            => 'required|string',
            'command_key'     => 'required|string',
            'request_payload' => 'required|string',
            'action'          => 'required|string',
        ]);

        $provider = ProviderFactory::make([], [
            'category_slug' => $validated['category_slug']
        ]);

        // 1. Generic structural validation
        $validation = $provider->validateSamplePayload($validated['request_payload']);
        if (!$validation['valid']) {
            return response()->json([
                'message' => 'Invalid sample payload structural schema',
                'errors'  => $validation['errors']
            ], 422);
        }

        // 2. Polymorphic command key alignment validation
        $keyValidation = $provider->validateCommandKeyOnPayload(
            $validated['command_key'],
            $validated['request_payload']
        );
        if (!$keyValidation['valid']) {
            return response()->json([
                'message' => 'Command key validation failed',
                'errors'  => $keyValidation['errors']
            ], 422);
        }

        $validated['system_params'] = $provider->extractSystemParams($validated['request_payload']);

        $command->update($validated);

        return response()->json($command);
    }


    /**
     * Delete a custom command.
     */
    public function destroy($id): JsonResponse
    {
        $command = Command::findOrFail($id);
        $command->delete();

        return response()->json(['message' => 'Command deleted successfully']);
    }

    /**
     * POST /api/management/commands/{id}/project-payload
     */
    public function projectPayload(Request $request, $id): JsonResponse
    {
        $command = Command::findOrFail($id);

        $validated = $request->validate([
            'mapping'     => 'required|array',
            'sample_data' => 'required|array',
        ]);

        try {
            $projectedPayload = $this->batchService->projectCommandPayload(
                $command,
                $validated['mapping'],
                $validated['sample_data']
            );

            return response()->json([
                'success' => true,
                'command_key' => $command->command_key,
                'projected_payload' => $projectedPayload
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payload projection failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
