<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Models\CommandParameter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Modules\Connectors\Services\BlueprintService;
use App\Modules\Connectors\Providers\ProviderFactory;

class CommandController extends Controller
{
    public function __construct(
        protected BlueprintService $blueprintService
    ) {
    }

    /**
     * Set up middleware for the controller.
     */
    public static function middleware(): array
    {
        return [
            // Any authenticated user with 'view_commands' can see the list and blueprints
            new \Illuminate\Routing\Controllers\Middleware('permission:view_commands', only: ['index', 'show']),

            // Only technical leads/admins can manage the underlying blueprints
            new \Illuminate\Routing\Controllers\Middleware('permission:manage_commands', only: ['store', 'update', 'destroy']),
        ];
    }


    /**
     * List available commands with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Command::query();

        // 1. Apply existing filters
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

        // 2. Minimal/Dropdown Mode
        if ($request->boolean('minimal')) {
            // We use get() instead of paginate() because dropdowns
            // usually need the full list to search locally.
            $commands = $query->orderBy('name', 'asc')
                              ->get(['id', 'name', 'command_key']);

            return response()->json([
                'success' => true,
                'data' => $commands
            ]);
        }

        // 3. Standard Paginated Mode
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

        // 1. Initialize Provider via Factory
        $provider = ProviderFactory::make([], $command->toArray());

        // 2. Get the standard parsed data (contains parameters + system_params)
        $parsedData = $provider->parseSamplePayload($command->request_payload);

        // 3. Generate the specific Mapping Blueprint (filtered for user inputs)
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

            // Values exactly as they are in your current response
            'parameters'        => $mergedParams,
            'meta'              => [
                'method'       => $parsedData['method'] ?? $command->command_key,
                'system_keys'  => array_keys($parsedData['system_params'] ?? [])
            ],

            // The specific list for the Batch Job Mapping Wizard (Filtered)
            'mapping_blueprint' => $blueprint
        ]);
    }

    /**
     * Create a custom command (Cloning or New).
     * Technical users can use this to save their own payload templates.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_slug'   => 'required|string',
            'name'            => 'required|string',
            'command_key'     => 'required|string',
            'request_payload' => 'required|string',
            'action' => 'required|string',
        ]);

        // 1. Use the Factory to get the right provider
        $provider = ProviderFactory::make([], ['category_slug' => $validated['category_slug']]);

        // 2. Delegate extraction to the provider instance
        $systemParams = $provider->extractSystemParams($validated['request_payload']);

        // 3. Save the command with the auto-extracted parameters
        $command = Command::create(array_merge($validated, [
            'system_params' => $systemParams,
            'is_custom'     => true,
            'created_by'    => auth()->id(),
        ]));

        return response()->json($command, 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $command = Command::findOrFail($id);

        $validated = $request->validate([
            'category_slug' => 'required|string',
            'name' => 'required|string',
            'command_key' => 'required|string',
            'request_payload' => 'required|string',
            'action' => 'required|string',
        ]);

        // 1. Use the Factory to get the right provider
        $provider = ProviderFactory::make([], ['category_slug' => $validated['category_slug']]);

        // 2. Extract system params
        $validated['system_params'] = $provider->extractSystemParams($validated['request_payload']);

        // 3. Single update
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
}
