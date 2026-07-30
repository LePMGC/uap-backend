<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Exceptions\ProvisioningException;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Models\ProvisioningProfile;
use App\Modules\Operations\Models\ProvisioningRequest;
use App\Modules\Connectors\Services\BatchOrchestrator;
use App\Modules\Connectors\Services\BatchReadinessService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BulkProvisioningBuilder
{
    public function __construct(
        protected BatchOrchestrator $batchOrchestrator,
        protected BatchReadinessService $readiness
    ) {
    }

    public function build(
        Reimbursement $reimbursement,
        ProvisioningProfile $profile,
        ProvisioningRequest $request
    ): JobInstance {
        /*
        |--------------------------------------------------------------------------
        | 1. Platform readiness validation
        |--------------------------------------------------------------------------
        */
        $isManyMany = ($reimbursement->distribution_mode === 'MANY_MANY');

        // Skip individual provider ping if distribution mode is MANY_MANY
        $check = $this->readiness->check(
            false,
            $isManyMany ? null : $profile->provisioning_provider_instance_id,
            $isManyMany ? null : $profile->provisioning_command_id,
            $profile->data_source_id ?? 1
        );

        if (!$check['ready']) {
            $failedChecks = collect($check['required_checks'])
                ->where('status_type', 'danger')
                ->map(fn ($item) => [
                    'check'   => $item['name'] ?? 'Unknown',
                    'status'  => $item['status'] ?? 'Failed',
                    'message' => $item['message'] ?? 'No message provided'
                ])
                ->values()
                ->toArray();

            \Illuminate\Support\Facades\Log::error("Readiness Check Failed for Reimbursement #{$reimbursement->id}:", [
                'failed_checks' => $failedChecks,
                'full_payload'  => $check,
            ]);

            $failedNames = implode(', ', array_column($failedChecks, 'check'));
            throw new ProvisioningException("Batch platform is not ready. Failed check(s): {$failedNames}");
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Locate and verify source file
        |--------------------------------------------------------------------------
        */
        $source = $reimbursement->getSecureDiskPath();

        if (!$source || !Storage::disk('secure_reimbursements')->exists($source)) {
            throw new ProvisioningException("Bulk source file missing.");
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Copy file for batch engine ingestion
        |--------------------------------------------------------------------------
        */
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $temporaryPath = "temp/batch/provisioning_" . $reimbursement->id . "_" . time() . "." . $extension;

        Storage::disk('local')->put(
            $temporaryPath,
            Storage::disk('secure_reimbursements')->get($source)
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Build column mapping & JobTemplate
        |--------------------------------------------------------------------------
        */
        $mapping = $this->buildMapping($reimbursement, $profile);

        $template = JobTemplate::create([
            'id'                   => (string) Str::uuid(),
            'name'                 => "AUTO_PROVISIONING_" . $reimbursement->ticket_id,
            'user_id'              => $reimbursement->reviewed_by_user_id ?? $reimbursement->requested_by_user_id,
            'provider_instance_id' => $profile->provisioning_provider_instance_id,
            'data_source_id'       => $profile->data_source_id ?? 1,
            'command_id'           => $profile->provisioning_command_id,
            'is_scheduled'         => false,
            'status'               => 'active',
            'source_config'        => [
                'temporary_path' => $temporaryPath,
                'has_header'     => true,
                'provisioning_request_id' => $request->id,
            ],
            'column_mapping'       => $mapping,
            'job_specific_config'  => [],
            'workflow_steps'       => [],
        ]);

        /*
|--------------------------------------------------------------------------
| 5. Create batch instance
|--------------------------------------------------------------------------
*/
        $instance = JobInstance::create([
            'job_template_id'     => $template->id,
            'status'              => 'pending',
            'instance_parameters' => [
                'provisioning_request_id' => $request->id
            ]
        ]);

        /*
        |--------------------------------------------------------------------------
        | 6. Immediately associate instance and template back to ProvisioningRequest
        |--------------------------------------------------------------------------
        */
        $request->update([
            'execution_batch_job_id'    => $template->id,
            'execution_job_instance_id' => $instance->id,
        ]);

        return $instance;
    }

    protected function buildMapping(Reimbursement $reimbursement, ProvisioningProfile $profile): array
    {
        if ($reimbursement->reimbursement_type === 'BUNDLE') {
            if ($reimbursement->distribution_mode === 'MANY_MANY') {
                return [
                    'MSISDN'  => ['mode' => 'static',  'value' => $profile->fundingAccount->msisdn],
                    'bNumber' => ['mode' => 'dynamic', 'value' => 'msisdn'],
                    'offerId' => ['mode' => 'dynamic', 'value' => 'offer_id'],
                ];
            }

            return [
                'MSISDN'  => ['mode' => 'static',  'value' => $profile->fundingAccount->msisdn],
                'bNumber' => ['mode' => 'dynamic', 'value' => 'msisdn'],
                'offerId' => ['mode' => 'static',  'value' => $reimbursement->bundle->offer_id ?? $reimbursement->target_product_id],
            ];
        }

        if ($reimbursement->reimbursement_type === 'AIRTIME') {
            if ($reimbursement->distribution_mode === 'MANY_MANY') {
                return [
                    'msisdn' => ['mode' => 'dynamic', 'value' => 'msisdn'],
                    'amount' => ['mode' => 'dynamic', 'value' => 'value'],
                ];
            }

            return [
                'msisdn' => ['mode' => 'dynamic', 'value' => 'msisdn'],
                'amount' => ['mode' => 'static',  'value' => $reimbursement->amount],
            ];
        }

        throw new ProvisioningException("Unsupported reimbursement type.");
    }
}
