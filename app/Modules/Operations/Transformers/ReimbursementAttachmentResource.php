<?php

namespace App\Modules\Operations\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReimbursementAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array payload format.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => (string) $this->id,
            'file_name'           => $this->file_name,
            'file_url'            => $this->file_url, // This reads seamlessly from the updated getFileUrlAttribute()
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'uploaded_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
