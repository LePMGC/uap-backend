<?php

namespace App\Modules\Core\Auditing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemLog extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'system_logs';

    protected $fillable = [
        'module',
        'event_type',
        'status',
        'user_name',
        'trace_id',
        'client_ip',
        'details',
    ];

    protected $casts = [
        'details' => 'json',
    ];

    /**
     * Relationship to user if user_id is passed in details
     */
    public function user()
    {
        return $this->belongsTo(\App\Modules\Core\UserManagement\Models\User::class, 'user_id');
    }
}
