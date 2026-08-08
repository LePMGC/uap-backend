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
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        // 1. Resolve Category Slug
        $category = is_array($this->resource)
            ? ($this->resource['category_slug'] ?? 'unknown')
            : ($this->category_slug ?? 'unknown');

        // 2. Resolve Response Format from Blueprints
        $format = config(
            "blueprints.{$category}.response_format",
            'xml'
        );

        // 3. Resolve normalized target MSISDN
        //
        // Prefer request_payload.data because this is where the
        // actual structured command parameters are available.
        //
        // Fall back to the raw payload/provider extractor for
        // older records or payloads where data is unavailable.
        $targetMsisdn = $this->resolveTargetMsisdn($category);

        return [
            'id' => $this->id,

            // Normalized command target.
            // FE should always use this field and should not care
            // whether the provider calls it subscriberNumber,
            // bNumber, MSISDN, etc.
            'target_msisdn' => $targetMsisdn,

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
            // Keep the original payload untouched for debugging/auditing.
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
                'execution_time' => number_format(
                    $this->execution_time_ms,
                    2
                ) . 'ms',
                'timestamp' => $this->started_at
                    ? $this->started_at->toDateTimeString()
                    : now()->toDateTimeString(),

                // Keep identifier for backward compatibility.
                'identifier' => $targetMsisdn,
            ],
        ];
    }

    /**
     * Resolve the normalized target MSISDN/identifier.
     *
     * Provider-specific fields:
     *
     * Ericsson UCIP:
     *   subscriberNumber
     *
     * Conviva LEAP:
     *   bNumber (preferred)
     *   MSISDN (fallback)
     *
     * We first inspect the structured request data because command
     * logs may not contain a raw payload.
     */
    protected function resolveTargetMsisdn(string $category): string
    {
        $requestData = $this->request_payload['data'] ?? [];

        if (!is_array($requestData)) {
            $requestData = [];
        }

        /*
         * ============================================================
         * ERICSSON UCIP
         * ============================================================
         */
        if ($category === 'ericsson-ucip') {
            $subscriberNumber = $requestData['subscriberNumber'] ?? null;

            if ($subscriberNumber !== null && $subscriberNumber !== '') {
                return (string) $subscriberNumber;
            }
        }

        /*
         * ============================================================
         * CONVIVA LEAP
         * ============================================================
         *
         * LEAP command payloads can contain both:
         *
         *   bNumber = target subscriber
         *   MSISDN  = subscriber/account MSISDN
         *
         * For command-log targeting we explicitly prefer bNumber.
         */
        if ($category === 'conviva-leap') {
            $bNumber = $requestData['bNumber'] ?? null;

            if ($bNumber !== null && $bNumber !== '') {
                return (string) $bNumber;
            }

            $msisdn = $requestData['MSISDN'] ?? null;

            if ($msisdn !== null && $msisdn !== '') {
                return (string) $msisdn;
            }
        }

        /*
         * ============================================================
         * FALLBACK: RAW PROVIDER PAYLOAD
         * ============================================================
         *
         * This keeps compatibility with records where structured
         * request data is unavailable but raw payload exists.
         */
        return $this->resolveIdentifierFromRawPayload($category);
    }

    /**
     * Extract identifier from the raw provider payload.
     *
     * Used only as a fallback when structured request data does not
     * contain a target identifier.
     */
    protected function resolveIdentifierFromRawPayload(string $category): string
    {
        $rawRequest = $this->request_payload['raw'] ?? '';

        if (empty($rawRequest)) {
            return 'N/A';
        }

        try {
            $driver = ProviderFactory::make(
                [],
                ['category_slug' => $category]
            );

            return $driver->extractIdentifier($rawRequest) ?? 'N/A';

        } catch (\Throwable $e) {
            Log::warning(
                "Metadata identifier extraction failed for command {$this->id}: "
                . $e->getMessage()
            );

            return 'N/A';
        }
    }
}
