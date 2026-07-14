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
        'provisioning_provider_instance_id',
        'provisioning_command_id',
        'debit_provider_instance_id',
        'debit_command_id',
        'debit_using_provisioning_provider',
        'execution_mode',
        'funding_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'debit_using_provisioning_provider' => 'boolean',
        'catalog_product_types' => 'array',
    ];

    /**
     * Provider instance responsible for provisioning.
     */
    public function provisioningProviderInstance(): BelongsTo
    {
        return $this->belongsTo(
            ProviderInstance::class,
            'provisioning_provider_instance_id'
        );
    }

    /**
     * Provider instance responsible for debit operations.
     */
    public function debitProviderInstance(): BelongsTo
    {
        return $this->belongsTo(
            ProviderInstance::class,
            'debit_provider_instance_id'
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
     * Command executed for provisioning.
     */
    public function provisioningCommand(): BelongsTo
    {
        return $this->belongsTo(
            Command::class,
            'provisioning_command_id'
        );
    }

    /**
     * Command executed for debit operations.
     */
    public function debitCommand(): BelongsTo
    {
        return $this->belongsTo(
            Command::class,
            'debit_command_id'
        );
    }
}
