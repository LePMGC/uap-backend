<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogProduct extends Model
{
    use SoftDeletes;

    // Remove the tracking variables so Eloquent handles standard auto-incrementing integers naturally:
    // public $incrementing = false;
    // protected $keyType = 'string';

    protected $fillable = [
        'id',             // Explicitly fillable now if mapping custom values or let it auto-assign
        'offer_id',
        'name',
        'type',
        'cost',
        'validity',
        'validity_units',
        'is_active',
    ];

    protected $casts = [
        'id'        => 'integer',
        'offer_id'  => 'integer',
        'validity'  => 'integer',
        'is_active' => 'boolean',
        'cost'      => 'decimal:2'
    ];
}
