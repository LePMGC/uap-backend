<?php

namespace App\Modules\Connectors\Services\Parsers;

class XmlPayloadParser implements PayloadParserInterface
{
    public function extractParameters(string $rawPayload): array
    {
        // Regex to find content between tags
        preg_match_all('/<([^>]+)>([^<]+)<\/\1>/', $rawPayload, $matches);
        
        // Return unique tag names as potential parameters
        return array_unique($matches[1]);
    }

    public function createTemplate(string $rawPayload): string
    {
        // Replaces tag contents with Blade/Mustache placeholders
        return preg_replace('/<([^>]+)>([^<]+)<\/\1>/', '<$1>{{ $1 }}</$1>', $rawPayload);
    }
}