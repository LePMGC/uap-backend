<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTemplate extends Model
{
    use HasUuids; // Automatically handles UUID generation

    protected $fillable = [
        'name',
        'user_id',
        'provider_instance_id',
        'data_source_id',
        'job_specific_config',
        'column_mapping',
        'workflow_steps',
        'is_active'
    ];

    protected $casts = [
        'job_specific_config' => 'json',
        'column_mapping'      => 'json',
        'workflow_steps'      => 'json',
        'is_active'           => 'boolean',
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
}