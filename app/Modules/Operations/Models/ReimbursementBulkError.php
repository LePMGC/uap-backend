<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementBulkError extends Model
{
    protected $fillable = [
        'file_reference_id', 'row', 'identifier', 'reason'
    ];

    /**
     * RELATIONSHIP LINK: Inverse lookup pointing back to the parent batch entity context.
     */
    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class, 'file_reference_id', 'file_reference_id');
    }
}
