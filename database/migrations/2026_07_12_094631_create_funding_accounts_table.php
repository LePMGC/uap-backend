<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('funding_accounts', function (Blueprint $table) {

            $table->uuid('id')->primary();

            $table->string('name', 100);

            $table->string('msisdn', 20)->unique();

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_accounts');
    }
};
