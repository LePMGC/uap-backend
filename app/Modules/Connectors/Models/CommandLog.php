<?php

// app/Modules/Connectors/Models/CommandLog.php

namespace App\Modules\Connectors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Modules\Core\UserManagement\Models\User;

class CommandLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'provider_instance_id',
        'command_name',
        'category_slug',
        'request_payload',
        'response_payload',
        'is_successful',
        'response_code',
        'started_at',
        'ended_at',
        'execution_time_ms',
        'ip_address',
        'raw_response', 
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'is_successful' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function instance()
    {
        return $this->belongsTo(ProviderInstance::class, 'provider_instance_id');
    }
}