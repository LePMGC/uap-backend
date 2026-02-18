<?php

namespace App\Modules\Connectors\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommandLogResource extends JsonResource
{
    public function toArray($request): array
    {
        $category = is_array($this->resource) ? $this->resource['category_slug'] : $this->category_slug;
    
        $format = config("providers.{$category}.response_format", 'text');

        return [
            'id' => $this->id,
            'user' => $this->user->username ?? 'System',
            'instance_name' => $this->instance->name ?? 'Unknown',
            'command_name' => $this->command_name,
            'category' => $this->category_slug,
            'is_successful' => $this->is_successful,
            'response_code' => $this->response_code,
            
            // Payloads
            'request_payload' => $this->request_payload,
            'response_payload' => $this->response_payload,
            'raw_response' => $this->raw_response,
            
            // Metadata for Frontend Display
            'display_metadata' => [
                'format' => $format, // 'xml', 'mml', etc.
                'execution_time' => number_format($this->execution_time_ms, 2) . 'ms',
                'timestamp' => $this->started_at->toDateTimeString(),
            ]
        ];
    }
}