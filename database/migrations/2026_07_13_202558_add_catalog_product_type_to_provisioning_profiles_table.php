<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->jsonb('catalog_product_types')
                ->nullable()
                ->after('reimbursement_type');
        });
    }


    public function down(): void
    {
        Schema::table('provisioning_profiles', function (Blueprint $table) {
            $table->dropColumn('catalog_product_types');
        });
    }
};
