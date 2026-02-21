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

class BatchJobController extends Controller
{
    public function __construct(
        protected BatchOrchestrator $orchestrator,
        protected BatchSchemaService $schemaService
    ) {}

    /**
     * STEP 2: DISCOVER HEADERS (Unified)
     * The FE sends the connection ID and the resource details (table, path, etc.)
     * The API returns the headers so the user can proceed to Step 3 (Mapping).
     */
    public function discoverHeaders(Request $request)
    {
        $request->validate([
            'data_source_id' => 'required_without:file|exists:data_sources,id',
            'file'           => 'required_without:data_source_id|file|mimes:csv,txt',
            'source_config'  => 'required_with:data_source_id|array', // e.g., ['table' => 'users']
        ]);

        try {
            // CASE A: Manual File Upload
            if ($request->hasFile('file')) {
                $headers = $this->schemaService->getHeadersFromUpload($request->file('file'));
                return response()->json(['headers' => $headers]);
            }

            // CASE B: Existing DataSource (SFTP, DB, API)
            $dataSource = \App\Modules\Connectors\Models\DataSource::findOrFail($request->data_source_id);
            
            // We pass the config (e.g., path/pattern) to the schema service
            $headers = $this->schemaService->discoverHeaders($dataSource, $request->source_config);

            return response()->json(['headers' => $headers]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Discovery failed: ' . $e->getMessage()], 422);
        }
    }

    /**
     * STEP 3 & 5: STORE TEMPLATE (The Permanent Contract)
     */
    public function storeTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:255',
            'provider_instance_id'  => 'required|exists:provider_instances,id',
            'data_source_id'        => 'required|exists:data_sources,id',
            'column_mapping'        => 'required|array', // The mapping contract
            'expected_columns'      => 'required|array', // The headers discovered in Step 2
            'workflow_steps'        => 'required|array|min:1',
            'job_specific_config'   => 'nullable|array',
            
            // Scheduling logic
            'is_scheduled'          => 'boolean',
            'cron_expression'       => 'required_if:is_scheduled,true|nullable|string',
            'timezone'              => 'string',
            'starts_at'             => 'nullable|date',
            'ends_at'               => 'nullable|date|after:starts_at',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $data = $validator->validated();

            // 1. Calculate the first run if scheduled
            if ($data['is_scheduled'] ?? false) {
                $cron = new \Cron\CronExpression($data['cron_expression']);
                $data['next_run_at'] = $cron->getNextRunDate()->format('Y-m-d H:i:s');
                $data['schedule_active'] = true;
            }

            $data['user_id'] = auth()->id() ?? $request->user_id;
            $data['id'] = \Illuminate\Support\Str::uuid();

            // 2. Create the Template
            $template = JobTemplate::create($data);

            return response()->json([
                'message' => 'Template created successfully',
                'template_id' => $template->id
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
            'source'  => 'source.csv' // This is the processed input snapshot
        ];

        if (!isset($files[$type])) {
            return response()->json(['error' => 'Invalid file type'], 400);
        }

        $path = config('connectors.batch.storage_path', 'jobs') . "/{$instance->id}/{$files[$type]}";

        if (!Storage::exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return Storage::download($path, "job_{$instance->id}_{$files[$type]}");
    }

    /**
     * STATUS & SCHEDULING (Preserved & Refined)
     */
    public function getInstanceStatus(string $instanceId)
    {
        $instance = JobInstance::findOrFail($instanceId);
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
}