<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        /*
         * 1. Remove foreign key from provisioning_profiles
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->dropForeign([
                'funding_account_id'
            ]);
        });


        /*
         * 2. Change provisioning_profiles.funding_account_id
         *    UUID -> BIGINT
         *
         * Existing relationships cannot be preserved because
         * funding_accounts.id values are UUIDs.
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->dropColumn('funding_account_id');
        });


        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->unsignedBigInteger('funding_account_id')
                ->after('provider_instance_id');

        });


        /*
         * 3. Drop funding_accounts primary key
         */
        Schema::table('funding_accounts', function (Blueprint $table) {
            $table->dropPrimary();
        });


        /*
         * 4. Replace UUID id with BIGINT
         */
        Schema::table('funding_accounts', function (Blueprint $table) {
            $table->dropColumn('id');
        });


        Schema::table('funding_accounts', function (Blueprint $table) {

            $table->bigIncrements('id')
                ->first();

        });


        /*
         * 5. Recreate FK
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->foreign('funding_account_id')
                ->references('id')
                ->on('funding_accounts')
                ->onDelete('restrict');

        });
    }


    public function down(): void
    {

        /*
         * Remove FK
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->dropForeign([
                'funding_account_id'
            ]);

        });


        /*
         * Remove BIGINT relation
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->dropColumn('funding_account_id');

        });


        /*
         * Restore UUID funding_accounts ID
         */
        Schema::table('funding_accounts', function (Blueprint $table) {

            $table->dropPrimary();

            $table->dropColumn('id');

            $table->uuid('id')
                ->first();

            $table->primary('id');

        });


        /*
         * Restore UUID FK
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {

            $table->uuid('funding_account_id')
                ->after('provider_instance_id');

            $table->foreign('funding_account_id')
                ->references('id')
                ->on('funding_accounts')
                ->onDelete('restrict');

        });
    }
};
