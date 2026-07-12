<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Models\Command;

class ProvisioningProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'provisioning_profiles';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'name',
        'reimbursement_type',
        'provider_instance_id',
        'command_id',
        'debit_command_id',
        'execution_mode',
        'funding_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];


    public function providerInstance(): BelongsTo
    {
        return $this->belongsTo(
            ProviderInstance::class,
            'provider_instance_id'
        );
    }

    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(
            FundingAccount::class,
            'funding_account_id'
        );
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(
            Command::class,
            'command_id'
        );
    }

    public function debitCommand(): BelongsTo
    {
        return $this->belongsTo(
            Command::class,
            'debit_command_id'
        );
    }
}
