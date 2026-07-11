<?php

namespace App\Modules\Operations\Transformers;

use App\Modules\Core\UserManagement\Models\User;
use App\Modules\Operations\Models\CatalogProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReimbursementResource extends JsonResource
{
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

        /*
        |--------------------------------------------------------------------------
        | Authorization capabilities
        |--------------------------------------------------------------------------
        */

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

            /*
            |--------------------------------------------------------------------------
            | Core information
            |--------------------------------------------------------------------------
            */

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
                    'id'         => $bundle->id,
                    'offer_id'   => $bundle->offer_id,
                    'name'       => $bundle->name,
                    'category'   => ucfirst(strtolower($bundle->type)),
                    'price'      => number_format((float) $bundle->cost, 0, '.', ' ').' F',
                    'validity'   => $bundle->validity ? (int) $bundle->validity : null,
                    'validity_units' => $bundle->validity_units ? strtoupper($bundle->validity_units) : null,
                ]
            ),

            'amount'             => $this->amount !== null
                ? (float) $this->amount
                : null,

            'is_bulk'            => (bool) $this->is_bulk,
            'file_reference_id'  => $this->file_reference_id,

            'input_file_url' => $this->is_bulk && $this->file_reference_id
                ? url("/api/operations/reimbursements/{$this->id}/download-input-file")
                : null,

            'required_tier'      => (int) $this->required_tier,
            'status'             => $this->status,
            'description'        => $this->description,

            /*
            |--------------------------------------------------------------------------
            | Review information
            |--------------------------------------------------------------------------
            */

            'rejection_reason'   => $this->rejection_reason,
            'reviewed_at'        => $this->reviewed_at?->toIso8601String(),

            'requested_by_user_id' => $this->requested_by_user_id,
            'reviewed_by_user_id'  => $this->reviewed_by_user_id,

            /*
            |--------------------------------------------------------------------------
            | User names
            |--------------------------------------------------------------------------
            */

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
            | Attachments
            |--------------------------------------------------------------------------
            */

            'attachments' => ReimbursementAttachmentResource::collection(
                $this->whenLoaded('attachments')
            ),

            /*
            |--------------------------------------------------------------------------
            | Bulk validation errors
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Audit information
            |--------------------------------------------------------------------------
            */

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            /*
            |--------------------------------------------------------------------------
            | Frontend capabilities
            |--------------------------------------------------------------------------
            */

            'capabilities' => [

                // User can approve OR reject.
                'can_review' => $canReview,

                // Kept for backward compatibility if the frontend
                // still checks this property.
                'can_approve' => $canReview,

                'can_reject' => $canReview,

                'can_cancel' => $user
                    && $this->status === 'pending'
                    && $this->requested_by_user_id === $user->id,
            ],
        ];
    }
}
