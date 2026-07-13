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
        'catalog_product_types',
        'provider_instance_id',
        'command_id',
        'debit_command_id',
        'execution_mode',
        'funding_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'catalog_product_types' => 'array',
    ];


    /**
     * Provider instance responsible for execution.
     */
    public function providerInstance(): BelongsTo
    {
        return $this->belongsTo(
            ProviderInstance::class,
            'provider_instance_id'
        );
    }


    /**
     * Funding account used for provisioning operations.
     */
    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(
            FundingAccount::class,
            'funding_account_id'
        );
    }


    /**
     * Main provisioning command.
     */
    public function command(): BelongsTo
    {
        return $this->belongsTo(
            Command::class,
            'command_id'
        );
    }


    /**
     * Debit command used for reimbursement charging.
     */
    public function debitCommand(): BelongsTo
    {
        return $this->belongsTo(
            Command::class,
            'debit_command_id'
        );
    }
}
