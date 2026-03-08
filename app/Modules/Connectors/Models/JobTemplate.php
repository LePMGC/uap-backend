<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'provider_instance_id',
        'data_source_id',
        'command_id',
        'user_id',

        // Core configuration
        'job_specific_config',
        'column_mapping',
        'workflow_steps',

        // Source contract layer
        'source_config',
        'expected_columns',

        // Activation
        'is_active',

        // Scheduling
        'is_scheduled',
        'cron_expression',
        'next_run_at',
        'timezone',
        'schedule_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'job_specific_config' => 'json',
        'column_mapping'      => 'json',
        'workflow_steps'      => 'json',
        'expected_columns'    => 'json', 
        'source_config'       => 'json', 
        'is_active'           => 'boolean',
        'is_scheduled'        => 'boolean',
        'next_run_at'         => 'datetime',
        'starts_at'           => 'datetime',
        'ends_at'             => 'datetime',
    ];

    /**
     * The Target Node (e.g., Ericsson UCIP)
     */
    public function providerInstance(): BelongsTo
    {
        return $this->belongsTo(ProviderInstance::class);
    }

    /**
     * The existing Data Source (SFTP, DB, etc.)
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * History of all executions of this template
     */
    public function instances(): HasMany
    {
        return $this->hasMany(JobInstance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\UserManagement\Models\User::class, 'user_id');
    }

    /**
     * The specific Command Blueprint this batch will execute
     */
    public function command(): BelongsTo
    {
        return $this->belongsTo(Command::class);
    }
}