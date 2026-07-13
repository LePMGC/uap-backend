<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\ProvisioningProfile;
use App\Modules\Operations\Http\Requests\StoreProvisioningProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Modules\Operations\Http\Requests\ProvisioningProfileStatusRequest;

class ProvisioningProfileController extends Controller
{
    /**
     * Controller middleware definitions.
     */
    public static function middleware(): array
    {
        return [
            'auth:api',
            new \Illuminate\Routing\Controllers\Middleware(
                'permission:view_provisioning_profiles',
                only: ['index', 'show']
            ),
            new \Illuminate\Routing\Controllers\Middleware(
                'permission:create_provisioning_profiles',
                only: ['store']
            ),
            new \Illuminate\Routing\Controllers\Middleware(
                'permission:edit_provisioning_profiles',
                only: ['update', 'updateStatus']
            ),
            new \Illuminate\Routing\Controllers\Middleware(
                'permission:delete_provisioning_profiles',
                only: ['destroy']
            ),
        ];
    }


    /**
     * Display provisioning profiles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProvisioningProfile::with([
            'providerInstance:id,name,system_key',
            'command:id,name,system_key',
            'debitCommand:id,name,system_key',
            'fundingAccount:id,name,msisdn'
        ]);


        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(
                'name',
                'like',
                "%{$search}%"
            );
        }


        foreach ([
            'reimbursement_type',
            'catalog_product_type',
            'provider_instance_id',
            'funding_account_id',
            'execution_mode',
            'is_active'
        ] as $filter) {

            if ($request->filled($filter)) {
                $query->where(
                    $filter,
                    $request->input($filter)
                );
            }
        }


        $paginatedData = $query
            ->orderBy('created_at', 'desc')
            ->paginate(
                $request->input('per_page', 10)
            );


        return response()->json([
            'success' => true,
            'data'    => $paginatedData
        ]);
    }


    /**
     * Create provisioning profile.
     */
    public function store(StoreProvisioningProfileRequest $request): JsonResponse
    {
        $profile = DB::transaction(function () use ($request) {

            $validated = $request->validated();


            /*
             * Only one active provisioning profile
             * is allowed per reimbursement type + product type.
             *
             * Examples:
             *
             * BUNDLE + DATA  -> one active profile
             * BUNDLE + VOICE -> one active profile
             * AIRTIME        -> one active profile
             */
            if ($request->boolean('is_active', true)) {

                $this->deactivateExistingProfiles(
                    $validated['reimbursement_type'],
                    $validated['catalog_product_type'] ?? null
                );
            }


            return ProvisioningProfile::create($validated);
        });


        return response()->json([
            'success' => true,
            'message' => 'Provisioning profile created successfully.',
            'data'    => $profile->load([
                'providerInstance',
                'command',
                'debitCommand',
                'fundingAccount'
            ])
        ], 201);
    }


    /**
     * Display a single provisioning profile.
     */
    public function show(
        ProvisioningProfile $provisioningProfile
    ): JsonResponse {

        return response()->json([
            'success' => true,
            'data' => $provisioningProfile->load([
                'providerInstance',
                'command',
                'debitCommand',
                'fundingAccount'
            ])
        ]);
    }


    /**
     * Update provisioning profile.
     */
    public function update(
        StoreProvisioningProfileRequest $request,
        ProvisioningProfile $provisioningProfile
    ): JsonResponse {

        DB::transaction(function () use (
            $request,
            $provisioningProfile
        ) {

            $validated = $request->validated();


            if ($request->boolean('is_active')) {

                $this->deactivateExistingProfiles(
                    $validated['reimbursement_type'],
                    $validated['catalog_product_type'] ?? null,
                    $provisioningProfile->id
                );
            }


            $provisioningProfile->update($validated);
        });


        return response()->json([
            'success' => true,
            'message' => 'Provisioning profile configuration updated safely.',
            'data' => $provisioningProfile->fresh([
                'providerInstance',
                'command',
                'debitCommand',
                'fundingAccount'
            ])
        ]);
    }


    /**
     * Delete provisioning profile.
     */
    public function destroy(
        ProvisioningProfile $provisioningProfile
    ): JsonResponse {

        try {

            $provisioningProfile->delete();


            return response()->json([
                'success' => true,
                'message' =>
                    'Provisioning rule successfully removed from active configurations.'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }


    /**
     * Disable existing active profiles
     * having the same provisioning scope.
     */
    private function deactivateExistingProfiles(
        string $reimbursementType,
        ?array $catalogProductTypes,
        ?int $exceptId = null
    ): void {

        $query = ProvisioningProfile::where(
            'reimbursement_type',
            $reimbursementType
        );


        if (!empty($catalogProductTypes)) {

            foreach ($catalogProductTypes as $type) {

                $query->orWhereJsonContains(
                    'catalog_product_types',
                    $type
                );
            }

        } else {

            $query->whereNull(
                'catalog_product_types'
            );
        }


        if ($exceptId) {
            $query->where(
                'id',
                '!=',
                $exceptId
            );
        }


        $query->update([
            'is_active' => false
        ]);
    }

    /**
     * Update Status
     */
    public function updateStatus(
        ProvisioningProfileStatusRequest $request,
        ProvisioningProfile $provisioningProfile
    ): JsonResponse {

        DB::transaction(function () use (
            $request,
            $provisioningProfile
        ) {

            $isActive = $request->boolean('is_active');


            if ($isActive) {

                $this->deactivateExistingProfiles(
                    $provisioningProfile->reimbursement_type,
                    $provisioningProfile->catalog_product_type,
                    $provisioningProfile->id
                );
            }


            $provisioningProfile->update([
                'is_active' => $isActive
            ]);
        });


        return response()->json([
            'success' => true,
            'message' => $request->boolean('is_active')
                ? 'Provisioning profile activated successfully.'
                : 'Provisioning profile deactivated successfully.',
            'data' => $provisioningProfile->fresh([
                'providerInstance',
                'command',
                'debitCommand',
                'fundingAccount'
            ])
        ]);
    }
}
