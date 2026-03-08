<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Models\JobTemplate;
use League\Csv\Reader;
use Illuminate\Support\Facades\Storage;

class BatchValidationService
{
    /**
     * Preview the mapping of a CSV file against a command blueprint.
     */
    public function previewMapping(string $tempPath, int $commandId, array $mapping): array
    {
        $command = Command::with('parameters')->findOrFail($commandId);
        $reader = Reader::createFromPath(Storage::path($tempPath), 'r');
        $reader->setHeaderOffset(0);

        // Get first 5 rows for preview
        $records = iterator_to_array($reader->getSlice(0, 5));
        $headers = $reader->getHeader();

        $previewRows = [];
        foreach ($records as $index => $row) {
            $mappedData = [];
            $errors = [];

            foreach ($command->parameters as $param) {
                $csvHeader = $mapping[$param->name] ?? null;
                $value = $csvHeader ? ($row[$csvHeader] ?? null) : null;

                // Validation logic
                if ($param->is_mandatory && (is_null($value) || $value === '')) {
                    $errors[] = "Missing mandatory parameter: {$param->name}";
                }

                $mappedData[$param->name] = [
                    'value' => $value,
                    'label' => $param->label,
                    'type'  => $param->type,
                    'is_valid' => !in_array("Missing mandatory parameter: {$param->name}", $errors)
                ];
            }

            $previewRows[] = [
                'row_index' => $index + 1,
                'data' => $mappedData,
                'errors' => $errors
            ];
        }

        return [
            'command_name' => $command->name,
            'total_csv_columns' => count($headers),
            'preview' => $previewRows,
            'is_executable' => collect($previewRows)->pluck('errors')->flatten()->isEmpty()
        ];
    }
}