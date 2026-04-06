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
use App\Modules\Connectors\Services\BatchValidationService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\Response;

class BatchJobController extends Controller
{
    public function __construct(
        protected BatchOrchestrator $orchestrator,
        protected BatchSchemaService $schemaService,
        protected BatchValidationService $validatorService
    ) {
    }

    public static function middleware(): array
    {
        return [
            // Ensure the user is authenticated for ALL methods
            new Middleware('auth:api'),

            // Discovery (The Mapping Step)
            new Middleware(PermissionMiddleware::using('discover_batch_headers'), only: ['discoverHeaders']),

            // Viewing (Templates & History)
            new Middleware(
                PermissionMiddleware::using('view_batch_templates|view_batch_instances'),
                only: ['indexTemplates', 'indexInstances', 'getInstanceStatus', 'showTemplate', 'stats', 'getInstanceSummary']
            ),

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
    public function discoverHeadersAnFirstRows(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'data_source_id' => 'required_without:file|exists:data_sources,id',
            'file'           => 'required_without:data_source_id|file|mimes:csv,txt,xlsx|max:10240',
            'source_config'  => 'required_with:data_source_id|array',
            'number_of_rows' => 'integer|min:1|max:100'
        ]);

        $rowCount = $request->get('number_of_rows', 5);

        try {
            // CASE A: Manual File Upload (Already implemented)
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $filename = 'discovery_' . auth()->id() . '_' . time() . '.' . $file->getClientOriginalExtension();
                $tempPath = $file->storeAs('temp/batch_discovery', $filename);
                $discovery = $this->schemaService->getSchemaFromUpload($file, $rowCount);

                return response()->json([
                    'headers'        => $discovery['headers'],
                    'preview'        => $discovery['rows'],
                    'temporary_path' => $tempPath,
                    'source_type'    => 'upload'
                ]);
            }

            // CASE B: Remote DataSources (SFTP, DB, API)
            $dataSource = \App\Modules\Connectors\Models\DataSource::findOrFail($request->data_source_id);

            // Pass the config and the model to the service
            // The service will use $dataSource->type to decide how to connect
            $discovery = $this->schemaService->discoverSchema(
                $dataSource, // Pass the whole model to get type and connection_settings
                $request->source_config,
                $rowCount
            );

            return response()->json([
                'headers'     => $discovery['headers'],
                'preview'     => $discovery['rows'],
                'source_type' => $dataSource->type
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Discovery failed: ' . $e->getMessage()], 422);
        }
    }

    /**
     * STEP 3 & 5: STORE TEMPLATE (The Permanent Contract)
     */
    public function storeTemplate(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'provider_instance_id' => 'required|exists:provider_instances,id',
            'data_source_id' => 'required|exists:data_sources,id',
            'command_id' => 'required|exists:commands,id',
            'column_mapping' => 'required|array',
            'source_config' => 'required|array',
            'is_scheduled' => 'boolean',
            'cron_expression' => 'required_if:is_scheduled,true|nullable|string',
        ]);

        $validator->after(function ($validator) use ($request) {
            $mapping = $request->input('column_mapping', []);
            $expectedColumns = $request->input('expected_columns', []);

            foreach ($mapping as $param => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $mode = $config['mode'] ?? null;
                $value = $config['value'] ?? null;
                $excluded = $config['excluded'] ?? false;

                if ($excluded) {
                    continue;
                }

                if ($mode === 'dynamic' && !empty($expectedColumns)) {
                    if (!in_array($value, $expectedColumns)) {
                        $validator->errors()->add("column_mapping.{$param}", "Column '{$value}' not found in file.");
                    }
                }
            }

            if ($request->is_scheduled && $request->cron_expression) {
                if (!\Cron\CronExpression::isValid($request->cron_expression)) {
                    $validator->errors()->add('cron_expression', 'The cron expression is invalid.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 1. Create the Template
        $template = JobTemplate::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'name' => $request->name,
            'user_id' => auth()->id(),
            'provider_instance_id' => $request->provider_instance_id,
            'data_source_id' => $request->data_source_id,
            'command_id' => $request->command_id,
            'column_mapping' => $request->column_mapping,
            'job_specific_config' => $request->job_specific_config ?? [],
            'source_config' => $request->source_config,
            'expected_columns' => $request->expected_columns ?? [],
            'is_scheduled' => $request->is_scheduled ?? false,
            'cron_expression' => $request->cron_expression,
            'status' => 'active',
            'workflow_steps' => $request->workflow_steps ?? [],
        ]);

        // 2. Immediate Execution Logic
        if (!$template->is_scheduled) {
            try {
                $instance = JobInstance::create([
                    'job_template_id' => $template->id,
                    'provider_instance_id' => $template->provider_instance_id,
                    'status' => 'pending',
                ]);

                // Trigger the orchestrator
                $this->orchestrator->execute($instance);

                // Log Success
                \App\Modules\Core\Auditing\Services\UapLogger::info('BatchEngine', 'IMMEDIATE_RUN_STARTED', [
                    'template_id' => $template->id,
                    'instance_id' => $instance->id,
                    'user_id'     => auth()->id()
                ]);

                return response()->json([
                    'message' => 'Job template created and execution started successfully.',
                    'template' => $template,
                    'instance_id' => $instance->id
                ], 201);

            } catch (\Exception $e) {
                // Log Failure
                \App\Modules\Core\Auditing\Services\UapLogger::error('BatchEngine', 'IMMEDIATE_RUN_TRIGGER_FAILED', [
                    'template_id' => $template->id,
                    'error'       => $e->getMessage(),
                    'user_id'     => auth()->id()
                ]);

                return response()->json([
                    'message' => 'Template created, but failed to start execution: ' . $e->getMessage(),
                    'template' => $template
                ], 201);
            }
        }

        // Log Scheduled Creation
        \App\Modules\Core\Auditing\Services\UapLogger::info('BatchEngine', 'TEMPLATE_SCHEDULED', [
            'template_id' => $template->id,
            'cron'        => $template->cron_expression
        ]);

        return response()->json([
            'message' => 'Batch job template scheduled successfully.',
            'template' => $template
        ], 201);
    }


    /**
     * Triggers an immediate run of a template.
     */
    public function runJob(string $templateId)
    {
        $template = JobTemplate::findOrFail($templateId);

        // Capture the Trace ID from the middleware-enriched header
        $traceId = request()->header('X-Request-ID');

        $instance = JobInstance::create([
            'job_template_id' => $template->id,
            'status' => 'pending',
            'user_id' => auth()->id(),
        ]);

        // Pass the traceId to the orchestrator
        $this->orchestrator->execute($instance, $traceId);

        return response()->json([
            'message' => 'Job execution started',
            'instance_id' => $instance->id,
            'trace_id' => $traceId // Useful for the frontend to track immediately
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
        \App\Modules\Core\Auditing\Services\UapLogger::error('SystemAudit', 'BATCH_TEMPLATE_DELETION_INITIATED', [
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

        \App\Modules\Core\Auditing\Services\UapLogger::info('BatchEngine', 'INSTANCE_STATUS_VIEWED', [
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

    /**
     * POST /api/batch/preview-mapping
     */
    public function previewMapping(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporary_path' => 'required|string',
            'command_id'     => 'required|exists:commands,id',
            'column_mapping' => 'required|array',
        ]);

        try {
            $preview = $this->validator->previewMapping(
                $validated['temporary_path'],
                $validated['command_id'],
                $validated['column_mapping']
            );

            return response()->json($preview);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
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
    * Display a listing of job templates with pagination and filtering.
    */
    public function indexTemplates(Request $request): \Illuminate\Http\JsonResponse
    {
        $perPage = $request->query('per_page', 15);
        $search = $request->query('search');

        $query = JobTemplate::with(['dataSource', 'providerInstance', 'user:id,name,username'])
            ->latest();

        // 1. Search Filter (Name or Description)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%");
                //->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // 2. Permission-based Filtering
        // If the user can't view all templates, only show their own
        if (!auth()->user()->can('view_all_batch_templates')) {
            $query->where('user_id', auth()->id());
        }

        $templates = $query->paginate($perPage);

        // We use appends to ensure the search term stays in the pagination links
        return response()->json($templates->appends($request->query()));
    }

    /**
     * Display the specific batch template details.
     * GET /api/batch/templates/{id}
    */
    public function showTemplate(string $id): \Illuminate\Http\JsonResponse
    {
        // Eager load command and provider instance for the frontend details view
        $template = JobTemplate::with(['providerInstance', 'dataSource', 'user'])
            ->findOrFail($id);

        // Security: Ensure users can only see their own templates unless they have global view permissions
        if (!auth()->user()->can('view_all_batch_templates') && $template->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized access to this template.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }

    /**
     * List all execution instances (history) for a specific template.
     * GET /api/batch/templates/{id}/instances
     */
    public function indexInstancesForTemplate(string $id): \Illuminate\Http\JsonResponse
    {
        $template = JobTemplate::findOrFail($id);

        // Security check
        if (!auth()->user()->can('view_all_batch_instances') && $template->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get instances ordered by latest execution
        $instances = $template->instances()
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $instances
        ]);
    }

    /**
     * Cancel an active running instance.
     */
    public function cancelInstance(string $instanceId)
    {
        $instance = JobInstance::findOrFail($instanceId);

        \App\Modules\Core\Auditing\Services\UapLogger::error('BatchEngine', 'JOB_MANUALLY_CANCELLED', [
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


    /**
     * Get aggregated statistics for Job Templates.
     */
    public function stats(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = JobTemplate::query();

        // 1. Permission-based Filtering
        if (!auth()->user()->can('view_all_batch_templates')) {
            $query->where('user_id', auth()->id());
        }

        // 2. Base Counts
        $total = (clone $query)->count();
        $active = (clone $query)->where('status', 'active')->count();
        $failed = (clone $query)->where('status', 'failed')->count();
        $paused = (clone $query)->where('status', 'paused')->count();

        // 3. Updated Completed Logic
        // Condition A: Not scheduled AND has a completed job instance
        // Condition B: Is scheduled AND the end date (ends_at) has passed
        $completed = (clone $query)->where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('is_scheduled', false)
                    ->whereHas('instances', function ($instanceQuery) {
                        $instanceQuery->where('status', 'completed');
                    });
            })->orWhere(function ($sub) {
                $sub->where('is_scheduled', true)
                    ->whereNotNull('ends_at')
                    ->where('ends_at', '<', now());
            });
        })->count();

        // 4. Calculate Success/Completion Rate
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total,
                'by_status' => [
                    'active'    => $active,
                    'failed'    => $failed,
                    'paused'    => $paused,
                    'completed' => $completed,
                ],
                'performance' => [
                    'completion_rate' => $completionRate,
                ]
            ]
        ]);
    }


    /**
     * Get summary statistics for a specific job instance.
     * GET /api/batch/instances/{instanceId}/summary
     */
    public function getInstanceSummary(string $instanceId): \Illuminate\Http\JsonResponse
    {
        $instance = JobInstance::with('template:id,name')->findOrFail($instanceId);

        // Security: Ensure users can only see their own instance summaries
        /*if (!auth()->user()->can('view_all_batch_instances') && $instance->template->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized access to this instance summary.'], 403);
        }*/

        return response()->json([
            'success' => true,
            'data' => [
                'instance_id' => $instance->id,
                'job_name'    => $instance->template->name,
                'status'      => $instance->status,
                'total'       => $instance->total_records,
                'executed'    => $instance->processed_records,
                'success'     => $instance->success_records,
                'failed'      => $instance->failed_records,
                'progress'    => $instance->progress_percentage,
                'started_at'   => $instance->started_at,
                'completed_at' => $instance->completed_at,
                'error_analysis' => $this->orchestrator->analyzeErrorFile($instance)
            ]
        ]);
    }

    public function exportAllErrors(string $instanceId): StreamedResponse
    {
        $disk = Storage::disk('local');

        $relativePath = "jobs/{$instanceId}/results_failed.csv";

        if (!$disk->exists($relativePath)) {
            abort(404, "Failure file not found at: " . $relativePath);
        }

        return $disk->download($relativePath, "errors_all_{$instanceId}.csv");
    }

    /**
     * Export failed records filtered by a specific error code.
     */
    public function exportErrorsByCode(Request $request, string $instanceId): StreamedResponse
    {
        $errorCode = $request->query('error_code');

        // Correct the path to include 'private'
        $disk = Storage::disk('local'); // or 'private' if you defined one

        $relativePath = "jobs/{$instanceId}/results_failed.csv";

        if (!$disk->exists($relativePath)) {
            abort(404, "Failure file not found at: " . $relativePath);
        }

        $sourcePath = $disk->path($relativePath);

        if (!Storage::exists($relativePath)) {
            abort(404, "Failure file not found at: " . $relativePath);
        }

        return response()->streamDownload(function () use ($sourcePath, $errorCode) {
            $csv = Reader::createFromPath($sourcePath, 'r');
            $csv->setHeaderOffset(0);

            $writer = Writer::createFromFileObject(new \SplTempFileObject());
            $writer->insertOne($csv->getHeader());

            foreach ($csv->getRecords() as $record) {
                $currentCode = $record['response_code'] ?? $record['status'] ?? null;
                if ($currentCode == $errorCode) {
                    $writer->insertOne($record);
                }
            }
            echo $writer->toString();
        }, "errors_{$errorCode}_{$instanceId}.csv");
    }

    /**
     * Download source data file for a specific instance.
     * GET /api/batch/instances/{instanceId}/download/source
     */
    public function downloadSourceFile(string $instanceId)
    {
        $instance = JobInstance::findOrFail($instanceId);

        $path = "jobs/{$instance->id}/source.csv";

        if (!Storage::exists($path)) {
            return response()->json(['error' => 'Source file not found.'], 404);
        }

        return Storage::download($path, "job_{$instance->id}_source.csv");
    }


    /**
     * Download a static generic sample CSV file.
     */
    public function downloadSample(): Response
    {
        $path = 'samples/generic_sample.csv';

        if (!Storage::exists($path)) {
            return response()->streamDownload(function () {
                echo "msisdn,nai\n";
                echo "242064690001,2\n";
                echo "242064690002,2\n";
                echo "242064690003,2\n";
                echo "242064690004,2\n";
            }, 'uap_sample_template.csv');
        }

        return response()->download(
            Storage::path($path),
            'uap_sample_template.csv'
        );
    }
}
