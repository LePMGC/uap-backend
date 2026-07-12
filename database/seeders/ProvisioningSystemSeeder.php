<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Connectors\Models\ProviderInstance;
use App\Modules\Connectors\Models\Command;
use App\Modules\Operations\Models\FundingAccount;
use App\Modules\Operations\Models\ProvisioningProfile;

class ProvisioningSystemSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Provider Instances
        |--------------------------------------------------------------------------
        */

        $ucipProvider = ProviderInstance::updateOrCreate(
            [
                'system_key' => 'PROVISIONING_UCIP',
            ],
            [
                'name' => 'Ericsson UCIP Provisioning',
                'category_slug' => 'ericsson-ucip',
                'instance_type' => 'SYSTEM',
                'connection_settings' => [
                    'managed_by' => 'ProvisioningService',
                ],
                'is_active' => true,
            ]
        );


        $leapProvider = ProviderInstance::updateOrCreate(
            [
                'system_key' => 'PROVISIONING_LEAP',
            ],
            [
                'name' => 'Conviva LEAP Provisioning',
                'category_slug' => 'conviva-leap',
                'instance_type' => 'SYSTEM',
                'connection_settings' => [
                    'managed_by' => 'ProvisioningService',
                ],
                'is_active' => true,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | 2. Commands
        |--------------------------------------------------------------------------
        */

        $ucipCommand = Command::updateOrCreate(
            [
                'system_key' => 'UCIP_UPDATE_BALANCE_AND_DATE',
            ],
            [
                'category_slug' => 'ericsson-ucip',
                'name' => 'UpdateBalanceAndDate',
                'command_key' => 'UpdateBalanceAndDate',
                'action' => 'execute',
                'description' => 'System command used by reimbursement provisioning for airtime reimbursement.',
                'request_payload' => '',
                'system_params' => [],
                'is_custom' => false,
                'command_type' => 'SYSTEM',
                'created_by' => null,
            ]
        );


        $leapCommand = Command::updateOrCreate(
            [
                'system_key' => 'LEAP_BUNDLE_ACTIVATION',
            ],
            [
                'category_slug' => 'conviva-leap',
                'name' => 'BundleActivation',
                'command_key' => 'BundleActivation',
                'action' => 'execute',
                'description' => 'System command used by reimbursement provisioning for bundle activation.',
                'request_payload' => '',
                'system_params' => [],
                'is_custom' => false,
                'command_type' => 'SYSTEM',
                'created_by' => null,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | 3. Funding Accounts
        |--------------------------------------------------------------------------
        | IDs are now BIGINT auto-increment.
        | Do NOT manually set id values.
        |--------------------------------------------------------------------------
        */

        $airtimeWallet = FundingAccount::updateOrCreate(
            [
                'msisdn' => '242061100000',
            ],
            [
                'name' => 'Corporate Airtime Funding Wallet',
                'description' => 'Primary system source account for handling single/bulk airtime deductions.',
                'is_active' => true,
            ]
        );


        $bundleWallet = FundingAccount::updateOrCreate(
            [
                'msisdn' => '242061100001',
            ],
            [
                'name' => 'Corporate Bundle Funding Wallet',
                'description' => 'Primary system source account for handling dynamic bundle acquisition charges.',
                'is_active' => true,
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | 4. Provisioning Profiles
        |--------------------------------------------------------------------------
        */

        ProvisioningProfile::updateOrCreate(
            [
                'reimbursement_type' => 'AIRTIME',
            ],
            [
                'name' => 'Default Airtime Processing Strategy',

                'provider_instance_id' => $ucipProvider->id,

                'command_id' => $ucipCommand->id,

                'debit_command_id' => $ucipCommand->id,

                'execution_mode' => 'COMMAND',

                // BIGINT funding_accounts.id
                'funding_account_id' => $airtimeWallet->id,

                'is_active' => true,
            ]
        );


        ProvisioningProfile::updateOrCreate(
            [
                'reimbursement_type' => 'BUNDLE',
            ],
            [
                'name' => 'Default Bundle Processing Strategy',

                'provider_instance_id' => $leapProvider->id,

                'command_id' => $leapCommand->id,

                'debit_command_id' => null,

                'execution_mode' => 'COMMAND',

                // BIGINT funding_accounts.id
                'funding_account_id' => $bundleWallet->id,

                'is_active' => true,
            ]
        );
    }
}
