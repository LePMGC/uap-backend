<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Models\ProvisioningProfile;
use App\Modules\Operations\Models\ProvisioningRequest;
use App\Modules\Connectors\Services\CommandExecutor;
use App\Modules\Connectors\Services\BatchOrchestrator;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\JobInstance;
use App\Exceptions\ProvisioningException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProvisioningService
{
    public function __construct(
        protected CommandExecutor $commandExecutor,
        protected BatchOrchestrator $batchOrchestrator
    ) {
    }

    /**
     * Core Entrypoint invoked immediately following a reimbursement's approval.[cite: 2]
     */
    public function dispatchProvisioning(Reimbursement $reimbursement): void
    {
        $profile = ProvisioningProfile::query()
            ->where('reimbursement_type', $reimbursement->reimbursement_type)
            ->where('is_active', true)
            ->with('fundingAccount')
            ->first();

        if (!$profile) {
            throw new ProvisioningException(
                "No active provisioning profile configured for {$reimbursement->reimbursement_type}"
            );
        }

        $request = ProvisioningRequest::create([
            'reimbursement_id' => $reimbursement->id,
            'profile_id'       => $profile->id,
            'status'           => 'PENDING',
            'execution_type'   => $reimbursement->is_bulk ? 'BATCH' : 'COMMAND',
            'execution_step'   => $reimbursement->is_bulk ? 'SUBMIT_BATCH' : 'START',
        ]);

        if ($reimbursement->is_bulk) {
            $this->processBulk($reimbursement, $profile, $request);
            return;
        }

        $this->processSingle($reimbursement, $profile, $request);
    }

    /**
     * Single subscriber provisioning (via CommandExecutor)
     */
    protected function processSingle(
        Reimbursement $reimb,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): void {
        $request->update([
            'status'     => 'RUNNING',
            'started_at' => now()
        ]);

        $traceId = request()->header('X-Request-ID') ?? (string) Str::uuid();

        // Audit attribution priority: 1. Approver (Reviewer), 2. Requester, 3. System User ID[cite: 19]
        $actingUserId = $reimb->reviewed_by_user_id
            ?? $reimb->requested_by_user_id
            ?? 0;

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

                // Step 1: Debit funding account[cite: 11]
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

                if (!$debitLog->is_successful) {
                    $this->failed($request, "Debit failed: " . ($debitLog->response_payload['message'] ?? 'Unknown error'));
                    return;
                }

                $request->update([
                    'debit_command_log_id' => $debitLog->id,
                    'execution_step'       => 'CREDIT_SUBSCRIBER'
                ]);

                // Step 2: Credit target subscriber[cite: 11]
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
                    $this->failed($request, "Credit failed: " . ($creditLog->response_payload['message'] ?? 'Unknown error'));
                    return;
                }

                $this->success($request, $creditLog->id);
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
                        'MSISDN'   => $profile->fundingAccount->msisdn,
                        'number'   => $reimb->msisdn,
                        'offer_id' => $reimb->target_product_id
                    ],
                    $actingUserId,
                    null,
                    $traceId,
                    'form'
                );

                if (!$activationLog->is_successful) {
                    $this->failed($request, "Bundle activation failed: " . ($activationLog->response_payload['message'] ?? 'Unknown error'));
                    return;
                }

                $this->success($request, $activationLog->id);
                return;
            }

            throw new ProvisioningException("Unsupported reimbursement type: {$reimb->reimbursement_type}");

        } catch (\Throwable $e) {
            $this->failed($request, "System Exception during Single Provisioning: " . $e->getMessage());
        }
    }

    /**
     * Bulk provisioning (via BatchOrchestrator)
     */
    protected function processBulk(
        Reimbursement $reimb,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): void {
        try {
            $request->update([
                'status'     => 'RUNNING',
                'started_at' => now()
            ]);

            $traceId = request()->header('X-Request-ID') ?? (string) Str::uuid();

            // Audit attribution priority for the Batch creation[cite: 19]
            $actingUserId = $reimb->reviewed_by_user_id
                ?? $reimb->requested_by_user_id
                ?? 0;

            // 1. Locate and retrieve the correct file from secure disk storage[cite: 22]
            $secureRelativePath = $reimb->getSecureDiskPath();

            if (!$secureRelativePath || !Storage::disk('secure_reimbursements')->exists($secureRelativePath)) {
                throw new ProvisioningException("Secure bulk spreadsheet file not found for Reimbursement ID: {$reimb->id}");
            }

            // Extract file extension dynamically (correctly preserves xlsx, csv, or txt)[cite: 22]
            $extension = pathinfo($secureRelativePath, PATHINFO_EXTENSION);

            // 2. Mirror the file to the local storage path so BatchOrchestrator can ingest it[cite: 12]
            $tempBatchPath = "temp/batch_discovery/prov_run_" . $reimb->id . "_" . time() . "." . $extension;

            $fileContent = Storage::disk('secure_reimbursements')->get($secureRelativePath);
            Storage::disk('local')->put($tempBatchPath, $fileContent);

            // 3. Dynamically define column mapping based on the distribution mode[cite: 22]
            $columnMapping = [];

            if ($reimb->reimbursement_type === 'BUNDLE') {
                if ($reimb->distribution_mode === 'MANY_MANY') {
                    // Scenario: File has MSISDN in column 1, and the product/bundle configuration in column 2[cite: 23]
                    $columnMapping = [
                        'MSISDN'   => ['mode' => 'static',  'value' => $profile->fundingAccount->msisdn],
                        'number'   => ['mode' => 'dynamic', 'value' => 'msisdn'],
                        'offer_id' => ['mode' => 'dynamic', 'value' => 'value'] // Bound to file column 2[cite: 22, 23]
                    ];
                } else {
                    // Scenario (MANY_SINGLE): File only has MSISDN. Every subscriber gets the same bundle.[cite: 22]
                    $columnMapping = [
                        'MSISDN'   => ['mode' => 'static',  'value' => $profile->fundingAccount->msisdn],
                        'number'   => ['mode' => 'dynamic', 'value' => 'msisdn'],
                        'offer_id' => ['mode' => 'static',  'value' => $reimb->target_product_id] // Pulled from DB model[cite: 22]
                    ];
                }
            } elseif ($reimb->reimbursement_type === 'AIRTIME') {
                if ($reimb->distribution_mode === 'MANY_MANY') {
                    // Scenario: File has MSISDN in column 1, and the dynamic airtime amount in column 2[cite: 23]
                    $columnMapping = [
                        'msisdn' => ['mode' => 'dynamic', 'value' => 'msisdn'],
                        'amount' => ['mode' => 'dynamic', 'value' => 'value'] // Bound to file column 2[cite: 22, 23]
                    ];
                } else {
                    // Scenario (MANY_SINGLE): File only has MSISDN. Every subscriber gets the same airtime amount.[cite: 22]
                    $columnMapping = [
                        'msisdn' => ['mode' => 'dynamic', 'value' => 'msisdn'],
                        'amount' => ['mode' => 'static',  'value' => $reimb->amount] // Pulled from DB model[cite: 22]
                    ];
                }
            }

            // 4. Create a transient JobTemplate linked to the reviewer/approver[cite: 18]
            $template = JobTemplate::create([
                'id'                   => (string) Str::uuid(),
                'name'                 => "AUTO_PROV_RUN_" . $reimb->ticket_id, //[cite: 19]
                'user_id'              => $actingUserId,
                'provider_instance_id' => $profile->provisioning_provider_instance_id,
                'data_source_id'       => $profile->data_source_id ?? 1,
                'command_id'           => $profile->provisioning_command_id,
                'is_scheduled'         => false,
                'status'               => 'active',
                'source_config'        => [
                    'temporary_path' => $tempBatchPath,
                    'has_header'     => true
                ],
                'column_mapping'       => $columnMapping
            ]);

            // 5. Create the Batch Job Instance[cite: 17]
            $instance = JobInstance::create([
                'job_template_id'     => $template->id,
                'status'              => 'pending',
                'instance_parameters' => [
                    'provisioning_request_id' => $request->id
                ]
            ]);

            // 6. Instantly hand execution context over to the Orchestrator[cite: 13]
            $this->batchOrchestrator->execute($instance, $traceId);

            $request->update([
                'execution_batch_job_id' => $instance->id
            ]);

        } catch (\Throwable $e) {
            $this->failed($request, "System Exception during Bulk Provisioning: " . $e->getMessage());
        }
    }

    protected function success(ProvisioningRequest $request, string $executionId): void
    {
        $request->update([
            'status'                   => 'SUCCESS',
            'execution_command_log_id' => $executionId,
            'execution_step'           => 'COMPLETED',
            'completed_at'             => now()
        ]);
    }

    protected function failed(ProvisioningRequest $request, string $message): void
    {
        $request->update([
            'status'        => 'FAILED',
            'error_message' => $message,
            'completed_at'  => now()
        ]);
    }
}
