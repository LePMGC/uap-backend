<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            // Rename existing columns
            $table->renameColumn(
                'provider_instance_id',
                'provisioning_provider_instance_id'
            );

            $table->renameColumn(
                'command_id',
                'provisioning_command_id'
            );
        });

        Schema::table('provisioning_profiles', function (Blueprint $table) {

            // New debit execution pipeline
            $table->foreignId('debit_provider_instance_id')
                ->nullable()
                ->after('provisioning_command_id')
                ->constrained('provider_instances')
                ->nullOnDelete();

            $table->boolean('debit_using_provisioning_provider')
                ->default(true)
                ->after('debit_provider_instance_id');
        });

        /*
         * Existing records currently use the provisioning
         * provider for debit as well.
         */
        DB::table('provisioning_profiles')->update([
            'debit_using_provisioning_provider' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->dropForeign([
                'debit_provider_instance_id'
            ]);

            $table->dropColumn([
                'debit_provider_instance_id',
                'debit_using_provisioning_provider',
            ]);
        });

        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->renameColumn(
                'provisioning_provider_instance_id',
                'provider_instance_id'
            );

            $table->renameColumn(
                'provisioning_command_id',
                'command_id'
            );
        });
    }
};
