<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchOrchestrator;
use App\Modules\Connectors\Services\BatchSchemaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Cron\CronExpression;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;


class BatchJobController extends Controller
{
    public function __construct(
        protected BatchOrchestrator $orchestrator,
        protected BatchSchemaService $schemaService
    ) {}

    public static function middleware(): array
    {
        return [
            // Ensure the user is authenticated for ALL methods
            new Middleware('auth:api'),

            // Discovery (The Mapping Step)
            new Middleware(PermissionMiddleware::using('discover_batch_headers'), only: ['discoverHeaders']),

            // Viewing (Templates & History)
            new Middleware(PermissionMiddleware::using('view_batch_templates|view_batch_instances'), only: ['indexTemplates', 'indexInstances', 'getInstanceStatus', 'showTemplate']),

            // Creation
            new Middleware(PermissionMiddleware::using('create_batch_templates'), only: ['storeTemplate']),

            // Execution
            new Middleware(PermissionMiddleware::using('run_batch_jobs'), only: ['runJob']),

            // Results
            new Middleware(PermissionMiddleware::using('download_batch_results'), only: ['downloadFile']),

            // Deletion
            new Middleware(PermissionMiddleware::using('delete_batch_templates'), only: ['destroyTemplate']),

            // Schedule Management
            new Middleware(PermissionMiddleware::using('manage_batch_schedules'), only: ['toggleSchedule', 'terminateSchedule', 'updateSchedule']),

            // Cancellation
            new Middleware(PermissionMiddleware::using('cancel_batch_instances'), only: ['cancelInstance']),
        ];
    }

    /**
     * STEP 2: DISCOVER HEADERS (Unified)
     * The FE sends the connection ID and the resource details (table, path, etc.)
     * The API returns the headers so the user can proceed to Step 3 (Mapping).
     */
    public function discoverHeaders(Request $request)
    {
        $request->validate([
            'data_source_id' => 'required_without:file|exists:data_sources,id',
            'file'           => 'required_without:data_source_id|file|mimes:csv,txt|max:10240',
            'source_config'  => 'required_with:data_source_id|array',
        ]);

        try {
            // CASE A: Manual File Upload
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                
                // 1. Store the file in a temp directory
                // We use a unique name to avoid collisions during concurrent user sessions
                $filename = 'discovery_' . auth()->id() . '_' . time() . '.' . $file->getClientOriginalExtension();
                $tempPath = $file->storeAs('temp/batch_discovery', $filename);

                // 2. Extract headers using the service
                $headers = $this->schemaService->getHeadersFromUpload($file);

                return response()->json([
                    'headers' => $headers,
                    'temporary_path' => $tempPath, // FE will send this back in storeTemplate
                    'source_type' => 'upload'
                ]);
            }

            // CASE B: Existing DataSource (SFTP, DB, API)
            $dataSource = \App\Modules\Connectors\Models\DataSource::findOrFail($request->data_source_id);

            \App\Modules\Connectors\Services\UapLogger::info('SchemaService', 'REMOTE_HEADER_DISCOVERY_REQUESTED', [
                'user_id' => auth()->id(),
                'source_name' => $source->name,
                'config_keys' => array_keys($request->source_config)
            ]);

            $headers = $this->schemaService->discoverHeaders($dataSource->type, $request->source_config);

            return response()->json([
                'headers' => $headers,
                'source_type' => $dataSource->type
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Discovery failed: ' . $e->getMessage()], 422);
        }
    }

    /**
     * STEP 3 & 5: STORE TEMPLATE (The Permanent Contract)
     */
    public function storeTemplate(Request $request)
    {
        $validated = $request->validate([
            'name'                 => 'required|string',
            'provider_instance_id' => 'required|integer',
            'data_source_id'       => 'required|integer',
            'expected_columns'     => 'required|array',
            'column_mapping'       => 'required|array',
            'workflow_steps'       => 'required|array',
            'job_specific_config'  => 'nullable|array',
            'source_config'        => 'nullable|array',
            'is_scheduled'         => 'boolean',
        ]);

        try {
            // 2. Handle the file migration logic
            if (isset($validated['source_config']['temporary_path'])) {
                $tempPath = $validated['source_config']['temporary_path'];
                
                // Check if file exists in temp storage before proceeding
                if (!Storage::exists($tempPath)) {
                    return response()->json([
                        'error' => 'Template creation failed: The temporary source file was not found. Please re-upload the sample file.'
                    ], 422);
                }

                $filename = basename($tempPath);
                $permanentPath = "templates/" . $filename; 

                // Move file to the permanent /private/templates folder
                Storage::move($tempPath, $permanentPath);
                
                // Update the config with the permanent path for the DB record
                $validated['source_config']['file_path'] = $permanentPath;
                unset($validated['source_config']['temporary_path']);
            }

            // 3. Explicitly create the model
            $template = new \App\Modules\Connectors\Models\JobTemplate();
            $template->fill($validated);
            $template->user_id = auth()->id();
            $template->id = (string) \Illuminate\Support\Str::uuid();
            $template->save();

            \App\Modules\Connectors\Services\UapLogger::info('SystemAudit', 'BATCH_TEMPLATE_CREATED', [
                'template_id' => $template->id,
                'template_name' => $template->name,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Template created successfully',
                'id' => $template->id
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Template creation failed: ' . $e->getMessage()], 500);
        }
    }

    
    /**
     * Triggers an immediate run of a template.
     */
    public function runJob(string $templateId)
    {
        $template = JobTemplate::findOrFail($templateId);

        // 1. Create a Job Instance
        $instance = JobInstance::create([
            'job_template_id' => $template->id,
            'status' => 'pending',
            'user_id' => auth()->id(),
        ]);

        // 2. Pass to Orchestrator to begin ingestion and execution
        // The Orchestrator will use the connectors to pull the file/data
        $this->orchestrator->execute($instance);

        return response()->json([
            'message' => 'Job execution started',
            'instance_id' => $instance->id
        ]);
    }

    /**
     * DOWNLOAD ENDPOINTS (Preserved)
     */
    public function downloadFile(string $instanceId, string $type)
    {
        $instance = JobInstance::findOrFail($instanceId);
        
        $files = [
            'success' => 'results_success.csv',
            'failed'  => 'results_failed.csv',
            'source'  => 'source.csv'
        ];

        // Remove 'private/' from the path. 
        // Laravel usually maps the 'local' disk to 'storage/app' or 'storage/app/private'
        $path = "jobs/{$instance->id}/" . $files[$type];

        if (!Storage::exists($path)) {
            return response()->json([
                'error' => 'File not found on storage',
                'debug_path' => $path 
            ], 404);
        }

        return Storage::download($path, "job_{$instance->id}_{$files[$type]}");
    }

    /**
     * Remove the specified job template.
     */
    public function destroyTemplate(string $id)
    {
        $template = JobTemplate::findOrFail($id);

        // 1. LOG: Capturing the destructive intent before it happens
        \App\Modules\Connectors\Services\UapLogger::error('SystemAudit', 'BATCH_TEMPLATE_DELETION_INITIATED', [
            'user_id'       => auth()->id(),
            'template_id'   => $id,
            'template_name' => $template->name,
            'has_instances' => $template->instances()->count() > 0
        ], 'WARNING');

        // 2. Safety Check (Optional): Prevent deletion if there are active instances
        $activeInstances = $template->instances()->whereNotIn('status', ['completed', 'failed'])->count();
        if ($activeInstances > 0) {
            return response()->json([
                'error' => "Cannot delete template. There are {$activeInstances} active jobs running based on this template."
            ], 422);
        }

        // 3. Perform Deletion
        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully.'
        ]);
    }

    /**
     * STATUS & SCHEDULING (Preserved & Refined)
     */
    public function getInstanceStatus(string $instanceId)
    {
        $instance = JobInstance::findOrFail($instanceId);

        \App\Modules\Connectors\Services\UapLogger::info('BatchEngine', 'INSTANCE_STATUS_VIEWED', [
            'instance_id' => $instanceId,
            'current_status' => $instance->status,
            'user_id' => auth()->id()
        ]);
    
        return response()->json([
            'id' => $instance->id,
            'status' => $instance->status,
            'progress' => [
                'total' => $instance->total_records,
                'processed' => $instance->processed_records,
                'failed' => $instance->failed_records,
                'percentage' => $instance->progress_percentage
            ]
        ]);
    }

    public function toggleSchedule(string $id)
    {
        $template = JobTemplate::findOrFail($id);
        $template->update(['schedule_active' => !$template->schedule_active]);
        return response()->json(['schedule_active' => $template->schedule_active]);
    }

    public function terminateSchedule(string $id)
    {
        $template = JobTemplate::findOrFail($id);
        $template->update([
            'is_scheduled' => false,
            'schedule_active' => false,
            'next_run_at' => null,
            'ends_at' => now(),
        ]);
        return response()->json(['message' => 'Schedule terminated.']);
    }


    /**
     * Display a listing of job templates.
     */
    public function indexTemplates(Request $request)
    {
        // Filter by user or search term if needed
        $templates = JobTemplate::with('dataSource', 'providerInstance')
            ->latest()
            ->paginate(15);

        return response()->json($templates);
    }

    /**
     * Display a listing of job instances (Execution History).
     */
    public function indexInstances(Request $request)
    {
        $instances = JobInstance::with('template')
            ->latest()
            ->paginate(20);

        return response()->json($instances);
    }

    /**
     * Cancel an active running instance.
     */
    public function cancelInstance(string $instanceId)
    {
        $instance = JobInstance::findOrFail($instanceId);

        \App\Modules\Connectors\Services\UapLogger::error('BatchEngine', 'JOB_MANUALLY_CANCELLED', [
            'instance_id' => $instanceId,
            'user_id'     => auth()->id()
        ], 'WARNING');
        
        // Check if the job is in a state that can be cancelled
        if (in_array($instance->status, ['pending', 'loading_data', 'dispatching', 'processing'])) {
            
            // If you are using Laravel Bus Batches, you could also cancel the underlying batch
            // $instance->cancelBusBatch(); 

            $instance->update([
                'status' => 'failed',
                'completed_at' => now(),
                // We log that it was manually stopped
            ]);

            return response()->json(['message' => 'Job instance cancellation requested.']);
        }

        return response()->json(['error' => 'Job is already finished or cannot be cancelled.'], 422);
    }

    public function downloadReport(string $instanceId, Request $request)
    {
        $format = $request->query('format', 'xlsx'); // Default to xlsx for multi-sheet
        $instance = JobInstance::with(['template.providerInstance', 'template.dataSource', 'executor'])
            ->findOrFail($instanceId);

        return $this->orchestrator->generateReport($instance, $format);
    }
}