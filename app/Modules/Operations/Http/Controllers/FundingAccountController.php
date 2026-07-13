<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\FundingAccount;
use App\Modules\Operations\Http\Requests\StoreFundingAccountRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Modules\Operations\Http\Requests\ProvisioningProfileStatusRequest;
use App\Modules\Operations\Http\Requests\UpdateFundingAccountStatusRequest;

class FundingAccountController extends Controller
{
    /**
     * Get the middleware that should be assigned to the controller.
     * This fulfills the Illuminate\Routing\Controllers\HasMiddleware contract.
    */
    public static function middleware(): array
    {
        return [
            'auth:api',
            new \Illuminate\Routing\Controllers\Middleware('permission:view_funding_accounts', only: ['index', 'show']),
            new \Illuminate\Routing\Controllers\Middleware('permission:create_funding_accounts', only: ['store']),
            new \Illuminate\Routing\Controllers\Middleware('permission:edit_funding_accounts', only: ['update', 'updateStatus']),
            new \Illuminate\Routing\Controllers\Middleware('permission:delete_funding_accounts', only: ['destroy']),
        ];
    }

    /**
     * Display a filtered, paginated listing of system funding wallets.
     */
    public function index(Request $request): JsonResponse
    {
        // start query builder without provider instance relationship to avoid N+1 query issues
        $query = FundingAccount::query();

        /**
         * Search criteria (Partial matching on name or MSISDN)
         */
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('msisdn', 'like', "%{$search}%");
            });
        }

        /**
         * Standard relational and status filters
         */
        foreach (['provider_instance_id', 'is_active'] as $filter) {
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
     * Store a new system source wallet mapping.
     */
    public function store(StoreFundingAccountRequest $request): JsonResponse
    {
        $account = FundingAccount::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'System funding account registered successfully.',
            'data'    => $account->load('providerInstance')
        ], 201);
    }

    /**
     * Display structural data for an individual funding wallet.
     */
    public function show(FundingAccount $fundingAccount): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $fundingAccount
        ]);
    }

    /**
     * Update active configurations on a physical system account tracking index.
     */
    public function update(StoreFundingAccountRequest $request, FundingAccount $fundingAccount): JsonResponse
    {
        $fundingAccount->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'System funding account modified successfully.',
            'data'    => $fundingAccount,
        ]);
    }

    /**
     * Deactivate or restrict a system wallet asset mapping.
     */
    public function destroy(FundingAccount $fundingAccount): JsonResponse
    {
        // Enforce Restrict constraints: block deletion if mapped to any existing profiling structures
        if ($fundingAccount->provisioningProfiles()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Integrity constraint violation: This wallet is actively utilized by an existing provisioning profile policy.'
            ], 422);
        }

        $fundingAccount->delete();

        return response()->json([
            'success' => true,
            'message' => 'Funding asset account successfully removed from service configurations tracking.'
        ]);
    }


    public function updateStatus(
        UpdateFundingAccountStatusRequest $request,
        FundingAccount $fundingAccount
    ): JsonResponse {

        $fundingAccount->update([
            'is_active' => $request->boolean('is_active')
        ]);


        return response()->json([
            'success' => true,
            'message' =>
                'Funding account status updated successfully.',
            'data' => $fundingAccount->fresh()
        ]);
    }
}
