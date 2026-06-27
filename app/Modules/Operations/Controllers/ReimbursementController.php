<?php

namespace App\Modules\Operations\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Services\ReimbursementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Modules\Operations\Transformers\ReimbursementResource;

class ReimbursementController extends Controller
{
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
            'file' => 'required|file|mimes:xlsx,csv,txt|max:10240',
        ]);

        $result = $this->reimbursementService->validateAndPresaveFile($request->file('file'));

        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * POST /operations/attachments/upload
     */
    public function uploadAttachment(Request $request): JsonResponse
    {
        $request->validate([
            'attachment' => 'required|file|mimes:pdf,png,jpg,jpeg|max:5120',
        ]);

        $attachmentData = $this->reimbursementService->storeAttachment($request->file('attachment'));

        return response()->json($attachmentData);
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
            'attachment_ids' => 'nullable|array'
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
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        // 1. Enforce strict baseline permission check
        if (!$user->can('view_all_reimbursements') && !$user->can('view_own_reimbursements')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view reimbursement records.'
            ], 403);
        }

        $query = Reimbursement::with(['attachments', 'requester', 'approver']);

        // 2. Multi-Tenant Query Scoping
        // If the user can't look globally, restrict query explicitly to their own authored requests
        if (!$user->can('view_all_reimbursements')) {
            $query->where('requested_by_user_id', $user->id);
        }

        // 3. Apply Text or Exact Target Filters
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ticket_id', 'like', "%{$search}%")
                  ->orWhere('msisdn', 'like', "%{$search}%");
            });
        }

        // Apply strict column drop-down filters
        foreach (['status', 'reimbursement_type', 'reimbursement_mode', 'required_tier', 'msisdn'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        // Historical chronological boundaries
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
}
