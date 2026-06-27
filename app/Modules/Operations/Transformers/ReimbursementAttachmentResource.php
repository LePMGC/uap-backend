<?php

namespace App\Modules\Operations\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReimbursementAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'file_name'           => $this->file_name,
            'file_url'            => $this->file_url,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            // Format timestamps into standardized ISO 8601 strings for frontend date parsing
            'uploaded_at'         => $this->uploaded_at?->toIso8601String(),
        ];
    }
}
