<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Command extends Model
{
    protected $fillable = [
        'category_slug', 'name', 'command_key', 'action', 
        'description', 'request_payload', 'system_params', 
        'is_custom', 'created_by'
    ];

    protected $casts = [
        'system_params' => 'json',
        'is_custom' => 'boolean',
    ];
}