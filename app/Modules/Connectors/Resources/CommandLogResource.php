<?php

namespace App\Modules\Connectors\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Modules\Connectors\Providers\ProviderFactory;
use Illuminate\Support\Facades\Log;

class CommandLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        // 1. Resolve Category Slug
        $category = is_array($this->resource)
            ? ($this->resource['category_slug'] ?? 'unknown')
            : ($this->category_slug ?? 'unknown');

        // 2. Resolve Response Format from Blueprints
        $format = config("blueprints.{$category}.response_format", 'xml');

        // 3. Extract Identifier (MSISDN) using Provider Logic
        $identifier = $this->resolveMetadataIdentifier($category);

        Log::info("CommandLogResource Access - ID: {$this->id}, User: " . ($this->user->name ?? 'System'));

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
                'id' => $this->user->id ?? null,
                'username' => $this->user->name ?? 'System',
            ],

            // Execution Result
            'result' => [
                'is_successful' => $this->is_successful,
                'response_code' => $this->response_code,
            ],

            // Data Payloads
            'payloads' => [
                'request' => [
                    'data' => $this->request_payload['data'] ?? [],
                    'raw'  => $this->request_payload['raw'] ?? '',
                ],
                'response' => $this->response_payload,
            ],

            // Enriched Metadata
            'metadata' => [
                'format' => $format,
                'execution_time' => number_format($this->execution_time_ms, 2) . 'ms',
                'timestamp' => $this->started_at ? $this->started_at->toDateTimeString() : now()->toDateTimeString(),
                'identifier' => $identifier
            ],
        ];
    }

    /**
     * Internal helper to extract the MSISDN/Identifier based on provider category.
     */
    protected function resolveMetadataIdentifier(string $category): string
    {
        $rawRequest = $this->request_payload['raw'] ?? '';

        if (empty($rawRequest)) {
            return 'N/A';
        }

        try {
            /** * We use the Factory to get the correct driver.
             * Since we only need the parsing logic, we pass empty arrays for config
             * and the minimal blueprint required for the factory to identify the driver.
             */
            $driver = ProviderFactory::make([], ['category_slug' => $category]);

            return $driver->extractIdentifier($rawRequest) ?? 'Unknown';
        } catch (\Throwable $e) {
            // Fallback if provider is not found or parsing fails
            Log::warning("Metadata extraction failed for command {$this->id}: " . $e->getMessage());
            return 'N/A';
        }
    }
}
