<?php

namespace App\Modules\Operations\Models;

use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Reimbursement extends Model
{
    use HasUuids;
    use SoftDeletes;
    /**
     * UUID primary key configuration.
     */
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'ticket_id',
        'msisdn',
        'reimbursement_type',
        'reimbursement_mode',
        'target_product_id',
        'amount',
        'is_bulk',
        'file_reference_id',
        'distribution_mode',

        'required_tier',
        'status',
        'description',

        'requested_by_user_id',

        // Review information
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_reason',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'is_bulk'        => 'boolean',
        'amount'         => 'float',
        'required_tier'  => 'integer',
        'reviewed_at'    => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
        'deleted_at'     => 'datetime',
    ];

    /**
     * Model lifecycle hooks.
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (Reimbursement $reimbursement) {

            if (
                $reimbursement->is_bulk &&
                $reimbursement->file_reference_id
            ) {
                $reimbursement->deleteAssociatedFile();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Bulk validation errors.
     */
    public function bulkErrors(): HasMany
    {
        return $this->hasMany(
            ReimbursementBulkError::class,
            'file_reference_id',
            'file_reference_id'
        );
    }

    /**
     * Uploaded supporting documents.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(
            ReimbursementAttachment::class,
            'reimbursement_id'
        );
    }

    /**
     * User who created the reimbursement.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id'
        );
    }

    /**
     * User who reviewed (approved/rejected) the reimbursement.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by_user_id'
        );
    }

    public function provisioningRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(
            ProvisioningRequest::class,
            'reimbursement_id'
        );
    }


    /**
     * The bundle selected in this reimbursement, if applicable.
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(
            CatalogProduct::class,
            'target_product_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the reimbursement is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Whether the reimbursement has been approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Whether the reimbursement has been rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Whether the reimbursement has been reviewed.
     */
    public function isReviewed(): bool
    {
        return in_array($this->status, [
            'approved',
            'rejected',
        ]);
    }

    /**
     * Reads uploaded spreadsheet rows from secure storage.
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

        $absolutePath = Storage::disk('secure_reimbursements')
            ->path($filename);

        $spreadsheet = IOFactory::load($absolutePath);

        $sheetData = $spreadsheet
            ->getActiveSheet()
            ->toArray();

        $records = [];

        foreach ($sheetData as $index => $row) {

            if ($index === 0 || empty(array_filter($row))) {
                continue;
            }

            $records[] = [
                'msisdn' => trim($row[0] ?? ''),
                'value'  => trim($row[1] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * Returns the relative file path on the secure disk.
     */
    public function getSecureDiskPath(): ?string
    {
        if (!$this->file_reference_id) {
            return null;
        }

        foreach (['xlsx', 'csv', 'txt'] as $extension) {

            $filename = "uploaded_sheets/{$this->file_reference_id}.{$extension}";

            if (
                Storage::disk('secure_reimbursements')
                    ->exists($filename)
            ) {
                return $filename;
            }
        }

        return null;
    }

    /**
     * Deletes the uploaded spreadsheet.
     */
    public function deleteAssociatedFile(): bool
    {
        $filename = $this->getSecureDiskPath();

        if (!$filename) {
            return false;
        }

        return Storage::disk('secure_reimbursements')
            ->delete($filename);
    }
}
