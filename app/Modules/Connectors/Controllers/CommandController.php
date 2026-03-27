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
        $perPage = $request->query('per_page', 15);

        $query = Command::query();

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%' . $request->query('search') . '%')
                  ->orWhere('command_key', 'ilike', '%' . $request->query('search') . '%');
        }

        if ($request->filled('category')) {
            $query->where('category_slug', $request->category);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Access Control: Global commands OR user-specific custom ones
        /* $query->where(function ($q) {
            $q->where('is_custom', false)
              ->orWhere('created_by', auth()->id());
        }); */

        $paginatedCommands = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($paginatedCommands);
    }

    /**
     * Display the full blueprint of a command, including nested parameters.
     */
    public function show($id): JsonResponse
    {
        $command = Command::findOrFail($id);
        $provider = ProviderFactory::make([], ['category_slug' => $command->category_slug]);

        $parsed = $provider->parseSamplePayload($command->request_payload ?? "");

        // Merge system and user params for the form
        $combinedParams = array_merge($parsed['system_params'], $parsed['params']);

        $response = $command->toArray();

        // OVERWRITE: Use the injected raw XML so FE 'Raw Mode' matches 'Form Mode'
        $response['parameters'] = $combinedParams;
        $response['request_payload'] = $parsed['raw_payload'];

        $response['meta'] = [
            'method' => $parsed['method'],
            'system_keys' => array_keys($parsed['system_params'])
        ];

        return response()->json($response);
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
