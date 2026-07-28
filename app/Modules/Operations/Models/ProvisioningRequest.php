<?php

namespace App\Modules\Operations\Models;

use App\Modules\Connectors\Models\JobInstance;
use App\Modules\Connectors\Models\JobTemplate;
use App\Modules\Connectors\Models\CommandLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProvisioningRequest extends Model
{
    use HasFactory;

    protected $table = 'provisioning_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'reimbursement_id',
        'status',
        'execution_type',
        'funding_strategy',
        'debit_command_log_id',
        'execution_command_log_id',
        'execution_batch_job_id',
        'profile_id',
    ];

    protected $casts = [
        'id' => 'string',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function profile()
    {
        return $this->belongsTo(
            ProvisioningProfile::class,
            'profile_id'
        );
    }

    public function reimbursement()
    {
        return $this->belongsTo(
            Reimbursement::class,
            'reimbursement_id'
        );
    }

    public function debitCommandLog()
    {
        return $this->belongsTo(
            CommandLog::class,
            'debit_command_log_id'
        );
    }

    public function executionCommandLog()
    {
        return $this->belongsTo(
            CommandLog::class,
            'execution_command_log_id'
        );
    }

    public function executionBatchJob()
    {
        return $this->belongsTo(
            JobTemplate::class,
            'execution_batch_job_id'
        );
    }

    /**
     * Link directly to the specific execution run (JobInstance)
     */
    public function executionJobInstance()
    {
        return $this->hasOneThrough(
            JobInstance::class,
            JobTemplate::class,
            'id',
            'job_template_id',
            'execution_batch_job_id',
            'id'
        );
    }
}
