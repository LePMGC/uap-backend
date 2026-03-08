<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Models\CommandParameter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CommandController extends Controller
{

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
        $query->where(function ($q) {
            $q->where('is_custom', false)
              ->orWhere('created_by', auth()->id());
        });

        $paginatedCommands = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($paginatedCommands);
    }

    /**
     * Display the full blueprint of a command, including nested parameters.
     */
    public function show($id): JsonResponse
    {
        // We load 'parameters.children' recursively to support nested structs
        $command = Command::with(['parameters.children.children'])->findOrFail($id);

        return response()->json($command);
    }

    /**
     * Create a custom command (Cloning or New).
     * Technical users can use this to save their own payload templates.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_slug' => 'required|string',
            'name' => 'required|string',
            'command_key' => 'required|string',
            'payload_template' => 'required|string', // The XML/JSON/CAI body
            'system_params' => 'nullable|array',
        ]);

        $command = Command::create(array_merge($validated, [
            'is_custom' => true,
            'created_by' => auth()->id(),
        ]));

        return response()->json($command, 201);
    }

    /**
     * Update a custom command.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $command = Command::where('created_by', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'payload_template' => 'sometimes|string',
            'system_params' => 'sometimes|array',
        ]);

        $command->update($validated);

        return response()->json($command);
    }

    /**
     * Delete a custom command.
     */
    public function destroy($id): JsonResponse
    {
        $command = Command::where('created_by', auth()->id())->findOrFail($id);
        $command->delete();

        return response()->json(['message' => 'Command deleted successfully']);
    }
}