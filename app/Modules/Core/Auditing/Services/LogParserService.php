<?php

namespace App\Modules\Core\Auditing\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class LogParserService
{
    public function getRawLogs(string $date): Collection
    {
        $path = "/var/log/uap/application-{$date}.log";
        
        if (!File::exists($path)) {
            return collect();
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return collect($lines)
            ->map(function ($line) {
                $line = trim($line);
                if (preg_match('/(\{.*\})/', $line, $matches)) {
                    $decoded = json_decode($matches[1], true);
                    if (is_array($decoded) && isset($decoded['module'])) {
                        return $decoded;
                    }
                }
                return null;
            })
            ->filter()
            ->values();
    }

/**
     * Get filtered logs returned as a LengthAwarePaginator.
     */
    public function getFilteredLogs(array $filters): LengthAwarePaginator
    {
        $date = $filters['date'] ?? now()->format('Y-m-d');
        
        $logs = $this->getRawLogs($date)->filter(function ($entry) use ($filters) {
            if (!empty($filters['user']) && !str_contains(strtolower($entry['user'] ?? ''), strtolower($filters['user']))) return false;
            if (!empty($filters['module']) && ($entry['module'] ?? '') !== strtoupper($filters['module'])) return false;
            if (!empty($filters['status']) && ($entry['status'] ?? '') !== strtoupper($filters['status'])) return false;
            if (!empty($filters['trace_id']) && ($entry['trace_id'] ?? '') !== $filters['trace_id']) return false;
            return true;
        })->reverse()->values();

        return $this->paginateCollection($logs, $filters['per_page'] ?? 15);
    }

    /**
     * Get Connectivity Statistics paginated by Provider.
     */
    public function getPaginatedStats(array $filters): LengthAwarePaginator
    {
        $date = $filters['date'] ?? now()->format('Y-m-d');
        $logs = $this->getRawLogs($date)->where('module', 'NETWORKAUDIT');

        $stats = $logs->groupBy(fn($item) => $item['details']['provider_name'] ?? 'Unknown')
            ->map(function ($group, $name) {
                return [
                    'provider_name' => $name,
                    'total'         => $group->count(),
                    'success'       => $group->where('status', 'SUCCESS')->count(),
                    'failed'        => $group->where('status', 'ERROR')->count(),
                    'failure_rate'  => round(($group->where('status', 'ERROR')->count() / $group->count()) * 100, 2) . '%'
                ];
            })->values();

        return $this->paginateCollection($stats, $filters['per_page'] ?? 15);
    }

    /**
     * Helper to wrap any collection into a LengthAwarePaginator
     */
    protected function paginateCollection(Collection $collection, int $perPage): LengthAwarePaginator
    {
        $page = request()->get('page', 1);
        
        return new LengthAwarePaginator(
            $collection->forPage($page, $perPage)->values(),
            $collection->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}