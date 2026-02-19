<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchOrchestrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Cron\CronExpression;

class BatchJobController extends Controller
{
    public function __construct(
        protected BatchOrchestrator $orchestrator
    ) {}


    /**
     * Create or Update a Template with Scheduling Support
     */
    public function storeTemplate(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'provider_instance_id' => 'required|exists:provider_instances,id',
            'data_source_id' => 'required|exists:data_sources,id',
            'job_specific_config' => 'required|array',
            'column_mapping' => 'required|array',
            'workflow_steps' => 'required|array',
            
            // Scheduling Fields
            'is_scheduled' => 'boolean',
            'cron_expression' => 'required_if:is_scheduled,true|nullable|string',
            'timezone' => 'string'
        ]);

        // If scheduled, validate the cron expression and calculate next run
        if ($request->is_scheduled) {
            try {
                $cron = new CronExpression($request->cron_expression);
                $validated['next_run_at'] = $cron->getNextRunDate()->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return response()->json(['error' => 'Invalid Cron Expression'], 422);
            }
        }

        $template = JobTemplate::updateOrCreate(
            ['id' => $request->id], // For updates
            array_merge($validated, ['user_id' => auth()->id() ?? 1])
        );

        return response()->json($template);
    }

    /**
     * Step 2: Trigger an Execution Instance
     */
    public function runJob(string $templateId, Request $request)
    {
        $template = JobTemplate::findOrFail($templateId);

        // Create the Instance (UUID is auto-generated in model)
        $instance = JobInstance::create([
            'job_template_id' => $template->id,
            'status' => 'pending',
            'instance_parameters' => $request->input('parameters', []),
        ]);

        // Trigger the orchestrator asynchronously (You can also dispatch this as a Job)
        // For now, we call execute which handles the stages
        $this->orchestrator->execute($instance);

        return response()->json([
            'message' => 'Job started successfully',
            'instance_id' => $instance->id
        ]);
    }

    /**
     * Step 3: The Status Check Endpoint (The FE Poller)
     */
    public function getInstanceStatus(string $instanceId)
    {
        $instance = JobInstance::findOrFail($instanceId);

        return response()->json([
            'id' => $instance->id,
            'status' => $instance->status, // loading_data, processing, etc.
            'progress' => [
                'total' => $instance->total_records,
                'processed' => $instance->processed_records,
                'failed' => $instance->failed_records,
                'percentage' => $instance->progress_percentage // Using the accessor we built
            ],
            'timing' => [
                'started_at' => $instance->started_at,
                'completed_at' => $instance->completed_at,
            ]
        ]);
    }

    /**
     * Download Results or the Original Source Snapshot
     * Supported types: 'success', 'failed', 'source'
     */
    public function downloadFile(string $instanceId, string $type)
    {
        $instance = JobInstance::findOrFail($instanceId);
        
        $files = [
            'success' => 'results_success.csv',
            'failed'  => 'results_failed.csv',
            'source'  => 'source.csv'
        ];

        if (!isset($files[$type])) {
            return response()->json(['error' => 'Invalid file type requested'], 400);
        }

        $path = config('connectors.batch.storage_path') . "/{$instance->id}/{$files[$type]}";

        if (!Storage::exists($path)) {
            return response()->json(['error' => 'File not found on server'], 404);
        }

        // Custom download name to include the Instance UUID for the admin
        $downloadName = "job_{$instance->id}_{$files[$type]}";

        return Storage::download($path, $downloadName);
    }


    /**
     * Toggle Schedule (Pause/Resume)
     */
    public function toggleSchedule(string $id)
    {
        $template = JobTemplate::findOrFail($id);
        
        $template->update([
            'schedule_active' => !$template->schedule_active
        ]);

        return response()->json([
            'message' => $template->schedule_active ? 'Schedule Resumed' : 'Schedule Paused',
            'schedule_active' => $template->schedule_active
        ]);
    }

    /**
     * Update Scheduling Parameters with Immutability Guards
     */
    public function updateSchedule(Request $request, string $id)
    {
        $template = JobTemplate::findOrFail($id);
        
        $validated = $request->validate([
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'cron_expression' => 'nullable|string',
        ]);

        // GUARD: Cannot change starts_at if it has already passed
        if ($request->has('starts_at') && $template->starts_at && $template->starts_at->isPast()) {
            if ($request->starts_at != $template->starts_at->toDateTimeString()) {
                return response()->json(['error' => 'The start date cannot be changed because it has already passed.'], 422);
            }
        }

        $template->update($validated);

        // Refresh the next run time if the cron or start date changed
        if ($template->is_scheduled && $template->schedule_active) {
            $cron = new \Cron\CronExpression($template->cron_expression);
            $template->update([
                'next_run_at' => $cron->getNextRunDate($template->starts_at ?? now())
            ]);
        }

        return response()->json($template);
    }

    /**
     * Hard Terminate the Schedule
     */
    public function terminateSchedule(string $id)
    {
        $template = JobTemplate::findOrFail($id);
        
        $template->update([
            'is_scheduled' => false,
            'schedule_active' => false,
            'next_run_at' => null,
            'ends_at' => now(), // Close the window effectively
        ]);

        return response()->json(['message' => 'Schedule completely terminated.']);
    }
}