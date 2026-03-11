<?php

namespace App\Modules\Connectors\Services\Parsers;

interface PayloadParserInterface
{
    /**
     * Extracts parameters and their locations from a raw string.
     * Returns an array of parameter names found.
     */
    public function extractParameters(string $rawPayload): array;

    /**
     * Converts the raw string into a reusable Template 
     * where parameters are replaced by placeholders (e.g., {{msisdn}}).
     */
    public function createTemplate(string $rawPayload): string;
}