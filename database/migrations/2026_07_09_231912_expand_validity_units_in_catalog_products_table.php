<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            // Drop the 20 character constraint to allow long system values
            $table->string('validity_units')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('validity_units', 20)->nullable()->change();
        });
    }
};
