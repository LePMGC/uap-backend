<?php

namespace App\Modules\Operations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Services\ReimbursementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Modules\Operations\Transformers\ReimbursementResource;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;

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
            new \Illuminate\Routing\Controllers\Middleware('permission:create_single_reimbursements|create_bulk_reimbursements', only: ['store', 'uploadAttachment']),
            new \Illuminate\Routing\Controllers\Middleware('permission:approve_reimbursements', only: ['approve', 'reject']),
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
            'ticket_id' => 'required|string',
            'reimbursement_type' => 'required|in:BUNDLE,AIRTIME',
            'reimbursement_mode' => 'required|in:AUTO,MANUAL',
            'is_bulk' => 'required|boolean',
            'msisdn' => 'required_if:is_bulk,false|nullable|string',
            'target_product_id' => 'required_if:reimbursement_type,BUNDLE|nullable|string',
            'amount' => 'required_if:reimbursement_type,AIRTIME|nullable|numeric|min:0.01',
            'file_reference_id' => 'required_if:is_bulk,true|nullable|string',
            'description' => 'nullable|string',
            'attachment_ids' => 'nullable|array',
            'distribution_mode' => 'nullable|string|in:SINGLE_SINGLE,MANY_SINGLE,MANY_MANY',
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

        $query = Reimbursement::with(['attachments', 'requester', 'approver']);

        if (!$user->can('view_all_reimbursements')) {
            $query->where('requested_by_user_id', $user->id);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ticket_id', 'like', "%{$search}%")
                  ->orWhere('msisdn', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'reimbursement_type', 'reimbursement_mode', 'required_tier', 'msisdn'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('created_at_start')) {
            $query->whereDate('created_at', '>=', $request->input('created_at_start'));
        }
        if ($request->filled('created_at_end')) {
            $query->whereDate('created_at', '<=', $request->input('created_at_end'));
        }

        $paginatedData = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data'    => \App\Modules\Operations\Transformers\ReimbursementResource::collection($paginatedData)
                ->response()
                ->getData(true)
        ]);
    }

    /**
     * GET /operations/reimbursements/{id}
     * Inspect a specific adjustment profile record with tenancy guarding rules.
     */
    public function show($id): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        // 1. Enforce baseline permission check
        if (!$user->can('view_all_reimbursements') && !$user->can('view_own_reimbursements')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view reimbursement ledger details.'
            ], 403);
        }

        // 2. Hydrate model from database with relations
        $reimbursement = Reimbursement::with(['attachments', 'bulkErrors', 'requester', 'approver'])
            ->findOrFail($id);

        // 3. Explicit Ownership Tenancy Validation
        // Fail if they lack global access and the request was authored by someone else
        if (!$user->can('view_all_reimbursements') && $reimbursement->requested_by_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access: You are not permitted to inspect reimbursement records authored by other operators.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => new \App\Modules\Operations\Transformers\ReimbursementResource($reimbursement)
        ]);
    }

    /**
     * POST /operations/reimbursements/{id}/approve
     */
    public function approve($id): JsonResponse
    {
        $reimbursement = Reimbursement::findOrFail($id);

        if ($reimbursement->status !== 'pending') {
            return response()->json(['message' => 'This request instance is not pending review.'], 400);
        }

        $reimbursement->update([
            'status' => $reimbursement->reimbursement_mode === 'AUTO' ? 'success' : 'approved',
            'approved_by_user_id' => auth()->id() ?? 1
        ]);

        return response()->json(['success' => true, 'data' => $reimbursement]);
    }

    /**
     * POST /operations/reimbursements/{id}/reject
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:5']);
        $reimbursement = Reimbursement::findOrFail($id);

        if ($reimbursement->status !== 'pending') {
            return response()->json(['message' => 'This request instance is not pending review.'], 400);
        }

        $reimbursement->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'approved_by_user_id' => auth()->id() ?? 1
        ]);

        return response()->json(['success' => true, 'data' => $reimbursement]);
    }

    /**
     * GET /operations/reimbursements/stats
     */
    public function stats(): JsonResponse
    {
        $totals = Reimbursement::count();
        $byStatus = [
            'pending' => Reimbursement::where('status', 'pending')->count(),
            'approved' => Reimbursement::where('status', 'approved')->count(),
            'success' => Reimbursement::where('status', 'success')->count(),
            'rejected' => Reimbursement::where('status', 'rejected')->count(),
            'failed' => Reimbursement::where('status', 'failed')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $totals,
                'by_status' => $byStatus,
                'performance' => [
                    'success_rate' => $totals > 0 ? round((Reimbursement::where('status', 'success')->count() / $totals) * 100, 2) : 100
                ]
            ]
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

        // Guard rule: Block modifications on already audited/processed entries
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
            'target_product_id'  => 'nullable|string|required_if:reimbursement_type,BUNDLE',
            'amount'             => 'nullable|numeric|required_if:reimbursement_type,AIRTIME',
            'is_bulk'            => 'nullable|boolean',
            'file_reference_id'  => 'nullable|string|max:100',
            'attachment_ids'     => 'nullable|array',
            'attachment_ids.*'   => 'required|string',
            'distribution_mode' => 'nullable|string|in:SINGLE_SINGLE,MANY_SINGLE,MANY_MANY'
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
}
