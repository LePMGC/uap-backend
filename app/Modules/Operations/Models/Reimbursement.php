<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Reimbursement extends Model
{
    use HasUuids;

    /**
     * Instruct Eloquent that key constraints are non-incrementing UUID string keys.
     */
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'ticket_id', 'msisdn', 'reimbursement_type', 'reimbursement_mode',
        'target_product_id', 'amount', 'is_bulk', 'file_reference_id',
        'required_tier', 'status', 'description', 'rejection_reason',
        'requested_by_user_id', 'approved_by_user_id','distribution_mode'
    ];

    protected $casts = [
        'is_bulk' => 'boolean',
        'amount' => 'float',
        'required_tier' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Model Execution Boot Lifecycle Hooks
     */
    protected static function boot()
    {
        parent::boot();

        /**
         * Automated Data Housekeeping: When an administrator hard-deletes a transaction entry
         * from the platform, automatically purge the linked multi-tenant data sheet off the private storage disk.
         */
        static::deleting(function (Reimbursement $reimbursement) {
            if ($reimbursement->is_bulk && $reimbursement->file_reference_id) {
                $reimbursement->deleteAssociatedFile();
            }
        });
    }

    /**
     * RELATIONSHIP LINK: Fetch all extraction diagnostic errors for this batch.
     * Maps the file_reference_id string locally to the bulk error logs records.
     */
    public function bulkErrors(): HasMany
    {
        return $this->hasMany(ReimbursementBulkError::class, 'file_reference_id', 'file_reference_id');
    }

    /**
     * RELATIONSHIP LINK: Fetch all physical verification evidence receipts/proofs uploaded.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ReimbursementAttachment::class, 'reimbursement_id');
    }

    /**
     * RELATIONSHIP LINK: User profile mapping tracking who initialized this request.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\UserManagement\Models\User::class, 'requested_by_user_id');
    }

    /**
     * RELATIONSHIP LINK: User profile mapping tracking who authorized this request.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\UserManagement\Models\User::class, 'approved_by_user_id');
    }

    /**
     * MODEL METHOD: Stream the linked MSISDN file contents directly from storage.
     * This reads the records on disk on-demand without bloating your database tables.
     */
    public function getUploadedRows(): array
    {
        if (!$this->is_bulk || !$this->file_reference_id) {
            return [];
        }

        $filename = $this->getSecureDiskPath();
        if (!$filename) {
            return [];
        }

        // Lazy load the file stream to parse data row indices sequentially
        $absolutePath = Storage::disk('secure_reimbursements')->path($filename);
        $spreadsheet = IOFactory::load($absolutePath);
        $sheetData = $spreadsheet->getActiveSheet()->toArray();

        $records = [];
        foreach ($sheetData as $index => $row) {
            if ($index === 0 || empty(array_filter($row))) {
                continue; // Skip file table header and empty rows
            }

            $records[] = [
                'msisdn' => trim($row[0] ?? ''),
                'value'  => trim($row[1] ?? '') // Represents Product Catalog ID or Airtime amount depending on structural context
            ];
        }

        return $records;
    }

    /**
     * UTILITY METHOD: Locate and return the relative path on the secure disk.
     */
    public function getSecureDiskPath(): ?string
    {
        if (!$this->file_reference_id) {
            return null;
        }

        $extensions = ['xlsx', 'csv', 'txt'];
        foreach ($extensions as $ext) {
            $filename = "uploaded_sheets/{$this->file_reference_id}.{$ext}";
            if (Storage::disk('secure_reimbursements')->exists($filename)) {
                return $filename;
            }
        }
        return null;
    }

    /**
     * UTILITY METHOD: Secure disk purge runner.
     */
    public function deleteAssociatedFile(): bool
    {
        $filename = $this->getSecureDiskPath();
        if ($filename) {
            return Storage::disk('secure_reimbursements')->delete($filename);
        }
        return false;
    }
}
