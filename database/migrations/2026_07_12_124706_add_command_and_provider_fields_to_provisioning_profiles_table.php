<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->unsignedBigInteger('provider_instance_id')
                ->after('reimbursement_type');

            $table->unsignedBigInteger('command_id')
                ->nullable()
                ->after('provider_instance_id');

            $table->unsignedBigInteger('debit_command_id')
                ->nullable()
                ->after('command_id');

        });
    }

    public function down(): void
    {
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->dropColumn([
                'provider_instance_id',
                'command_id',
                'debit_command_id',
            ]);

        });
    }
};
