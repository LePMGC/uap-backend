<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Models\ProvisioningRequest;
use App\Modules\Operations\Services\ReimbursementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Modules\Operations\Transformers\ReimbursementResource;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;
use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Validation\Rule;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Services\BatchOrchestrator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReimbursementController extends Controller implements HasMiddleware
{
    /**
     * Get the middleware that should be assigned to the controller.
     * This fulfills the Illuminate\Routing\Controllers\HasMiddleware contract.
     */
    public static function middleware(): array
    {
        return [
            'auth:api',
            new \Illuminate\Routing\Controllers\Middleware('permission:view_all_reimbursements|view_own_reimbursements', only: ['index', 'show', 'stats']),
            new \Illuminate\Routing\Controllers\Middleware('permission:create_bulk_reimbursements', only: ['validateFile']),
            new \Illuminate\Routing\Controllers\Middleware('permission:create_single_reimbursement|create_bulk_reimbursements', only: ['store', 'uploadAttachment']),
            new \Illuminate\Routing\Controllers\Middleware('permission:view_all_reimbursements|view_own_reimbursements', only: ['getCreators', 'getReviewers']),
            // Note: We remove the hardcoded 'approve_reimbursements' wrapper here so we can evaluate specific tiers contextually inside the actions.
        ];
    }

    /**
     * Inject your operational service layer explicitly through constructor injection.
     */
    public function __construct(
        protected ReimbursementService $reimbursementService
    ) {
    }

    /**
     * POST /operations/reimbursements/validate-file
     */
    public function validateFile(Request $request): JsonResponse
    {
        $request->validate([
            'file'              => 'required|file|mimes:xlsx,csv,txt|max:10240', // Max 10MB protection limits
            'distribution_mode' => 'required|in:MANY_SINGLE,MANY_MANY', // Catch the UI structural target
        ]);

        // Forward both the file instance and the distribution mode configuration to the service tier
        $result = $this->reimbursementService->validateAndPresaveFile(
            $request->file('file'),
            $request->input('distribution_mode')
        );

        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * POST /operations/attachments/upload
     */
    public function uploadAttachment(Request $request): JsonResponse
    {
        // Log the incoming request
        \Illuminate\Support\Facades\Log::info('Raw Inbound Attachment Request Diagnostic', [
            'has_attachment_key' => $request->hasFile('attachment'),
            'all_input_keys'     => $request->keys(),
            'files_payload'      => $_FILES,
        ]);

        // Validate request
        $request->validate([
            'attachment' => 'required|file|mimes:pdf,png,jpg,jpeg|max:5120',
        ]);

        try {

            $file = $request->file('attachment');

            \Illuminate\Support\Facades\Log::info('Attachment details', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
                'is_valid'      => $file->isValid(),
                'error_code'    => $file->getError(),
            ]);

            // Store the file using the service
            $attachment = $this->reimbursementService->storeAttachment($file);

            // Verify that the file actually exists
            $exists = \Illuminate\Support\Facades\Storage::disk('reimbursement_attachments')
                ->exists($attachment['file_path']);

            \Illuminate\Support\Facades\Log::info('Attachment stored', [
                'exists'    => $exists,
                'disk_path' => \Illuminate\Support\Facades\Storage::disk('reimbursement_attachments')
                    ->path($attachment['file_path']),
                'data'      => $attachment,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attachment uploaded successfully.',
                'data'    => $attachment,
            ]);

        } catch (\Throwable $e) {

            \Illuminate\Support\Facades\Log::error('Attachment upload failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Attachment upload failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /operations/reimbursements
     */
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'ticket_id'          => 'required|string',
            'reimbursement_type' => 'required|in:BUNDLE,AIRTIME',
            'reimbursement_mode' => 'required|in:AUTO,MANUAL',
            'is_bulk'            => 'required|boolean',
            'msisdn'             => 'required_if:is_bulk,false|nullable|string',
            'distribution_mode'  => 'nullable|string|in:SINGLE_SINGLE,MANY_SINGLE,MANY_MANY',
            'target_product_id'  => [
                'nullable',
                'string',
                Rule::requiredIf(function () use ($request) {
                    // Required for BUNDLE unless distribution_mode is MANY_MANY
                    return $request->input('reimbursement_type') === 'BUNDLE'
                        && $request->input('distribution_mode') !== 'MANY_MANY';
                }),
            ],
            'amount'             => 'required_if:reimbursement_type,AIRTIME|nullable|numeric|min:0.01',
            'file_reference_id'  => 'required_if:is_bulk,true|nullable|string',
            'description'        => 'nullable|string',
            'attachment_ids'     => 'nullable|array',
        ]);

        $reimbursement = $this->reimbursementService->createReimbursement(
            $validatedData,
            auth()->id() ?? 1
        );

        return response()->json($reimbursement, 201);
    }

    /**
     * GET /operations/reimbursements
     * List with multi-tenant filtering based on view_all and view_own permission scopes.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->can('view_all_reimbursements') && !$user->can('view_own_reimbursements')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view reimbursement records.'
            ], 403);
        }

        // 1. Core query with subselect for provisioning_status
        $query = Reimbursement::select('reimbursements.*')
            ->addSelect([
                'latest_provisioning_status' => ProvisioningRequest::select('status')
                    ->whereColumn('reimbursement_id', 'reimbursements.id')
                    ->latest()
                    ->limit(1)
            ])
            ->with(['attachments', 'reviewer', 'requester']);

        /**
         * Permission scope
         */
        if (!$user->can('view_all_reimbursements')) {
            $query->where('requested_by_user_id', $user->id);
        }

        /**
         * Search
         */
        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('ticket_id', 'like', "%{$search}%")
                  ->orWhere('msisdn', 'like', "%{$search}%");
            });
        }

        /**
         * Dual-Group Status Filter Handling
         */
        if ($request->filled('status')) {
            $status = strtolower($request->input('status'));

            match ($status) {
                // Group 1: Workflow Statuses
                'pending', 'rejected', 'cancelled' => $query->where('status', $status),

                // Group 2: Approved (All)
                'approved' => $query->where('status', 'approved'),

                // Group 2: Fulfillment Statuses (Checked via subquery against ProvisioningRequest)
                'queued', 'success', 'failed' => $query->where('status', 'approved')
                    ->whereExists(function ($sub) use ($status) {
                        $sub->select(\DB::raw(1))
                            ->from('provisioning_requests')
                            ->whereColumn('provisioning_requests.reimbursement_id', 'reimbursements.id')
                            ->where('provisioning_requests.status', strtoupper($status));
                    }),

                default => null,
            };
        }

        /**
         * Standard filters (excluding 'status' which is handled above)
         */
        foreach ([
            'reimbursement_type',
            'reimbursement_mode',
            'required_tier',
            'msisdn'
        ] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        /**
         * Requester filter
         */
        if ($request->filled('created_by')) {
            $query->where(
                'requested_by_user_id',
                $request->input('created_by')
            );
        }

        /**
         * Reviewer filter
         */
        if ($request->filled('reviewed_by')) {
            $query->where(
                'reviewed_by_user_id',
                $request->input('reviewed_by')
            );
        }

        /**
         * Date range
         */
        if ($request->filled('created_at_start')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->input('created_at_start')
            );
        }

        if ($request->filled('created_at_end')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->input('created_at_end')
            );
        }

        $paginatedData = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => \App\Modules\Operations\Transformers\ReimbursementResource::collection($paginatedData)
                ->response()
                ->getData(true)
        ]);
    }

    /**
     * GET /api/operations/reimbursements/{id}
     */
    public function show(string $id): JsonResponse
    {
        $reimbursement = Reimbursement::with([
            'attachments',
            'requester',
            'reviewer',
            'bundle',
            // Load relationships required for summary and admin reference IDs
            'provisioningRequest.executionCommandLog',
            'provisioningRequest.executionJobInstance'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => new ReimbursementResource($reimbursement)
        ]);
    }

    /**
        * PUT /operations/reimbursements/{id}/approve
        */
    public function approve(Request $request, string $id): JsonResponse
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $user = auth()->user();

        // 1. Structural Guard Rule: Prevent Self-Reimbursement approvals
        if ($reimbursement->requested_by_user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Security Guard: You are explicitly forbidden from approving your own reimbursement requests.'
            ], 403);
        }

        // 2. Fetch the authoring user entity to determine their operating tier validation profile
        $requester = $reimbursement->requester; // Assumes relation or look-up exists. If not, use: \App\Modules\Core\UserManagement\Models\User::find($reimbursement->requested_by_user_id);

        // Contextual Fallback: If requester has tier2 role, require tier2 approval. Default to checking requester's role.
        $isRequesterTier2 = $requester ? $requester->hasPermissionTo('approve_tier2_reimbursements') : false;

        if ($isRequesterTier2) {
            if (!$user->hasPermissionTo('approve_tier2_reimbursements')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access Denied: You do not possess the approve_tier2_reimbursements permission required to verify this Tier 2 operator request.'
                ], 403);
            }
        } else {
            // Default assumes requester is a Tier 1 operator or baseline submitter
            if (!$user->hasPermissionTo('approve_tier1_reimbursements')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access Denied: You do not possess the approve_tier1_reimbursements permission required to verify this request.'
                ], 403);
            }
        }

        // Call the updated service processor
        $updatedRecord = $this->reimbursementService->approveReimbursement($reimbursement, $user->id);

        return response()->json([
            'success' => true,
            'data'    => new ReimbursementResource($updatedRecord)
        ]);
    }

    /**
     * PUT /operations/reimbursements/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $user = auth()->user();

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:255'
        ]);

        // 1. Structural Guard Rule: Prevent Self-Reimbursement rejections
        if ($reimbursement->requested_by_user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Security Guard: You cannot perform rejection audits on your own requests.'
            ], 403);
        }

        // 2. Determine required rights matching the requester's structural rank
        $requester = $reimbursement->requester;
        $isRequesterTier2 = $requester ? $requester->hasPermissionTo('approve_tier2_reimbursements') : false;

        if ($isRequesterTier2) {
            if (!$user->hasPermissionTo('approve_tier2_reimbursements')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access Denied: You require the approve_tier2_reimbursements permission to reject this Tier 2 operator request.'
                ], 403);
            }
        } else {
            if (!$user->hasPermissionTo('approve_tier1_reimbursements')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access Denied: You require the approve_tier1_reimbursements permission to reject this request.'
                ], 403);
            }
        }

        $updatedRecord = $this->reimbursementService->rejectReimbursement($reimbursement, $validated['rejection_reason'], $user->id);

        return response()->json([
            'success' => true,
            'data'    => new ReimbursementResource($updatedRecord)
        ]);
    }


    /**
     * GET /operations/reimbursements/download-template
     * Fetch template payload stream from backend based on structural constraints.
     */
    public function downloadTemplate(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $validated = $request->validate([
            'distribution_mode' => 'required|in:SINGLE_SINGLE,MANY_SINGLE,MANY_MANY',
            'format'            => 'required|in:xlsx,csv,txt',
        ]);

        $mode = strtolower($validated['distribution_mode']);
        $format = strtolower($validated['format']);

        // Build the absolute path to the target file
        $fileName = "reimbursement_{$mode}.{$format}";
        $filePath = storage_path("app/private/templates/{$fileName}");

        // Guard against missing files on the server disk
        if (!file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => "The requested template profile '{$fileName}' could not be located on the server system storage."
            ], 404);
        }

        // Map appropriate content headers based on the requested format
        $mimeTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv',
            'txt'  => 'text/plain',
        ];

        $headers = [
            'Content-Type'        => $mimeTypes[$format] ?? 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        // Stream the file back directly to the client as a binary payload chunk response
        return response()->download($filePath, $fileName, $headers);
    }


    /**
         * PUT /operations/reimbursements/{id}
         * Safely modify text parameters and add/remove evidence documents.
         */
    public function update(Request $request, string $id): JsonResponse
    {
        $reimbursement = Reimbursement::findOrFail($id);

        if ($reimbursement->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This request instance cannot be modified because it is no longer pending review.'
            ], 400);
        }

        $validated = $request->validate([
            'ticket_id'          => 'required|string|max:50',
            'description'        => 'nullable|string|max:255',
            'reimbursement_type' => 'required|string|in:BUNDLE,AIRTIME',
            'reimbursement_mode' => 'required|string|in:AUTO,MANUAL',
            'distribution_mode'  => 'nullable|string|in:SINGLE_SINGLE,MANY_SINGLE,MANY_MANY',
            'target_product_id'  => [
                'nullable',
                'string',
                Rule::requiredIf(function () use ($request) {
                    return $request->input('reimbursement_type') === 'BUNDLE'
                        && $request->input('distribution_mode') !== 'MANY_MANY';
                }),
            ],
            'amount'             => 'nullable|numeric|required_if:reimbursement_type,AIRTIME',
            'is_bulk'            => 'nullable|boolean',
            'file_reference_id'  => 'nullable|string|max:100',
            'attachment_ids'     => 'nullable|array',
            'attachment_ids.*'   => 'required|string',
        ]);

        $updatedRecord = $this->reimbursementService->updateReimbursement($reimbursement, $validated);

        return response()->json([
            'success' => true,
            'data'    => new ReimbursementResource($updatedRecord)
        ]);
    }

    /**
         * GET /operations/reimbursements/{id}/download-input-file
         * Stream the raw subscriber sheet in its actual native storage format.
         */
    public function downloadInputFile(string $id): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $reimbursement = Reimbursement::findOrFail($id);

        if (!$reimbursement->is_bulk || !$reimbursement->file_reference_id) {
            return response()->json([
                'success' => false,
                'message' => 'This reimbursement record is not a bulk transaction or does not contain an associated input file.'
            ], 400);
        }

        // Get relative disk path (e.g., "uploaded_sheets/VLT-REF-XXXX.csv")
        $relativeDiskPath = $reimbursement->getSecureDiskPath();

        if (!$relativeDiskPath || !Storage::disk('secure_reimbursements')->exists($relativeDiskPath)) {
            return response()->json([
                'success' => false,
                'message' => 'The physical subscriber file could not be found on secure server storage.'
            ], 404);
        }

        // Extract the exact native file extension directly from the storage path
        $extension = strtolower(pathinfo($relativeDiskPath, PATHINFO_EXTENSION));
        $absolutePath = Storage::disk('secure_reimbursements')->path($relativeDiskPath);

        // Map real content headers matching the native format on disk
        $mimeTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv',
            'txt'  => 'text/plain'
        ];

        // The dynamic file name sent to browser matching the true format
        $downloadName = "subscriber_list_{$reimbursement->file_reference_id}.{$extension}";

        $headers = [
            'Content-Type'        => $mimeTypes[$extension] ?? 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$downloadName}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
        ];

        return response()->download($absolutePath, $downloadName, $headers);
    }


    /**
         * GET /operations/reimbursements/stats
         */
    public function stats(): JsonResponse
    {
        $totals = Reimbursement::count();

        // 1. Count workflow statuses directly from the reimbursement table
        $pending   = Reimbursement::where('status', 'pending')->count();
        $rejected  = Reimbursement::where('status', 'rejected')->count();
        $cancelled = Reimbursement::where('status', 'cancelled')->count();

        // Helper subquery closure for fulfillment status counts
        $countByProvisioningStatus = function (string $provStatus) {
            return Reimbursement::where('status', 'approved')
                ->whereExists(function ($sub) use ($provStatus) {
                    $sub->select(\DB::raw(1))
                        ->from('provisioning_requests')
                        ->whereColumn('provisioning_requests.reimbursement_id', 'reimbursements.id')
                        ->where('provisioning_requests.status', strtoupper($provStatus));
                })->count();
        };

        // 2. Count fulfillment execution outcomes
        $success = $countByProvisioningStatus('SUCCESS');
        $failed  = $countByProvisioningStatus('FAILED');

        // 3. Approved count = approved reimbursements that are not yet marked as SUCCESS or FAILED
        $approved = Reimbursement::where('status', 'approved')
            ->whereDoesntHave('provisioningRequest', function ($sub) {
                $sub->whereIn('status', ['SUCCESS', 'FAILED']);
            })->count();

        // Calculate performance rate based on completed fulfillment requests
        $completedExecutions = $success + $failed;
        $successRate = $completedExecutions > 0
            ? round(($success / $completedExecutions) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $totals,
                'by_status' => [
                    'pending'   => $pending,
                    'approved'  => $approved,
                    'success'   => $success,
                    'rejected'  => $rejected,
                    'failed'    => $failed,
                    'cancelled' => $cancelled,
                ],
                'performance' => [
                    'success_rate' => $successRate,
                ]
            ]
        ]);
    }


    /**
     * PUT /operations/reimbursements/{id}/cancel
     * Terminate a pending reimbursement request.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $user = auth()->user();

        // 1. Lifecycle Guard Rule: Only pending requests can be aborted
        if ($reimbursement->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid State Transition: This request cannot be cancelled because it has already been processed.'
            ], 400);
        }

        // 2. Authorization Guard Matrix
        $isCreator = ($reimbursement->requested_by_user_id === $user->id);
        $isTier2Manager = $user->hasPermissionTo('approve_tier2_reimbursements');

        if (!$isCreator && !$isTier2Manager) {
            return response()->json([
                'success' => false,
                'message' => 'Access Denied: You are not authorized to cancel this reimbursement request.'
            ], 403);
        }

        // 3. Delegate execution safely to the service layer
        $updatedRecord = $this->reimbursementService->cancelReimbursement($reimbursement, $user->id);

        return response()->json([
            'success' => true,
            'message' => 'The reimbursement request has been cancelled successfully.',
            'data'    => new ReimbursementResource($updatedRecord)
        ]);
    }


    /**
     * GET /operations/reimbursements/creators
     *
     * Users allowed to create reimbursement requests.
     */
    public function getCreators(): JsonResponse
    {
        $users = User::permission([
            'create_single_reimbursement',
            'create_bulk_reimbursements',
        ])
        ->select([
            'id',
            'name'
        ])
        ->orderBy('name')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }


    /**
     * GET /operations/reimbursements/reviewers
     *
     * Users allowed to review/approve reimbursement requests.
     */
    public function getReviewers(): JsonResponse
    {
        $users = User::permission([
            'approve_tier1_reimbursements',
            'approve_tier2_reimbursements'
        ])
        ->select([
            'id',
            'name'
        ])
        ->orderBy('name')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }


    /**
        * GET /api/operations/reimbursements/{id}/download-report
        * Generate and stream high-level execution CSV report for non-technical operations users.
    */
    public function downloadProvisioningReport(string $id): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $reimbursement = Reimbursement::with([
            'bundle',
            'provisioningRequest.executionCommandLog',
            'provisioningRequest.executionJobInstance'
        ])->findOrFail($id);

        $provRequest = $reimbursement->provisioningRequest;

        if (!$provRequest) {
            return response()->json([
                'success' => false,
                'message' => 'No provisioning request is linked to this reimbursement yet.'
            ], 404);
        }

        $filename = "reimbursement_report_{$reimbursement->ticket_id}.csv";

        return response()->streamDownload(function () use ($reimbursement, $provRequest) {
            $handle = fopen('php://output', 'w');

            // Output CSV Headers
            fputcsv($handle, [
                'MSISDN',
                'Reimbursement Type',
                'Item / Amount',
                'Status',
                'Message'
            ]);

            $defaultItem = $reimbursement->reimbursement_type === 'BUNDLE'
                ? ($reimbursement->bundle?->name ?? 'Bundle')
                : ($reimbursement->amount . ' FCFA');

            // CASE 1: Single Reimbursement Execution
            if ($provRequest->execution_type === 'COMMAND') {
                $log = $provRequest->executionCommandLog;
                $status = $log ? ($log->is_successful ? 'SUCCESS' : 'FAILED') : 'PENDING';
                $error = $log && !$log->is_successful
                    ? ($log->response_payload['error'] ?? $log->getErrorMessage() ?? 'Execution Failed')
                    : 'Processed Successfully';

                fputcsv($handle, [
                    $reimbursement->msisdn ?? 'N/A',
                    $reimbursement->reimbursement_type,
                    $defaultItem,
                    $status,
                    $error
                ]);
            }
            // CASE 2: Bulk Reimbursement Execution (BATCH)
            elseif ($provRequest->execution_type === 'BATCH') {
                $jobInstance = $provRequest->executionJobInstance;

                if ($reimbursement->is_bulk && $reimbursement->file_reference_id) {
                    $rows = $reimbursement->getUploadedRows();

                    // Load execution results map from Batch storage if instance exists
                    $successMsisdns = [];
                    $failedMsisdns  = [];

                    if ($jobInstance) {
                        $batchStorageDir = config('connectors.batch.storage_path', 'jobs') . "/{$jobInstance->id}";

                        // 1. Read successful execution records
                        $successPath = "{$batchStorageDir}/results_success.csv";
                        if (Storage::exists($successPath)) {
                            $csv = \League\Csv\Reader::createFromPath(Storage::path($successPath), 'r');
                            $csv->setHeaderOffset(0);
                            foreach ($csv->getRecords() as $record) {
                                // Capture MSISDN or primary identifier
                                $key = $record['msisdn'] ?? $record['MSISDN'] ?? reset($record);
                                if ($key) {
                                    $successMsisdns[trim($key)] = $record['message'] ?? $record['response'] ?? 'Command executed successfully';
                                }
                            }
                        }

                        // 2. Read failed execution records
                        $failedPath = "{$batchStorageDir}/results_failed.csv";
                        if (Storage::exists($failedPath)) {
                            $csv = \League\Csv\Reader::createFromPath(Storage::path($failedPath), 'r');
                            $csv->setHeaderOffset(0);
                            foreach ($csv->getRecords() as $record) {
                                $key = $record['msisdn'] ?? $record['MSISDN'] ?? reset($record);
                                if ($key) {
                                    $failedMsisdns[trim($key)] = $record['error'] ?? $record['message'] ?? $record['reason'] ?? 'Command execution failed';
                                }
                            }
                        }
                    }

                    // Loop through input sheet rows and match against command outcomes
                    foreach ($rows as $row) {
                        $msisdn = trim($row['msisdn'] ?? '');
                        $item = ($reimbursement->distribution_mode === 'MANY_MANY' && !empty($row['value']))
                            ? $row['value']
                            : $defaultItem;

                        // Determine actual row-level command execution status
                        if (isset($failedMsisdns[$msisdn])) {
                            $status = 'FAILED';
                            $msg = $failedMsisdns[$msisdn];
                        } elseif (isset($successMsisdns[$msisdn])) {
                            $status = 'SUCCESS';
                            $msg = $successMsisdns[$msisdn];
                        } else {
                            // Fallback if batch was overall successful or still pending
                            if ($jobInstance && $jobInstance->status === 'COMPLETED') {
                                $status = 'SUCCESS';
                                $msg = 'Command executed successfully';
                            } elseif ($jobInstance && $jobInstance->status === 'FAILED') {
                                $status = 'FAILED';
                                $msg = 'Batch execution failed';
                            } else {
                                $status = 'PENDING';
                                $msg = 'Awaiting execution';
                            }
                        }

                        fputcsv($handle, [
                            $msisdn,
                            $reimbursement->reimbursement_type,
                            $item,
                            $status,
                            $msg
                        ]);
                    }
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'  => 'text/csv',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }
}
