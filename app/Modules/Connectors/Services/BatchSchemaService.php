<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\DataSource;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use League\Csv\Reader;
use League\Csv\Writer;
use Illuminate\Support\Facades\Log;

class BatchSchemaService
{
    /**
     * Direct discovery for manual file uploads.
     * Accepts an UploadedFile object or a direct string file path.
     */
    public function getSchemaFromUpload($file, int $limit = 5, ?string $delimiter = null): array
    {
        try {
            $filePath = is_string($file) ? $file : $file->getRealPath();

            // 1. Detect or resolve the delimiter
            $resolvedDelimiter = $delimiter ?: $this->detectDelimiter($filePath);

            // 2. If the file is not comma-separated, convert it in-place to standard CSV
            if ($resolvedDelimiter !== ',') {
                $this->normalizeToCommaDelimited($filePath, $resolvedDelimiter);
                $resolvedDelimiter = ','; // Updated to comma after conversion
            }

            // 3. Read headers and preview rows from the normalized file
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setDelimiter(',');
            $csv->setHeaderOffset(0);

            $headers = $csv->getHeader();
            $rows = [];
            $totalRecords = 0;

            foreach ($csv->getRecords() as $record) {
                $totalRecords++;

                if (count($rows) < $limit) {
                    $rows[] = $record;
                }
            }

            return [
                'headers'       => $headers,
                'rows'          => $rows,
                'total_records' => $totalRecords,
                'delimiter'     => ',',
                'was_converted' => isset($delimiter) ? $delimiter !== ',' : true,
            ];

        } catch (\Exception $e) {
            Log::error("Upload Schema Discovery Failed: " . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Converts a file from a given delimiter (e.g. ';') to standard comma-separated format in-place.
     */
    public function normalizeToCommaDelimited(string $filePath, string $currentDelimiter): void
    {
        if ($currentDelimiter === ',') {
            return;
        }

        $reader = Reader::createFromPath($filePath, 'r');
        $reader->setDelimiter($currentDelimiter);

        $tempPath = $filePath . '.tmp_comma';
        $writer = Writer::createFromPath($tempPath, 'w+');
        $writer->setDelimiter(',');

        // Stream rows unparsed (preserving the header as row 0)
        foreach ($reader->getIterator() as $row) {
            $writer->insertOne($row);
        }

        // Overwrite original file with the newly formatted comma-separated file
        rename($tempPath, $filePath);
    }

    /**
     * Detects whether the file uses comma (,) or semicolon (;) as a delimiter.
     */
    private function detectDelimiter(string $filePath): string
    {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            return ',';
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if (!$firstLine) {
            return ',';
        }

        $semicolons = substr_count($firstLine, ';');
        $commas     = substr_count($firstLine, ',');

        return $semicolons > $commas ? ';' : ',';
    }
}