<?php

namespace App\Modules\LeapLogs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LeapLogs\Services\LeapLogParser;
use Illuminate\Http\Request;

class LogParserController extends Controller
{
    public function __construct(protected LeapLogParser $parser)
    {
    }

    public static function middleware(): array
    {
        return [];
    }

    public function parse(Request $request)
    {
        $content = '';
        if ($request->hasFile('logs')) {
            $content = $request->file('logs')->get();
        } else {
            $content = $request->input('raw_logs', '');
        }

        if (empty($content)) {
            return response()->json(['error' => 'No log content provided'], 400);
        }

        // 1. Get the flat, sorted list of logs from the service
        $sortedLogs = $this->parser->parse($content);

        if ($sortedLogs->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $groups = [];
        $currentGroup = null;

        // 2. Linear grouping based on consecutive application instances
        foreach ($sortedLogs as $log) {
            $appId = $log['app_instance'];

            // If it's the first log OR the app_instance has changed, start a new group
            if ($currentGroup === null || $currentGroup['app_instance'] !== $appId) {

                // Push the completed group to our list before starting a new one
                if ($currentGroup !== null) {
                    $groups[] = $this->finalizeGroup($currentGroup);
                }

                $currentGroup = [
                    'app_instance' => $appId,
                    'msisdn'       => $log['identifier'] ?? 'N/A',
                    'start_time'   => $log['timestamp'],
                    'logs'         => collect([$log])
                ];
            } else {
                // Same application as previous log, just append
                $currentGroup['logs']->push($log);

                // Update MSISDN if we found it in a later step of the same group
                if ($currentGroup['msisdn'] === 'N/A' && !empty($log['identifier'])) {
                    $currentGroup['msisdn'] = $log['identifier'];
                }
            }
        }

        // Push the very last group
        if ($currentGroup !== null) {
            $groups[] = $this->finalizeGroup($currentGroup);
        }

        return response()->json([
            'success' => true,
            'data'    => $groups
        ]);
    }

    /**
     * Helper to wrap up the group metadata
     */
    private function finalizeGroup(array $group): array
    {
        $logs = $group['logs'];
        return [
            'app_instance' => $group['app_instance'],
            'msisdn'       => $group['msisdn'],
            'start_time'   => $group['start_time'],
            'end_time'     => $logs->last()['timestamp'],
            'step_count'   => $logs->count(),
            'has_error'    => $logs->contains(fn ($l) => !in_array($l['status'], ['0', '200'])),
            'logs'         => $logs->values()
        ];
    }
}
