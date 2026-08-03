<?php

namespace App\Modules\Operations\Services;

use App\Exceptions\ProvisioningException;
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
                continue;
            }

            $total++;
            $msisdn = trim($row[0] ?? '');
            $value = ($distributionMode === 'MANY_MANY') ? trim($row[1] ?? '') : null;
            $rowErrors = [];

            if (empty($msisdn) || !preg_match('/^\d{10,15}$/', $msisdn)) {
                $rowErrors[] = 'Malformed subscriber MSISDN length or character pattern constraint violation.';
            }

            if ($distributionMode === 'MANY_MANY' && empty($value)) {
                $rowErrors[] = 'Resource value fields or asset target references cannot be left unmapped in MANY_MANY mode.';
            }

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

        /*
        |--------------------------------------------------------------------------
        | Bulk File Strict Validation Rule (Stop Processing if Invalid Rows > 0)
        |--------------------------------------------------------------------------
        */
        if ($invalid > 0) {
            throw new ProvisioningException("Bulk file verification failed: Contains {$invalid} invalid structural rows.");
        }

        if ($total === 0) {
            throw new ProvisioningException("Bulk file verification failed: File contains no valid processing records.");
        }

        $fileReferenceId = 'VLT-REF-' . strtoupper(Str::random(12));
        $destinationFilename = "uploaded_sheets/{$fileReferenceId}.{$file->getClientOriginalExtension()}";
        Storage::disk('secure_reimbursements')->put($destinationFilename, file_get_contents($tempPath));

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

    public function storeAttachment(UploadedFile $file): array
    {
        $uuidFilename = (string) Str::uuid() . '.' . $file->getClientOriginalExtension();

        Storage::disk('reimbursement_attachments')->putFileAs('', $file, $uuidFilename);

        if (!Storage::disk('reimbursement_attachments')->exists($uuidFilename)) {
            throw new \Exception('The attachment could not be found immediately after being written to disk.');
        }

        return [
            'id'        => $uuidFilename,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $uuidFilename,
            'file_url'  => Storage::disk('reimbursement_attachments')->url($uuidFilename),
        ];
    }

    public function createReimbursement(array $data, int $userId): Reimbursement
    {
        return DB::transaction(function () use ($data, $userId) {
            $requiredTier = 1;
            if (isset($data['amount']) && $data['amount'] > 500) {
                $requiredTier = 2;
            }

            $reimbursement = Reimbursement::create([
                'ticket_id'           => $data['ticket_id'],
                'reimbursement_type'  => $data['reimbursement_type'],
                'reimbursement_mode'  => $data['reimbursement_mode'],
                'is_bulk'             => $data['is_bulk'],
                'msisdn'              => $data['msisdn'] ?? null,
                'target_product_id'   => $data['target_product_id'] ?? null,
                'amount'              => $data['amount'] ?? null,
                'file_reference_id'   => $data['file_reference_id'] ?? null,
                'required_tier'       => $requiredTier,
                'status'              => 'pending',
                'provisioning_status' => 'NOT_STARTED',
                'description'         => $data['description'] ?? null,
                'requested_by_user_id' => $userId,
                'distribution_mode'   => $data['distribution_mode'] ?? null
            ]);

            if (!empty($data['attachment_ids'])) {
                foreach ($data['attachment_ids'] as $attachmentToken) {
                    if (empty($attachmentToken) || $attachmentToken === "0") {
                        continue;
                    }

                    $cleanFileNameOnDisk = basename($attachmentToken);

                    /*
                    |--------------------------------------------------------------------------
                    | Secure Disk Existence Check Protection
                    |--------------------------------------------------------------------------
                    */
                    if (!Storage::disk('reimbursement_attachments')->exists($cleanFileNameOnDisk)) {
                        continue;
                    }

                    $uuidOnly = pathinfo($cleanFileNameOnDisk, PATHINFO_FILENAME);

                    ReimbursementAttachment::create([
                        'id'                  => Str::isUuid($uuidOnly) ? $uuidOnly : (string) Str::uuid(),
                        'reimbursement_id'    => $reimbursement->id,
                        'file_name'           => 'Evidence_' . substr($cleanFileNameOnDisk, 0, 8),
                        'file_path'           => $cleanFileNameOnDisk,
                        'uploaded_by_user_id' => $userId,
                        'file_url'            => '',
                    ]);
                }
            }

            return $reimbursement->load('attachments');
        });
    }

    public function updateReimbursement(Reimbursement $reimbursement, array $data): Reimbursement
    {
        return DB::transaction(function () use ($reimbursement, $data) {
            if (!empty($data['file_reference_id']) && $data['file_reference_id'] !== $reimbursement->file_reference_id) {
                $extensions = ['xlsx', 'csv', 'txt'];
                foreach ($extensions as $ext) {
                    $oldFilename = "uploaded_sheets/{$reimbursement->file_reference_id}.{$ext}";
                    if (Storage::disk('secure_reimbursements')->exists($oldFilename)) {
                        Storage::disk('secure_reimbursements')->delete($oldFilename);
                        break;
                    }
                }
            }

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

            foreach ($incomingTokens as $token) {
                if (empty($token) || $token === "0") {
                    continue;
                }

                $cleanFileNameOnDisk = basename($token);

                if (!Storage::disk('reimbursement_attachments')->exists($cleanFileNameOnDisk)) {
                    continue;
                }

                $uuidCandidate = pathinfo($cleanFileNameOnDisk, PATHINFO_FILENAME);

                if (in_array($token, $retainedAttachmentIds) || in_array($uuidCandidate, $retainedAttachmentIds)) {
                    continue;
                }

                $preExisting = ReimbursementAttachment::where('id', $uuidCandidate)
                    ->orWhere('file_path', $cleanFileNameOnDisk)
                    ->first();

                if ($preExisting) {
                    $preExisting->update(['reimbursement_id' => $reimbursement->id]);
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

    public function approveReimbursement(Reimbursement $reimbursement, int $reviewerId): Reimbursement
    {
        $approvedReimbursement = DB::transaction(function () use ($reimbursement, $reviewerId) {
            if ($reimbursement->status !== 'pending') {
                throw new \RuntimeException('Only pending reimbursements can be approved.');
            }

            $reimbursement->update([
                'status'              => 'approved',
                'provisioning_status' => 'QUEUED',
                'reviewed_by_user_id' => $reviewerId,
                'reviewed_at'         => now(),
                'rejection_reason'    => null,
            ]);

            return $reimbursement->fresh([
                'requester',
                'reviewer',
                'attachments',
            ]);
        });

        try {
            $provisioningService = app(\App\Modules\Operations\Services\ProvisioningService::class);
            $provisioningService->dispatchProvisioning($approvedReimbursement);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error(
                "Immediate network provisioning failed to dispatch for Reimbursement ID #{$approvedReimbursement->id}: " . $e->getMessage()
            );
            $approvedReimbursement->update(['provisioning_status' => 'FAILED']);
        }

        return $approvedReimbursement;
    }

    public function rejectReimbursement(Reimbursement $reimbursement, string $rejectionReason, int $reviewerId): Reimbursement
    {
        return DB::transaction(function () use ($reimbursement, $rejectionReason, $reviewerId) {
            if ($reimbursement->status !== 'pending') {
                throw new \RuntimeException('Only pending reimbursements can be rejected.');
            }

            $reimbursement->update([
                'status'              => 'rejected',
                'provisioning_status' => 'NOT_STARTED',
                'reviewed_by_user_id' => $reviewerId,
                'reviewed_at'         => now(),
                'rejection_reason'    => $rejectionReason,
            ]);

            return $reimbursement->fresh([
                'requester',
                'reviewer',
                'attachments',
            ]);
        });
    }

    /**
     * Cancel a pending reimbursement request.
     */
    public function cancelReimbursement(Reimbursement $reimbursement, int $userId): Reimbursement
    {
        return DB::transaction(function () use ($reimbursement, $userId) {
            if ($reimbursement->status !== 'pending') {
                throw new \RuntimeException('Only pending reimbursements can be cancelled.');
            }

            if ((int) $reimbursement->requested_by_user_id !== $userId) {
                throw new \RuntimeException('You are not authorized to cancel this reimbursement request.');
            }

            $reimbursement->update([
                'status'              => 'cancelled',
                'provisioning_status' => 'CANCELLED',
            ]);

            return $reimbursement->fresh([
                'requester',
                'reviewer',
                'attachments',
            ]);
        });
    }
}
