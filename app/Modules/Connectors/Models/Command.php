<?php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Command extends Model
{
    protected $fillable = [
        'category_slug',
        'name',
        'command_key',
        'action',
        'description',
        'request_payload',
        'system_params',
        'command_type',
        'system_key',
        'is_custom',
        'created_by',
    ];

    protected $casts = [
        'system_params' => 'json',
        'is_custom' => 'boolean',
    ];


    protected static function booted()
    {
        /*static::updating(function ($command) {
            if ($command->getOriginal('command_type') === 'SYSTEM') {
                throw new \Exception("System-defined orchestration hooks cannot be modified by regular operational requests.");
            }
        });*/

        static::deleting(function ($command) {
            if ($command->command_type === 'SYSTEM') {
                throw new \Exception("Protected system command configuration cannot be purged.");
            }
        });
    }
}
