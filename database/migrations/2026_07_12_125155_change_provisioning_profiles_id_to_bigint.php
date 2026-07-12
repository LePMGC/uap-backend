<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        /*
         * 1. Remove foreign key dependency
         */
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropForeign([
                'profile_id'
            ]);
        });


        /*
         * 2. Change provisioning_requests.profile_id
         *    from UUID to BIGINT
         */
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropColumn('profile_id');
        });


        /*
         * 3. Drop provisioning_profiles primary key
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->dropPrimary();
        });


        /*
         * 4. Replace UUID id with bigint id
         */
        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->dropColumn('id');
        });


        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->bigIncrements('id')->first();
        });


        /*
         * 5. Recreate provisioning_requests.profile_id
         */
        Schema::table('provisioning_requests', function (Blueprint $table) {

            $table->unsignedBigInteger('profile_id')
                ->after('id');

            $table->foreign('profile_id')
                ->references('id')
                ->on('provisioning_profiles')
                ->onDelete('restrict');

        });
    }


    public function down(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropForeign([
                'profile_id'
            ]);

            $table->dropColumn('profile_id');
        });


        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->dropPrimary();

            $table->dropColumn('id');

            $table->uuid('id')->first();

            $table->primary('id');
        });


        Schema::table('provisioning_requests', function (Blueprint $table) {

            $table->uuid('profile_id')
                ->after('id');

            $table->foreign('profile_id')
                ->references('id')
                ->on('provisioning_profiles')
                ->onDelete('restrict');

        });
    }
};
