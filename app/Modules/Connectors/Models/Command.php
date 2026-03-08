<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Command extends Model
{
    protected $fillable = [
        'category_slug', 'name', 'command_key', 'action', 
        'description', 'payload_template', 'system_params', 
        'is_custom', 'created_by'
    ];

    protected $casts = [
        'system_params' => 'json',
        'is_custom' => 'boolean',
    ];

    public function parameters(): HasMany
    {
        return $this->hasMany(CommandParameter::class)->whereNull('parent_id');
    }

    /**
     * Get all parameters including nested ones
     */
    public function allParameters(): HasMany
    {
        return $this->hasMany(CommandParameter::class);
    }
}