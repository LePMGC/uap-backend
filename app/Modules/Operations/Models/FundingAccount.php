<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundingAccount extends Model
{
    use HasFactory;

    protected $table = 'funding_accounts';

    /**
     * Primary key is now BIGINT AUTO INCREMENT.
     */
    protected $keyType = 'int';

    public $incrementing = true;

    protected $fillable = [
        'name',
        'msisdn',
        'description',
        'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function provisioningProfiles()
    {
        return $this->hasMany(
            ProvisioningProfile::class,
            'funding_account_id'
        );
    }
}
