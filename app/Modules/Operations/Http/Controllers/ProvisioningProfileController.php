<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\ProvisioningProfile;
use App\Modules\Operations\Http\Requests\StoreProvisioningProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProvisioningProfileController extends Controller
{
    /**
     * Get the middleware that should be assigned to the controller.
     * This fulfills the Illuminate\Routing\Controllers\HasMiddleware contract.
    */
    public static function middleware(): array
    {
        return [
            'auth:api',
            new \Illuminate\Routing\Controllers\Middleware('permission:view_provisioning_profiles', only: ['index', 'show']),
            new \Illuminate\Routing\Controllers\Middleware('permission:create_provisioning_profiles', only: ['store']),
            new \Illuminate\Routing\Controllers\Middleware('permission:update_provisioning_profiles', only: ['update']),
            new \Illuminate\Routing\Controllers\Middleware('permission:delete_provisioning_profiles', only: ['destroy']),
        ];
    }

    /**
     * Display a scannable listing of profiles with their associated configuration maps.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProvisioningProfile::with([
            'providerInstance:id,name,system_key',
            'command:id,name,system_key',
            'debitCommand:id,name,system_key',
            'fundingAccount:id,name,msisdn'
        ]);

        /**
         * Search criteria (Partial matching on name tracking key string)
         */
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        /**
         * Direct matching system configuration attributes
         */
        foreach ([
            'reimbursement_type',
            'provider_instance_id',
            'funding_account_id',
            'execution_mode',
            'is_active'
        ] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $paginatedData = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data'    => $paginatedData
        ]);
    }

    /**
     * Store a new structural provisioning strategy configuration strategy safely.
     */
    public function store(StoreProvisioningProfileRequest $request): JsonResponse
    {
        $profile = DB::transaction(function () use ($request) {
            $validated = $request->validated();

            // Guardrail: If this profile is active, deactivate other profiles of the same type
            if ($request->boolean('is_active', true)) {
                ProvisioningProfile::where('reimbursement_type', $validated['reimbursement_type'])
                    ->update(['is_active' => false]);
            }

            return ProvisioningProfile::create($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Provisioning profile created successfully.',
            'data'    => $profile->load(['providerInstance', 'command', 'fundingAccount'])
        ], 201);
    }

    /**
     * Show detailed analytical information regarding a specific routing signature mapping.
     */
    public function show(ProvisioningProfile $provisioningProfile): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $provisioningProfile->load([
                'providerInstance',
                'command',
                'debitCommand',
                'fundingAccount'
            ])
        ]);
    }

    /**
     * Update an existing system strategy matrix safely using a transaction block.
     */
    public function update(StoreProvisioningProfileRequest $request, ProvisioningProfile $provisioningProfile): JsonResponse
    {
        DB::transaction(function () use ($request, $provisioningProfile) {
            $validated = $request->validated();

            // Guardrail: If turning active, auto-deactivate old entries of the same type
            if ($request->boolean('is_active')) {
                ProvisioningProfile::where('reimbursement_type', $validated['reimbursement_type'])
                    ->where('id', '!=', $provisioningProfile->id)
                    ->update(['is_active' => false]);
            }

            $provisioningProfile->update($validated);
        });

        return response()->json([
            'success' => true,
            'message' => 'Provisioning profile configuration updated safely.',
            'data'    => $provisioningProfile->fresh(['providerInstance', 'command', 'fundingAccount'])
        ]);
    }

    /**
     * Soft delete an execution schema safely.
     */
    public function destroy(ProvisioningProfile $provisioningProfile): JsonResponse
    {
        try {
            // Evaluates booted() exception rules if marked as system infrastructure[cite: 3]
            $provisioningProfile->delete();

            return response()->json([
                'success' => true,
                'message' => 'Provisioning rule successfully removed from the active system configurations pool.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
