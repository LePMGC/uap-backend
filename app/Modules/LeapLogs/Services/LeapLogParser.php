<?php

namespace App\Modules\LeapLogs\Services;

use Illuminate\Support\Collection;

class LeapLogParser
{
    public function parse(string $content): Collection
    {
        return collect(explode("\n", $content))
            ->filter(fn ($line) => trim($line) !== '')
            ->map(function ($line) {
                $line = trim($line);

                // 1. CLI PROMPT FILTER
                // Skips lines starting with [ (shell user) or lacking a pipe |
                // A valid log line always starts with a timestamp and contains pipes.
                if (str_starts_with($line, '[') || !str_contains($line, '|')) {
                    return null;
                }

                $parts = explode('|', $line);

                // 2. LOG STRUCTURE CHECK
                // Valid Leap logs have at least 7 columns (0 to 6)
                if (count($parts) < 5) {
                    return null;
                }

                $rawPayload = $parts[6] ?? '{}';
                $payload = json_decode($rawPayload, true);

                if (!is_array($payload)) {
                    $payload = ['raw_content' => $rawPayload];
                }

                $moduleIdFromLog = $parts[2] ?? 'Unknown';

                return [
                    'timestamp'     => $parts[0] ?? null,
                    'app_instance'  => $parts[1] ?? 'Unknown',
                    'module_id'     => $moduleIdFromLog,
                    'method'        => $parts[3] ?? 'Unknown',
                    'status'        => $parts[4] ?? 'Unknown',
                    'tid'           => $this->extractTid($payload, $moduleIdFromLog),
                    'identifier'    => $this->extractMsisdn($payload),
                    'bundle_info'   => $this->extractBundle($parts[3], $payload),
                    'system_source' => $this->detectSystem($parts[3], $payload),
                    'execution_ms'  => $payload['elapsedTime'] ?? $payload['httpRTT'] ?? ($parts[5] ?? null),
                    'payload'       => $payload,
                ];
            })
            ->filter() // Removes the 'null' entries from CLI prompts
            ->sortBy('timestamp')
            ->values();
    }

    private function extractTid(array $payload, ?string $fallbackId): ?string
    {
        return $payload['tid'] ?? $payload['originTransactionID'] ?? $fallbackId;
    }

    private function extractMsisdn(array $payload): ?string
    {
        $msisdn = $payload['MSISDN']
            ?? $payload['subscriberNumber']
            ?? $payload['receiverMsisdn']
            ?? $payload['qs']['0']['value'] ?? null;

        if (!$msisdn && isset($payload['raw_content'])) {
            preg_match("/'(\d{9,12})'/", $payload['raw_content'], $matches);
            $msisdn = $matches[1] ?? null;
        }

        return $msisdn;
    }

    private function extractBundle(string $method, array $payload): ?string
    {
        if ($method === 'dbill') {
            return $payload['planId'] ?? null;
        }
        return $payload['offerId'] ?? $payload['refillProfileID'] ?? null;
    }

    private function detectSystem(string $method, array $payload): string
    {
        if ($method === 'mariadb') {
            return 'Database (MariaDB)';
        }

        $url = $payload['url'] ?? '';
        if (str_contains($url, 'ewp')) {
            return 'EWP (Mobile Money)';
        }
        if (str_contains($url, 'smshttpquery')) {
            return 'SMSC';
        }
        if (str_contains($url, 'optasia')) {
            return 'Optasia (Loan)';
        }

        $ecsMethods = [
            'GetAccountDetails',
            'UpdateBalanceAndDate',
            'Refill',
            'UpdateOffer',
            'GetOffers',
            'GetAccumulators',
            'GetBalanceAndDate',
            'AddPeriodicAccountManagementData'
        ];

        if (in_array($method, $ecsMethods)) {
            return 'ECS (Charging)';
        }

        return ($method === 'dbill') ? 'DBILL (Catalogue)' : 'Internal/Other';
    }
}
