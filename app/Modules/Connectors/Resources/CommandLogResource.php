<?php

namespace App\Modules\Connectors\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CommandLogResource extends JsonResource
{
    public function toArray($request): array
    {
        $category = is_array($this->resource)
            ? $this->resource['category_slug']
            : $this->category_slug;

        $format = config("blueprints.{$category}.response_format", 'xml');

        \Log::info("command ID : ".$this->id.", User ID : ".$this->user->id.", User name : ".$this->user->name);

        return [
            'id' => $this->id,

            // Command Execution Context
            'command_info' => [
                'name' => $this->command_name,
                'category' => $category,
                'instance_name' => $this->instance->name ?? 'Unknown',
            ],

            // User / Executor Info
            'executed_by' => [
                'id' => $this->user->id,
                'username' => $this->user->name ?? 'System',
            ],

            // Execution Result
            'result' => [
                'is_successful' => $this->is_successful,
                'response_code' => $this->response_code,
            ],

            'payloads' => [
                'request' => [
                    'data' => $this->request_payload['data'] ?? [],
                    'raw'  => $this->request_payload['raw'] ?? '',
                ],
                'response' => $this->response_payload,
            ],

            'metadata' => [
                'format' => $format,
                'execution_time' => number_format($this->execution_time_ms, 2) . 'ms',
                'timestamp' => $this->started_at->toDateTimeString(),
            ],
        ];
    }
}
