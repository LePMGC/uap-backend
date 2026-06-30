<?php

namespace App\Modules\Operations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReimbursementAttachment extends Model
{
    use HasFactory;

    protected $table = 'reimbursement_attachments';

    // Disable auto-increment since we are explicitly using UUIDs
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'reimbursement_id',
        'file_name',
        'file_path',
        'file_url',
        'uploaded_by_user_id',
    ];

    protected $appends = ['file_url'];

    /**
     * Dynamic computed accessor attribute framework.
     * Converts raw internal 'file_path' elements into a valid public link string.
     */
    public function getFileUrlAttribute(): string
    {
        if (empty($this->file_path)) {
            return '';
        }

        if (filter_var($this->file_path, FILTER_VALIDATE_URL)) {
            return $this->file_path;
        }

        return Storage::disk('reimbursement_attachments')->url($this->file_path);
    }

    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class, 'reimbursement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
