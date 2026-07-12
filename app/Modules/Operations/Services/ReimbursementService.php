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
     * Parse and validate an uploaded source spreadsheet file in-memory contextually.
     * Writes the verified file to secure storage and logs diagnostics rows.
     */
    public function validateAndPresaveFile(UploadedFile $file, string $distributionMode): array
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

            // Dynamic asset mapping extraction based entirely on distribution rules
            $value = ($distributionMode === 'MANY_MANY') ? trim($row[1] ?? '') : null;

            $rowErrors = [];

            // 1. Structural Guard Rule: Telecom MSISDN formatting check
            if (empty($msisdn) || !preg_match('/^\d{10,15}$/', $msisdn)) {
                $rowErrors[] = 'Malformed subscriber MSISDN length or character pattern constraint violation.';
            }

            // 2. Conditional Structural Guard Rule: Enforce row-specific assets ONLY during MANY_MANY operations
            if ($distributionMode === 'MANY_MANY' && empty($value)) {
                $rowErrors[] = 'Resource value fields or asset target references cannot be left unmapped in MANY_MANY mode.';
            }

            // Build diagnostic response logs matrix
            if (!empty($rowErrors)) {
                $invalid++;
                foreach ($rowErrors as $errorReason) {
                    $errors[] = [
                        'row'        => $index + 1,
                        'identifier' => $msisdn ?: 'UNKNOWN_ROW',
                        'reason'     => $errorReason,
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
                    'row'               => $err['row'],
                    'identifier'        => $err['identifier'],
                    'reason'            => $err['reason']
                ]);
            }
        }

        return [
            'file_reference_id' => $fileReferenceId,
            'metrics' => [
                'total'   => $total,
                'valid'   => $valid,
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
        // 1. Generate the filename with its extension
        $uuidFilename = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();

        Storage::disk('reimbursement_attachments')
            ->putFileAs('', $file, $uuidFilename);

        if (!Storage::disk('reimbursement_attachments')->exists($uuidFilename)) {
            throw new \Exception(
                'The attachment could not be found immediately after being written to disk.'
            );
        }

        return [
            // CRITICAL: We return the full filename with its extension as the token ID.
            // When the frontend submits, it passes this token back to createReimbursement.
            'id'        => $uuidFilename,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $uuidFilename,
            'file_url'  => Storage::disk('reimbursement_attachments')->url($uuidFilename),
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
                'requested_by_user_id' => $userId,
                'distribution_mode'  => $data['distribution_mode'] ?? null
            ]);

            // Map and resolve attachments database associations cleanly
            if (!empty($data['attachment_ids'])) {
                foreach ($data['attachment_ids'] as $attachmentToken) {
                    if (empty($attachmentToken) || $attachmentToken === "0") {
                        continue;
                    }

                    // Extract the clean name from the token path string
                    $cleanFileNameOnDisk = basename($attachmentToken);

                    // Since your schema uses a strict NOT NULL constraint for the primary key 'id' (UUID),
                    // and your data token is a UUID filename (e.g. 'uuid.pdf'), we can extract a clean
                    // UUID for the ID column and preserve the full string name for the path column.
                    $uuidOnly = pathinfo($cleanFileNameOnDisk, PATHINFO_FILENAME);

                    ReimbursementAttachment::create([
                        'id'                  => Str::isUuid($uuidOnly) ? $uuidOnly : (string) Str::uuid(),
                        'reimbursement_id'    => $reimbursement->id,
                        'file_name'           => 'Evidence_' . substr($cleanFileNameOnDisk, 0, 8),
                        'file_path'           => $cleanFileNameOnDisk, // Now correctly saves 'uuid.pdf' or 'uuid.png'
                        'uploaded_by_user_id' => $userId,
                        'file_url'            => '', // Bypasses NOT NULL field constraint safely
                    ]);
                }
            }

            return $reimbursement->load('attachments');
        });
    }

    /**
        * Core transactional logic to update text attributes, replace input spreadsheets, and sync attachments.
        */
    public function updateReimbursement(Reimbursement $reimbursement, array $data): Reimbursement
    {
        return DB::transaction(function () use ($reimbursement, $data) {

            // Check if a brand new bulk input file has been uploaded first and is replacing the old file
            if (!empty($data['file_reference_id']) && $data['file_reference_id'] !== $reimbursement->file_reference_id) {

                // Identify and purge the old file from secure disk storage
                $extensions = ['xlsx', 'csv', 'txt'];
                foreach ($extensions as $ext) {
                    $oldFilename = "uploaded_sheets/{$reimbursement->file_reference_id}.{$ext}";
                    if (Storage::disk('secure_reimbursements')->exists($oldFilename)) {
                        Storage::disk('secure_reimbursements')->delete($oldFilename);
                        break; // Stop searching once the file is deleted
                    }
                }
            }

            // 1. Update text fields and the new spreadsheet file reference identifier
            $reimbursement->update([
                'ticket_id'          => $data['ticket_id'],
                'description'        => $data['description'] ?? null,
                'reimbursement_type' => $data['reimbursement_type'],
                'reimbursement_mode' => $data['reimbursement_mode'],
                'target_product_id'  => $data['target_product_id'] ?? null,
                'amount'             => $data['amount'] ?? null,
                'file_reference_id'  => $data['file_reference_id'] ?? $reimbursement->file_reference_id,
                'is_bulk'            => $data['is_bulk'] ?? $reimbursement->is_bulk,
                'distribution_mode'  => $data['distribution_mode'] ?? null,
            ]);

            $incomingTokens = $data['attachment_ids'] ?? [];
            $userId = auth()->id() ?? 2;

            // 2. Fetch and sync the evidence attachments list (add/remove logic)
            $currentAttachments = ReimbursementAttachment::where('reimbursement_id', $reimbursement->id)->get();
            $retainedAttachmentIds = [];

            foreach ($currentAttachments as $attachment) {
                $isRetained = false;

                foreach ($incomingTokens as $token) {
                    $cleanToken = basename($token);
                    $tokenUuidOnly = pathinfo($cleanToken, PATHINFO_FILENAME);

                    if ($attachment->id === $token
                        || $attachment->file_path === $cleanToken
                        || pathinfo($attachment->file_path, PATHINFO_FILENAME) === $tokenUuidOnly
                    ) {
                        $isRetained = true;
                        break;
                    }
                }

                if ($isRetained) {
                    $retainedAttachmentIds[] = $attachment->id;
                } else {
                    if (!empty($attachment->file_path)) {
                        Storage::disk('reimbursement_attachments')->delete($attachment->file_path);
                    }
                    $attachment->delete();
                }
            }

            // 3. Register newly appended attachment files
            foreach ($incomingTokens as $token) {
                if (empty($token) || $token === "0") {
                    continue;
                }

                $cleanFileNameOnDisk = basename($token);
                $uuidCandidate = pathinfo($cleanFileNameOnDisk, PATHINFO_FILENAME);

                if (in_array($token, $retainedAttachmentIds) || in_array($uuidCandidate, $retainedAttachmentIds)) {
                    continue;
                }

                $preExisting = ReimbursementAttachment::where('id', $uuidCandidate)
                    ->orWhere('file_path', $cleanFileNameOnDisk)
                    ->first();

                if ($preExisting) {
                    $preExisting->update([
                        'reimbursement_id' => $reimbursement->id
                    ]);
                    $retainedAttachmentIds[] = $preExisting->id;
                } else {
                    $newAttachmentId = Str::isUuid($uuidCandidate) ? $uuidCandidate : (string) Str::uuid();

                    ReimbursementAttachment::create([
                        'id'                  => $newAttachmentId,
                        'reimbursement_id'    => $reimbursement->id,
                        'file_name'           => 'Evidence_' . substr($cleanFileNameOnDisk, 0, 8),
                        'file_path'           => $cleanFileNameOnDisk,
                        'uploaded_by_user_id' => $userId,
                        'file_url'            => '',
                    ]);

                    $retainedAttachmentIds[] = $newAttachmentId;
                }
            }

            return $reimbursement->load('attachments');
        });
    }

    /**
     * Approve a reimbursement.
     */
    /**
         * Approve a reimbursement and fire network provisioning workflows.
         */
    public function approveReimbursement(
        Reimbursement $reimbursement,
        int $reviewerId
    ): Reimbursement {
        // 1. Process business status updates inside an isolated transaction
        $approvedReimbursement = DB::transaction(function () use ($reimbursement, $reviewerId) {

            // Prevent reviewing an already reviewed reimbursement.
            if ($reimbursement->status !== 'pending') { //[cite: 2]
                throw new \RuntimeException( //[cite: 2]
                    'Only pending reimbursements can be approved.' //[cite: 2]
                ); //[cite: 2]
            } //[cite: 2]

            $reimbursement->update([ //[cite: 2]
                'status' => 'approved', //[cite: 2]
                'reviewed_by_user_id' => $reviewerId, //[cite: 2]
                'reviewed_at' => now(), //[cite: 2]
                'rejection_reason' => null, //[cite: 2]
            ]); //[cite: 2]

            return $reimbursement->fresh([ //[cite: 2]
                'requester', //[cite: 2]
                'reviewer', //[cite: 2]
                'attachments', //[cite: 2]
                'bulkErrors', //[cite: 2]
            ]); //[cite: 2]
        });

        // 2. Safely trigger the Provisioning Service outside the database write lock
        try {
            // Adjust the namespace lookup to wherever your ProvisioningService is registered
            $provisioningService = app(\App\Modules\Operations\Services\ProvisioningService::class);
            $provisioningService->dispatchProvisioning($approvedReimbursement);
        } catch (\Exception $e) {
            // Log infrastructure failures locally so the financial ledger remains intact,
            // the record state remains 'approved', but operations are alerted.
            \Illuminate\Support\Facades\Log::error(
                "Immediate network provisioning failed to dispatch for Reimbursement ID #{$approvedReimbursement->id}: " . $e->getMessage()
            );
        }

        return $approvedReimbursement;
    }

    /**
     * Reject a reimbursement.
     */
    public function rejectReimbursement(
        Reimbursement $reimbursement,
        string $rejectionReason,
        int $reviewerId
    ): Reimbursement {
        return DB::transaction(function () use (
            $reimbursement,
            $rejectionReason,
            $reviewerId
        ) {

            // Prevent reviewing an already reviewed reimbursement.
            if ($reimbursement->status !== 'pending') {
                throw new \RuntimeException(
                    'Only pending reimbursements can be rejected.'
                );
            }

            $reimbursement->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $reviewerId,
                'reviewed_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            return $reimbursement->fresh([
                'requester',
                'reviewer',
                'attachments',
                'bulkErrors',
            ]);
        });
    }
}
