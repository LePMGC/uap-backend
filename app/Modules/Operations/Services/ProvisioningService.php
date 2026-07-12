<?php

namespace App\Modules\Operations\Services;

use App\Models\Reimbursement;
use App\Models\ProvisioningProfile;
use App\Models\ProvisioningRequest;
use App\Exceptions\ProvisioningException;
use Illuminate\Support\Facades\DB;

class ProvisioningService
{
    protected $commandExecutor;
    protected $batchOrchestrator;


    public function __construct(
        $commandExecutor,
        $batchOrchestrator
    ) {
        $this->commandExecutor = $commandExecutor;
        $this->batchOrchestrator = $batchOrchestrator;
    }


    /**
     * Entry point after reimbursement approval.
     */
    public function dispatchProvisioning(
        Reimbursement $reimbursement
    ): void {

        $profile = ProvisioningProfile::query()
            ->where(
                'reimbursement_type',
                $reimbursement->reimbursement_type
            )
            ->where('is_active', true)
            ->with('fundingAccount')
            ->first();


        if (!$profile) {
            throw new ProvisioningException(
                "No provisioning profile configured for {$reimbursement->reimbursement_type}"
            );
        }


        $request = ProvisioningRequest::create([
            'reimbursement_id' => $reimbursement->id,
            'profile_id' => $profile->id,
            'status' => 'PENDING',
            'execution_type' =>
                $reimbursement->is_bulk
                    ? 'BATCH'
                    : 'COMMAND',
            'execution_step' =>
                $reimbursement->is_bulk
                    ? 'SUBMIT_BATCH'
                    : 'START',
        ]);


        if ($reimbursement->is_bulk) {
            $this->processBulk(
                $reimbursement,
                $profile,
                $request
            );
            return;
        }


        $this->processSingle(
            $reimbursement,
            $profile,
            $request
        );
    }



    /**
     * Single subscriber provisioning.
     */
    protected function processSingle(
        Reimbursement $reimb,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): void {

        $request->update([
            'status' => 'RUNNING',
            'started_at' => now()
        ]);
        try {
            /*
            |--------------------------------------------------------------------------
            | AIRTIME FLOW
            |--------------------------------------------------------------------------
            */
            if ($reimb->reimbursement_type === 'AIRTIME') {
                /*
                 * Step 1:
                 * Debit funding account
                 */
                $debit = $this->commandExecutor->run(
                    $profile->debitCommand->id,
                    $profile->provider_instance_id,
                    [
                        'msisdn' => $profile
                                ->fundingAccount
                                ->msisdn,
                        'amount' => $reimb->amount
                    ],
                    'SYSTEM'
                );

                if (!$debit->isSuccess()) {
                    $this->failed(
                        $request,
                        $debit->getErrorMessage()
                    );
                    return;
                }

                $request->update([
                    'debit_command_log_id'
                        => $debit->getLogId(),

                    'execution_step'
                        => 'CREDIT_SUBSCRIBER'
                ]);


                /*
                 * Step 2:
                 * Credit subscriber
                 */
                $credit = $this->commandExecutor->run(
                    $profile->command_id,
                    $profile->provider_instance_id,
                    [
                        'msisdn' => $reimb->msisdn,
                        'amount' => $reimb->amount
                    ],
                    'SYSTEM'
                );

                if (!$credit->isSuccess()) {
                    $this->failed(
                        $request,
                        "Credit failed: ".$credit->getErrorMessage()
                    );
                    return;
                }

                $this->success(
                    $request,
                    $credit->getLogId()
                );
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | BUNDLE FLOW
            |--------------------------------------------------------------------------
            */
            if ($reimb->reimbursement_type === 'BUNDLE') {
                $activation = $this->commandExecutor->run(
                    $profile->command_id,
                    $profile->provider_instance_id,
                    [
                        'MSISDN' => $profile
                                ->fundingAccount
                                ->msisdn,
                        'number' => $reimb->msisdn,
                        'offer_id' => $reimb
                                ->bundle
                                ->offer_id,
                        ],
                    'SYSTEM'
                );

                if (!$activation->isSuccess()) {
                    $this->failed(
                        $request,
                        $activation->getErrorMessage()
                    );
                    return;
                }

                $this->success(
                    $request,
                    $activation->getLogId()
                );
                return;
            }

            throw new ProvisioningException(
                "Unsupported reimbursement type"
            );

        } catch (\Throwable $e) {
            $this->failed(
                $request,
                $e->getMessage()
            );
        }
    }





    /**
     * Bulk provisioning.
     */
    protected function processBulk(
        Reimbursement $reimb,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): void {
        try {
            $request->update([
                'status' => 'RUNNING',
                'started_at' => now()
            ]);
            $batchJob =
                $this->batchOrchestrator->createJob([
                    'command_id'
                        => $profile->command_id,
                    'provider_instance_id'
                        => $profile->provider_instance_id,
                    'file_reference_id'
                        => $reimb->file_reference_id,
                    'global_parameters' => [
                        'funding_msisdn'
                            => $profile
                                ->fundingAccount
                                ->msisdn
                    ],
                    'executed_by' => 'SYSTEM',
                    'meta' => [
                        'provisioning_request_id'
                            => $request->id
                    ]
                ]);
            $request->update([
                'processing_batch_job_id'
                    => $batchJob->id

            ]);

        } catch (\Throwable $e) {
            $this->failed(
                $request,
                $e->getMessage()
            );
        }
    }


    protected function success(
        ProvisioningRequest $request,
        $executionId
    ): void {
        $request->update([
            'status' => 'SUCCESS',
            'processing_command_id'
                => $executionId,
            'execution_step'
                => 'COMPLETED',
            'completed_at'
                => now()
        ]);

    }

    protected function failed(
        ProvisioningRequest $request,
        string $message
    ): void {
        $request->update([
            'status' => 'FAILED',
            'error_message' => $message,
            'completed_at' => now()
        ]);
    }
}
