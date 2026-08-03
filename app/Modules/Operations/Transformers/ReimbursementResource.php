<?php

namespace App\Modules\Operations\Transformers;

use App\Modules\Core\UserManagement\Models\User;
use App\Modules\Operations\Models\CatalogProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReimbursementResource extends JsonResource
{
    /**
     * Helper to read row count from stored subscriber sheet.
     */
    protected function getSubscriberCount(): ?int
    {
        if (!$this->is_bulk || !$this->file_reference_id) {
            return null;
        }

        $relativeDiskPath = $this->resource->getSecureDiskPath();

        if (!$relativeDiskPath || !Storage::disk('secure_reimbursements')->exists($relativeDiskPath)) {
            return null;
        }

        try {
            $absolutePath = Storage::disk('secure_reimbursements')->path($relativeDiskPath);
            $extension = strtolower(pathinfo($relativeDiskPath, PATHINFO_EXTENSION));

            if (in_array($extension, ['csv', 'txt'])) {
                $file = new \SplFileObject($absolutePath, 'r');
                $file->setFlags(\SplFileObject::READ_AHEAD | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
                $lines = 0;

                foreach ($file as $line) {
                    if (trim($line) !== '') {
                        $lines++;
                    }
                }

                return max(0, $lines > 0 ? $lines - 1 : 0);
            }

            if ($extension === 'xlsx') {
                $spreadsheet = IOFactory::load($absolutePath);
                $sheetData = $spreadsheet->getActiveSheet()->toArray();
                $count = 0;

                foreach ($sheetData as $index => $row) {
                    if ($index === 0 || empty(array_filter($row))) {
                        continue;
                    }
                    $count++;
                }

                return $count;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Could not calculate row count for reimbursement sheet #{$this->id}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $requester = $this->resource->relationLoaded('requester')
            ? $this->requester
            : User::find($this->requested_by_user_id);

        $user = auth()->user();

        $isRequesterTier2 = $requester
            ? $requester->hasPermissionTo('approve_tier2_reimbursements')
            : false;

        $bundle = $this->resource->relationLoaded('bundle')
            ? $this->bundle
            : CatalogProduct::find($this->target_product_id);

        $canReview = false;

        if (
            $user &&
            $this->status === 'pending' &&
            $this->requested_by_user_id !== $user->id
        ) {
            $canReview = $isRequesterTier2
                ? $user->hasPermissionTo('approve_tier2_reimbursements')
                : $user->hasPermissionTo('approve_tier1_reimbursements');
        }

        return [
            'id'                 => $this->id,
            'ticket_id'          => $this->ticket_id,
            'msisdn'             => $this->msisdn,
            'reimbursement_type' => $this->reimbursement_type,
            'reimbursement_mode' => $this->reimbursement_mode,
            'distribution_mode'  => $this->distribution_mode,
            'target_product_id'  => $this->target_product_id,
            'bundle'             => $this->when(
                $bundle,
                fn () => [
                    'id'             => $bundle->id,
                    'offer_id'       => $bundle->offer_id,
                    'name'           => $bundle->name,
                    'category'       => ucfirst(strtolower($bundle->type)),
                    'price'          => number_format((float) $bundle->cost, 0, '.', ' ').' F',
                    'validity'       => $bundle->validity ? (int) $bundle->validity : null,
                    'validity_units' => $bundle->validity_units ? strtoupper($bundle->validity_units) : null,
                ]
            ),

            'amount'            => $this->amount !== null ? (float) $this->amount : null,
            'is_bulk'           => (bool) $this->is_bulk,
            'file_reference_id' => $this->file_reference_id,

            'input_file_url' => $this->is_bulk && $this->file_reference_id
                ? url("/api/operations/reimbursements/{$this->id}/download-input-file")
                : null,

            'input_file_records_count' => $this->getSubscriberCount(),

            'required_tier' => (int) $this->required_tier,
            'status'        => $this->status,
            'description'   => $this->description,

            'provisioning_status' => $this->latest_provisioning_status
                ?? $this->provisioningRequest?->status
                ?? $this->provisioning_status
                ?? 'NOT_STARTED',

            'rejection_reason' => $this->rejection_reason,
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),

            'requested_by_user_id' => $this->requested_by_user_id,
            'reviewed_by_user_id'  => $this->reviewed_by_user_id,

            'requester_name' => $this->when(
                $this->resource->relationLoaded('requester'),
                fn () => $this->requester?->name
            ),

            'reviewer_name' => $this->when(
                $this->resource->relationLoaded('reviewer'),
                fn () => $this->reviewer?->name
            ),

            /*
            |--------------------------------------------------------------------------
            | Cleaned Provisioning Execution Payload (Summary & Admin References Only)
            |--------------------------------------------------------------------------
            */
            'provisioning_execution' => $this->when(
                $this->resource->relationLoaded('provisioningRequest'),
                function () {
                    $provRequest = $this->provisioningRequest;

                    if (!$provRequest) {
                        return null;
                    }

                    $totalInput = $this->is_bulk ? ($this->getSubscriberCount() ?? 0) : 1;
                    $summary = [
                        'status'          => $provRequest->status,
                        'execution_type'  => $provRequest->execution_type, // 'COMMAND' or 'BATCH'
                        'started_at'      => $provRequest->created_at?->toIso8601String(),
                        'completed_at'    => $provRequest->updated_at?->toIso8601String(),
                        'total_input'     => $totalInput,
                        'total_processed' => 0,
                        'total_success'   => 0,
                        'total_failed'    => 0,

                        'admin_reference' => [
                            'command_log_id'        => null,
                            'batch_job_id' => null,
                        ],
                    ];

                    // 1. Single Execution (COMMAND)
                    if ($provRequest->execution_type === 'COMMAND') {
                        $commandLog = $provRequest->executionCommandLog;

                        if ($commandLog) {
                            $summary['admin_reference']['command_log_id'] = $commandLog->id;
                            $isSuccess = (bool) $commandLog->is_successful;

                            $summary['total_processed'] = 1;
                            $summary['total_success']   = $isSuccess ? 1 : 0;
                            $summary['total_failed']    = $isSuccess ? 0 : 1;
                        }
                    }

                    // 2. Bulk Execution (BATCH)
                    if ($provRequest->execution_type === 'BATCH') {
                        $jobInstance = $provRequest->executionJobInstance;

                        if ($jobInstance) {
                            $summary['admin_reference']['batch_job_id'] = $jobInstance->job_template_id;
                            $summary['total_input']     = $jobInstance->total_records ?: $totalInput;
                            $summary['total_processed'] = $jobInstance->processed_records;
                            $summary['total_success']   = $jobInstance->success_records;
                            $summary['total_failed']    = $jobInstance->failed_records;
                        }
                    }

                    return $summary;
                }
            ),

            'attachments' => ReimbursementAttachmentResource::collection(
                $this->whenLoaded('attachments')
            ),

            'bulk_errors' => $this->when(
                $this->is_bulk && $this->resource->relationLoaded('bulkErrors'),
                function () {
                    return $this->bulkErrors->map(function ($error) {
                        return [
                            'row'        => (int) $error->row,
                            'identifier' => $error->identifier,
                            'reason'     => $error->reason,
                        ];
                    });
                }
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'capabilities' => [
                'can_review'  => $canReview,
                'can_approve' => $canReview,
                'can_reject'  => $canReview,
                'can_cancel'  => $user
                    && $this->status === 'pending'
                    && $this->requested_by_user_id === $user->id,
            ],
        ];
    }
}
