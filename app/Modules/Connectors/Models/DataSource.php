<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataSource extends Model
{
    use SoftDeletes;

    // Important: Tell Eloquent the table name if it's not following 
    // standard pluralization or if you want to be explicit.
    protected $table = 'data_sources';

    protected $fillable = [
        'name', 
        'type', 
        'connection_settings', 
        'created_by', 
        'is_active'
    ];

    protected $casts = [
        'connection_settings' => 'encrypted:json',
        'is_active' => 'boolean',
    ];

    /**
     * Relationship to the user who created the source.
     */
    public function creator()
    {
        return $this->belongsTo(\App\Modules\Core\UserManagement\Models\User::class, 'created_by');
    }
}