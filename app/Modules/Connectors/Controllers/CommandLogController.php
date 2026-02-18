<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\CommandLog;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Resources\CommandLogResource; // New Resource
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommandLogController extends Controller implements HasMiddleware
{
    protected CommandExecutor $executor;

    public function __construct(CommandExecutor $executor)
    {
        $this->executor = $executor;
    }

    
    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            new Middleware('permission:execute_commands', only: ['store']),
            new Middleware(\App\Http\Middleware\CheckCommandPermission::class, only: ['store']),
        ];
    }

    /**
     * List command logs with intelligent permission filtering and UI metadata.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = auth()->user();
        $query = CommandLog::with(['user:id,username', 'instance:id,name']);

        // 1. Authorization Logic
        if (!$user->can('view_all_command_logs')) {
            if ($user->can('view_own_command_logs')) {
                $query->where('user_id', $user->id);
            } else {
                return response()->json(['message' => 'Unauthorized to view logs'], 403);
            }
        }
        
        // 2. Filters
        if ($request->has('command_name')) {
            $query->where('command_name', $request->command_name);
        }
        if ($request->has('is_successful')) {
            $query->where('is_successful', $request->boolean('is_successful'));
        }
        if ($request->has('provider_instance_id')) {
            $query->where('provider_instance_id', $request->provider_instance_id);
        }

        // Return wrapped in Resource for metadata (response_format, etc)
        return CommandLogResource::collection($query->latest()->paginate(20));
    }

    /**
     * Show a specific log entry with full parsed and raw data.
     */
    public function show(string $id): CommandLogResource|JsonResponse
    {
        $log = CommandLog::with(['user', 'instance'])->findOrFail($id);
        
        // Security check
        if (!auth()->user()->can('view_all_command_logs') && $log->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return new CommandLogResource($log);
    }

    /**
     * Execute a new command and return the resulting log entry.
     */
    public function store(Request $request): CommandLogResource|JsonResponse
    {
        $validated = $request->validate([
            'instance_id' => 'required|integer|exists:provider_instances,id',
            'command_name' => 'required|string',
            'params' => 'required|array',
        ]);

        try {
            // Updated Executor should return the CommandLog Model instance
            $logEntry = $this->executor->execute(
                $validated['instance_id'],
                $validated['command_name'],
                $validated['params'],
                auth()->id()
            );

            // Return the resource so FE knows the response format (XML/MML) immediately
            return new CommandLogResource($logEntry);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}