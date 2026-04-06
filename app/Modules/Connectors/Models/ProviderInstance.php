<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProviderInstance extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'category_slug',
        'connection_settings',
        'is_active',
        'last_error_message',
        'latency_ms',
        'last_heartbeat_at',
        'health_score',
        'tps_limit'
    ];

    /**
     * The attributes that should be cast.
     * 'encrypted:json' ensures connection data is secure at rest.
     */
    protected $casts = [
        'connection_settings' => 'encrypted:json',
        'is_active' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

    /**
     * Scope to filter only active nodes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    //get command logs for this provider instance
    public function logs()
    {
        return $this->hasMany(CommandLog::class, 'provider_instance_id');
    }
}
