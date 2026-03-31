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

        // Base query with eager loading
        $query = CommandLog::with([
            'user:id,name',
            'instance:id,name',
            'command:id,name'
        ]);

        // 1. Authorization Logic
        if (!$user->can('view_all_command_logs')) {
            $query->where('user_id', $user->id);
        }

        // 2. NEW: Filter by Job Instance ID
        // This allows viewing logs tied to a specific batch execution
        if ($request->filled('job_instance_id')) {
            $query->where('job_instance_id', $request->query('job_instance_id'));
        }

        // 3. Additional Filters (Search, Status, etc.)
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('command_name', 'ilike', '%' . $request->query('search') . '%')
                  ->orWhere('response_code', 'ilike', '%' . $request->query('search') . '%');
            });
        }

        if ($request->filled('status')) {
            $isSuccessful = $request->query('status') === 'success';
            $query->where('is_successful', $isSuccessful);
        }

        // 4. Sort and Paginate
        $logs = $query->orderBy('created_at', 'desc')->paginate($request->query('per_page', 15));

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
            'payload'     => 'required',
            'mode'        => 'sometimes|string|in:form,raw',
        ]);

        try {
            $logEntry = $this->executor->execute(
                $validated['instance_id'],
                $validated['command_id'],
                $validated['payload'],
                auth()->id(),
                null,
                $request->header('X-Request-ID'),
                $request->get('mode', 'form')
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
