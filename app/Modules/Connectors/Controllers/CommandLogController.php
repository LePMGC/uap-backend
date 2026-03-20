<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\CommandLog;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Resources\CommandLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

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

        // Eager load 'command' to show the friendly name in the log table
        $query = CommandLog::with(['user:id,username', 'instance:id,name', 'command:id,name']);

        // 1. Authorization Logic
        if (!$user->can('view_all_command_logs')) {
            if ($user->can('view_own_command_logs')) {
                $query->where('user_id', $user->id);
            } else {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        // 2. New Filters
        if ($request->has('command_id')) {
            $query->where('command_id', $request->command_id);
        }

        if ($request->has('instance_id')) {
            $query->where('provider_instance_id', $request->instance_id);
        }

        if ($request->has('category')) {
            $query->where('category_slug', $request->category);
        }

        if ($request->has('status')) {
            $isSuccessful = $request->status === 'success';
            $query->where('is_successful', $isSuccessful);
        }

        if ($request->has('date')) {
            $query->whereDate('created_at', $request->date);
        }

        // 3. Search by MSISDN (Searching within JSON request_payload)
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('request_payload', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('command_name', 'LIKE', "%{$searchTerm}%");
            });
        }

        $logs = $query->latest()->paginate($request->query('per_page', 15));

        return CommandLogResource::collection($logs);
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
     * Execute a new command using command_id.
     */
    public function store(Request $request): CommandLogResource|JsonResponse
    {
        $validated = $request->validate([
            'instance_id' => 'required|integer|exists:provider_instances,id',
            'command_id'  => 'required|integer|exists:commands,id',
            'payload'      => 'required',
        ]);

        try {
            $logEntry = $this->executor->execute(
                $validated['instance_id'],
                $validated['command_id'],
                $validated['payload'],
                auth()->id(),
                null,
                $request->header('X-Request-ID')
            );

            return new CommandLogResource($logEntry);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
