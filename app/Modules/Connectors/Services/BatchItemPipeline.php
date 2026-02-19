<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\JobInstance;
use Illuminate\Support\Facades\Log;

class BatchItemPipeline
{
    public function __construct(
        protected CommandExecutor $executor
    ) {}

    /**
     * Process a single row through the command sequence.
     */
    public function process(JobInstance $instance, array $rowData): bool
    {
        $template = $instance->template;
        $mapping = $template->column_mapping;
        $workflow = $template->workflow_steps; // Future-proof for multiple commands

        try {
            // 1. Map Raw Data to Command Parameters
            $userInput = $this->mapRowToInput($rowData, $mapping);

            // 2. Execute the Workflow (Current implementation: Single command)
            foreach ($workflow as $commandName) {
                $this->executor->execute(
                    $template->provider_instance_id,
                    $commandName,
                    $userInput,
                    $template->user_id,
                    $instance->id
                );
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Batch Row Processing Failed for Instance {$instance->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Translates CSV/DB columns to Blueprint parameters based on mapping.
     */
    protected function mapRowToInput(array $rowData, array $mapping): array
    {
        $mapped = [];
        foreach ($mapping as $commandParam => $sourceHeader) {
            // Use the header to find the value in the row
            $mapped[$commandParam] = $rowData[$sourceHeader] ?? null;
        }
        return $mapped;
    }
}