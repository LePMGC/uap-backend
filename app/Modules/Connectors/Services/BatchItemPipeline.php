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
    public function process(JobInstance $instance, array $row, ?string $traceId = null)
    {
        $template = $instance->template;
        $mapping = $template->column_mapping;
        $workflow = $template->workflow_steps;

        // 1. Map Raw Data to Blueprint parameters
        $userInput = $this->mapRowToInput($rowData, $mapping);

        $lastLog = null;

        // 2. Execute the Workflow steps sequentially
        foreach ($workflow as $commandName) {
            // execute() returns a CommandLog object
            $lastLog = $this->executor->execute(
                $template->provider_instance_id,
                $commandName,
                $userInput,
                $template->user_id,
                $instance->id,
                $traceId
            );

            // Optional: If a step fails, you might want to stop the workflow for this row
            if (!$lastLog->is_successful) {
                break; 
            }
        }

        return $lastLog;
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