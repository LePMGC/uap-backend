<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Core\UserManagement\Models\User;

class JobInstance extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'job_template_id',
        'status',
        'instance_parameters',
        'total_records',
        'processed_records',
        'failed_records',
        'scheduled_at',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'instance_parameters' => 'json',
        'scheduled_at'        => 'datetime',
        'started_at'          => 'datetime',
        'completed_at'        => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(JobTemplate::class, 'job_template_id');
    }

    /**
     * Link to the detailed execution logs
     */
    public function commandLogs(): HasMany
    {
        return $this->hasMany(CommandLog::class, 'job_instance_id');
    }

    /**
     * Helper to get the absolute path for admin/system access
     */
    public function getWorkingDirectory(): string
    {
        return storage_path("app/jobs/{$this->id}");
    }

    /**
     * Check if the job is finished
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    /**
    * GRANULAR UX: Virtual attribute for progress bar
    */
    public function getProgressPercentageAttribute(): int
    {
        if ($this->total_records <= 0) return 0;
        
        $done = $this->processed_records + $this->failed_records;
        return (int) min(100, ($done / $this->total_records) * 100);
    }

    // Ensure this is visible in API responses
    protected $appends = ['progress_percentage'];

    // Get job executor which is the user who initiated the job template linked to this instance
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_id');
    }
}