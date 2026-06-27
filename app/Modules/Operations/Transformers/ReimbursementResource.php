<?php

namespace App\Modules\Operations\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReimbursementResource extends JsonResource
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id, // Automatically serializes UUID strings safely
            'ticket_id'            => $this->ticket_id,
            'msisdn'               => $this->msisdn,
            'reimbursement_type'   => $this->reimbursement_type, // 'BUNDLE' | 'AIRTIME'
            'reimbursement_mode'   => $this->reimbursement_mode, // 'AUTO' | 'MANUAL'
            'target_product_id'    => $this->target_product_id,

            // Enforce explicit fractional typing mappings matching TypeScript constraints
            'amount'               => $this->amount !== null ? (float) $this->amount : null,
            'is_bulk'              => (bool) $this->is_bulk,
            'file_reference_id'    => $this->file_reference_id,
            'required_tier'        => (int) $this->required_tier,
            'status'               => $this->status, // 'pending' | 'approved' | 'success' | 'rejected' | 'failed'

            'description'          => $this->description,
            'rejection_reason'     => $this->rejection_reason,

            // Convert Eloquent relations down to explicit JSON API structural blocks
            'attachments'          => ReimbursementAttachmentResource::collection($this->whenLoaded('attachments')),

            'requested_by_user_id' => $this->requested_by_user_id,
            'approved_by_user_id'  => $this->approved_by_user_id,

            // User entity metadata preview metrics (lazy loaded)
            'requester_name'       => $this->whenRelationLoaded('requester', fn () => $this->requester?->name),
            'approver_name'        => $this->whenRelationLoaded('approver', fn () => $this->approver?->name),

            // Diagnostics log tracking matrices (Exposed only on detail requests)
            'bulk_errors'          => $this->when($this->is_bulk && $this->relationLoaded('bulkErrors'), function () {
                return $this->bulkErrors->map(fn ($err) => [
                    'row'        => (int) $err->row,
                    'identifier' => $err->identifier,
                    'reason'     => $err->reason,
                ]);
            }),

            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
        ];
    }
}
