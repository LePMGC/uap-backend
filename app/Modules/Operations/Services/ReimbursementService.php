<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Models\Reimbursement;
use App\Modules\Operations\Models\ReimbursementBulkError;
use App\Modules\Operations\Models\ReimbursementAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReimbursementService
{
    /**
     * Parse and validate an uploaded source spreadsheet file in-memory.
     * Writes the verified file to secure storage and logs diagnostics rows.
     */
    public function validateAndPresaveFile(UploadedFile $file): array
    {
        $tempPath = $file->getRealPath();
        $spreadsheet = IOFactory::load($tempPath);
        $sheetData = $spreadsheet->getActiveSheet()->toArray();

        $total = 0;
        $valid = 0;
        $invalid = 0;
        $errors = [];

        foreach ($sheetData as $index => $row) {
            if ($index === 0 || empty(array_filter($row))) {
                continue; // Skip header row and empty entries
            }

            $total++;
            $msisdn = trim($row[0] ?? '');
            $value = trim($row[1] ?? '');

            $rowErrors = [];

            // Telecom MSISDN formatting sanity guard checks
            if (empty($msisdn) || !preg_match('/^\d{10,15}$/', $msisdn)) {
                $rowErrors[] = 'Malformed subscriber MSISDN length or character pattern constraint violation.';
            }

            // Allocation target field check
            if (empty($value)) {
                $rowErrors[] = 'Resource value fields or asset target references cannot be left unmapped.';
            }

            if (!empty($rowErrors)) {
                $invalid++;
                foreach ($rowErrors as $errorReason) {
                    $errors[] = [
                        'row' => $index + 1,
                        'identifier' => $msisdn ?: 'UNKNOWN_ROW',
                        'reason' => $errorReason,
                    ];
                }
            } else {
                $valid++;
            }
        }

        // Generate a cryptographically unique track reference ID string
        $fileReferenceId = 'VLT-REF-' . strtoupper(Str::random(12));

        // Persist file securely to the private disk infrastructure
        $destinationFilename = "uploaded_sheets/{$fileReferenceId}.{$file->getClientOriginalExtension()}";
        Storage::disk('secure_reimbursements')->put($destinationFilename, file_get_contents($tempPath));

        // Log diagnostics errors to database if any were uncovered
        if (!empty($errors)) {
            foreach ($errors as $err) {
                ReimbursementBulkError::create([
                    'file_reference_id' => $fileReferenceId,
                    'row' => $err['row'],
                    'identifier' => $err['identifier'],
                    'reason' => $err['reason']
                ]);
            }
        }

        return [
            'file_reference_id' => $fileReferenceId,
            'metrics' => [
                'total' => $total,
                'valid' => $valid,
                'invalid' => $invalid
            ],
            'errors' => $errors
        ];
    }

    /**
     * Securely upload a validation proof file asset to the symlinked storage disk.
     */
    public function storeAttachment(UploadedFile $file): array
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        Storage::disk('reimbursement_attachments')->put($filename, file_get_contents($file->getRealPath()));

        return [
            'id' => $filename, // Return the actual filename context identifier
            'file_name' => $file->getClientOriginalName(),
            'file_url' => Storage::disk('reimbursement_attachments')->url($filename)
        ];
    }

    /**
     * Process creation requests within an isolated transactional wrapper context.
     */
    public function createReimbursement(array $data, int $userId): Reimbursement
    {
        return DB::transaction(function () use ($data, $userId) {

            // Core Business Logic Rule: Multi-tier approval risk routing thresholds
            $requiredTier = 1;
            if (isset($data['amount']) && $data['amount'] > 500) {
                $requiredTier = 2;
            }

            $reimbursement = Reimbursement::create([
                'ticket_id' => $data['ticket_id'],
                'reimbursement_type' => $data['reimbursement_type'],
                'reimbursement_mode' => $data['reimbursement_mode'],
                'is_bulk' => $data['is_bulk'],
                'msisdn' => $data['msisdn'] ?? null,
                'target_product_id' => $data['target_product_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'file_reference_id' => $data['file_reference_id'] ?? null,
                'required_tier' => $requiredTier,
                'status' => 'pending',
                'description' => $data['description'] ?? null,
                'requested_by_user_id' => $userId
            ]);

            // Map and resolve attachments database associations cleanly
            if (!empty($data['attachment_ids'])) {
                foreach ($data['attachment_ids'] as $attachmentId) {
                    ReimbursementAttachment::create([
                        'reimbursement_id' => $reimbursement->id,
                        'file_name' => 'Evidence_' . substr($attachmentId, 0, 8),
                        'file_path' => 'reimbursement_evidence/' . $attachmentId,
                        'file_url' => Storage::disk('reimbursement_attachments')->url($attachmentId),
                        'uploaded_by_user_id' => $userId
                    ]);
                }
            }

            return $reimbursement->load('attachments');
        });
    }
}
