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
        Schema::create('catalog_products', function (Blueprint $table) {
            // Primary internal lookup code matching downstream Core Network definitions
            $table->string('id')->primary();

            // Explicit Core Network Integer Mapping representing the Provisioning Bundle/Offer ID
            // e.g., 36772 or 80016 for Local-LEAP API parameter matching
            $table->integer('offer_id')->nullable()->index();

            $table->string('name');
            $table->string('type');
            $table->decimal('cost', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
