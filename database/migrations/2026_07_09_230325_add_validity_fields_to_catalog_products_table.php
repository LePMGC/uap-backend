<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            // Add the numeric validity period value (e.g., 30, 24, 7)
            $table->integer('validity')
                  ->nullable()
                  ->after('cost');

            // Add the metric scale unit identifier (e.g., HOURS, DAYS, MONTHS)
            $table->string('validity_units', 20)
                  ->nullable()
                  ->after('validity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn(['validity', 'validity_units']);
        });
    }
};
