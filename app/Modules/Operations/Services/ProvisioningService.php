<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Models\ProvisioningProfile;
use App\Modules\Operations\Models\ProvisioningRequest;
use App\Modules\Operations\Models\CatalogProduct;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Services\BatchOrchestrator;
use App\Modules\Operations\Exceptions\ProvisioningException;
use Illuminate\Support\Str;
use App\Modules\Operations\Jobs\ProcessProvisioningRequestJob;
use Illuminate\Support\Facades\Log;

class ProvisioningService
{
    public function __construct(
        protected CommandExecutor $commandExecutor,
        protected BatchOrchestrator $batchOrchestrator,
        protected BulkProvisioningBuilder $bulkBuilder
    ) {
    }

    /**
     * Core Entrypoint invoked immediately following a reimbursement's approval.[cite: 2]
     */
    public function dispatchProvisioning(Reimbursement $reimbursement): void
    {
        try {

            if ($reimbursement->is_bulk && $reimbursement->distribution_mode === 'MANY_MANY') {
                $profile = ProvisioningProfile::where('reimbursement_type', $reimbursement->reimbursement_type)
                    ->where('is_active', true)
                    ->firstOrFail();
            } else {
                $profileQuery = ProvisioningProfile::query()
                ->where('reimbursement_type', $reimbursement->reimbursement_type)
                ->whereRaw('is_active IS TRUE')
                ->with('fundingAccount');

                // If a product is attached, match profiles by the product's type (case-insensitive)
                if ($reimbursement->target_product_id) {
                    $catalogProduct = $reimbursement->bundle
                        ?? CatalogProduct::find($reimbursement->target_product_id);

                    if ($catalogProduct && !empty($catalogProduct->type)) {
                        $productType = strtolower($catalogProduct->type);

                        // Case-insensitive lookup inside the JSON array
                        $profileQuery->whereRaw("
                    EXISTS (
                        SELECT 1 
                        FROM jsonb_array_elements_text(catalog_product_types::jsonb) AS elem 
                        WHERE LOWER(elem) = ?
                    )
                    ", [$productType]);
                    }
                }

                $profile = $profileQuery->first();

                if (!$profile) {
                    throw new ProvisioningException(
                        "No active provisioning profile configured for {$reimbursement->reimbursement_type}"
                    );
                }
            }


            $request = ProvisioningRequest::firstOrCreate(
                ['reimbursement_id' => $reimbursement->id],
                [
                    'profile_id'       => $profile->id,
                    'status'           => 'PENDING',
                    'execution_type'   => $reimbursement->is_bulk ? 'BATCH' : 'COMMAND',
                    'execution_step'   => $reimbursement->is_bulk ? 'SUBMIT_BATCH' : 'START',
                    'funding_strategy' => $profile->getFundingStrategy(), // 👈 Resolves 'PROVISIONING_PROVIDER' or 'SEPARATE_DEBIT_PROVIDER'
                ]
            );

            // Forward execution safely to the asynchronous worker infrastructure
            ProcessProvisioningRequestJob::dispatch($request->id);

            // Update parent operational context tracking status
            // $reimbursement->update(['provisioning_status' => 'QUEUED']);

        } catch (\Throwable $e) {
            Log::error(
                "Provisioning dispatch failed",
                [
                    'reimbursement_id' => $reimbursement->id,
                    'error'            => $e->getMessage()
                ]
            );

            // $reimbursement->update(['provisioning_status' => 'FAILED']);
            throw $e;
        }
    }

    /**
     * Single subscriber provisioning (via CommandExecutor)
     */
    protected function processSingle(
        Reimbursement $reimb,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): void {
        $traceId = request()->header('X-Request-ID') ?? (string) Str::uuid();
        $actingUserId = $reimb->reviewed_by_user_id ?? $reimb->requested_by_user_id ?? 0;

        try {
            /*
            |--------------------------------------------------------------------------
            | AIRTIME PROVISIONING FLOW
            |--------------------------------------------------------------------------
            */
            if ($reimb->reimbursement_type === 'AIRTIME') {
                $debitProviderId = $profile->debit_using_provisioning_provider
                    ? $profile->provisioning_provider_instance_id
                    : $profile->debit_provider_instance_id;

                if (!$debitProviderId || !$profile->debit_command_id) {
                    throw new ProvisioningException("Airtime profile is missing debit parameters.");
                }

                // Step 1: Debit funding account
                $debitLog = $this->commandExecutor->execute(
                    $debitProviderId,
                    $profile->debit_command_id,
                    [
                        'msisdn' => $profile->fundingAccount->msisdn,
                        'amount' => $reimb->amount
                    ],
                    $actingUserId,
                    null,
                    $traceId,
                    'form'
                );

                // Always track the debit log on the request regardless of success/failure
                $request->update([
                    'debit_command_log_id' => $debitLog->id,
                ]);

                if (!$debitLog->is_successful) {
                    $this->failed(
                        $request,
                        $reimb,
                        "Debit failed: " . ($debitLog->response_payload['message'] ?? 'Unknown error')
                        // 👈 Leave 4th arg empty so execution_command_log_id remains null (credit never ran)
                    );
                    return;
                }

                $request->update([
                    'execution_step' => 'CREDIT_SUBSCRIBER'
                ]);

                // Step 2: Credit target subscriber
                $creditLog = $this->commandExecutor->execute(
                    $profile->provisioning_provider_instance_id,
                    $profile->provisioning_command_id,
                    [
                        'msisdn' => $reimb->msisdn,
                        'amount' => $reimb->amount
                    ],
                    $actingUserId,
                    null,
                    $traceId,
                    'form'
                );

                if (!$creditLog->is_successful) {
                    $this->failed(
                        $request,
                        $reimb,
                        "Credit failed: " . ($creditLog->response_payload['message'] ?? 'Unknown error'),
                        $creditLog->id // 👈 Attach credit log ID
                    );
                    return;
                }

                $this->success($request, $reimb, $creditLog->id);
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | BUNDLE PROVISIONING FLOW
            |--------------------------------------------------------------------------
            */
            if ($reimb->reimbursement_type === 'BUNDLE') {
                $activationLog = $this->commandExecutor->execute(
                    $profile->provisioning_provider_instance_id,
                    $profile->provisioning_command_id,
                    [
                        'MSISDN'   => $profile->fundingAccount->msisdn, // Funding account / Requester MSISDN
                        'bNumber'  => $reimb->msisdn,                  // Target recipient MSISDN
                        'offerId'  => $reimb->target_product_id         // Target product / offer ID
                    ],
                    $actingUserId,
                    null,
                    $traceId,
                    'form'
                );

                if (!$activationLog->is_successful) {
                    $this->failed(
                        $request,
                        $reimb,
                        "Bundle activation failed: " . ($activationLog->response_payload['message'] ?? 'Unknown error'),
                        $activationLog->id
                    );
                    return;
                }

                $this->success($request, $reimb, $activationLog->id);
                return;
            }

            throw new ProvisioningException("Unsupported reimbursement type: {$reimb->reimbursement_type}");

        } catch (\Throwable $e) {
            $this->failed($request, $reimb, "System Exception during Single Provisioning: " . $e->getMessage());
        }
    }

    /**
         * Bulk provisioning orchestration (Delegated clean construction strategy)
         */
    protected function processBulk(
        Reimbursement $reimb,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): void {
        try {
            $traceId = request()->header('X-Request-ID') ?? (string) Str::uuid();

            // 1. Mark ProvisioningRequest as RUNNING
            $request->update([
                'status' => 'RUNNING',
                'execution_step' => 'PROCESSING_BATCH',
            ]);

            // Delegate building/readiness/persistence to the specialized builder
            $instance = $this->bulkBuilder->build($reimb, $profile, $request);

            // Execute batch processing using orchestrator engine
            $this->batchOrchestrator->execute($instance, $traceId);

        } catch (\Throwable $e) {
            $this->failed($request, $reimb, "System Exception during Bulk Provisioning: " . $e->getMessage());
        }
    }


    public function executeProvisioningRequest(ProvisioningRequest $request): void
    {
        $reimbursement = $request->reimbursement;
        $profile = $request->profile;

        if ($request->execution_type === 'COMMAND') {
            $this->processSingle($reimbursement, $profile, $request);
            return;
        }

        if ($request->execution_type === 'BATCH') {
            $this->processBulk($reimbursement, $profile, $request);
            return;
        }

        throw new ProvisioningException("Unsupported execution type {$request->execution_type}");
    }

    protected function success(ProvisioningRequest $request, Reimbursement $reimb, string $executionId): void
    {
        $request->update([
            'status'                   => 'SUCCESS',
            'execution_command_log_id' => $executionId,
            'execution_step'           => 'COMPLETED',
            'completed_at'             => now()
        ]);

        // $reimb->update(['provisioning_status' => 'SUCCESS']);
    }

    protected function failed(
        ProvisioningRequest $request,
        Reimbursement $reimb,
        string $message,
        ?string $commandLogId = null
    ): void {
        // 1. Log to system logs
        \Illuminate\Support\Facades\Log::error("Provisioning Request Failed [Request #{$request->id} | Reimbursement #{$reimb->id}]: {$message}");

        // 2. Persist state on ProvisioningRequest
        $request->update([
            'status'                   => 'FAILED',
            'error_message'            => $message,
            'execution_command_log_id' => $commandLogId ?? $request->execution_command_log_id,
            'completed_at'             => now()
        ]);
    }
}
