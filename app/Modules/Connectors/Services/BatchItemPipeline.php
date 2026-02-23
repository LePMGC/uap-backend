<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Models\CommandLog;
use Illuminate\Support\Facades\Log;

class BatchItemPipeline
{
    public function __construct(
        protected CommandExecutor $executor
    ) {}

    /**
     * Process a single row through the command sequence.
     */

    public function process(JobInstance $instance, array $rowData, ?string $traceId = null)
    {
        $template = $instance->template;
        
        // Extract the command name from the JSON config field
        $commandName = $template->job_specific_config['command'] ?? null;

        if (!$commandName) {
            throw new \Exception("Command name missing in job_specific_config for template: " . $template->id);
        }

        $mapping = $template->column_mapping;
        $apiParams = [];

        foreach ($mapping as $commandParam => $mapSource) {
            if (str_starts_with($mapSource, 'static:')) {
                $apiParams[$commandParam] = substr($mapSource, 7);
            } elseif (str_starts_with($mapSource, 'column:')) {
                $csvHeader = substr($mapSource, 7);
                $apiParams[$commandParam] = $rowData[$csvHeader] ?? null;
            } else {
                $apiParams[$commandParam] = $rowData[$mapSource] ?? null;
            }
        }

        // Use the instance user_id, or fall back to the template creator's ID
        $userId = $instance->user_id ?? $template->user_id;

        if (!$userId) {
            // Final fallback if everything is null (e.g. ID 1 for admin/system)
            $userId = 1; 
        }

        return $this->executor->execute(
            $template->provider_instance_id,
            $commandName, 
            $apiParams,
            (int) $userId, // Force cast to int to satisfy the type hint
            $instance->id
        );
    }

    /**
     * Translates CSV/DB columns to Blueprint parameters based on the Template's contract.
     */
    protected function mapRowToInput(array $rowData, array $mapping): array
    {
        $mapped = [];
        
        foreach ($mapping as $sourceHeader => $blueprintParam) {
            // Use null coalescing to prevent "Undefined index" errors if a row is malformed
            $mapped[$blueprintParam] = $rowData[$sourceHeader] ?? null;
        }

        return $mapped;
    }
}