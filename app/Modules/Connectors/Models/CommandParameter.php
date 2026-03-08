<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandParameter extends Model
{
    protected $fillable = [
        'command_id', 'parent_id', 'name', 'label', 
        'type', 'is_mandatory', 'default_value', 
        'validation_rules', 'sort_order'
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'validation_rules' => 'json',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(CommandParameter::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CommandParameter::class, 'parent_id');
    }
}