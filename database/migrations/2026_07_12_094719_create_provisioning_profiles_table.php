<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('provisioning_profiles', function (Blueprint $table) {

            $table->uuid('id')->primary();

            $table->string('name', 100);

            $table->string('reimbursement_type', 20);

            $table->string('execution_mode', 20)
                ->default('COMMAND');

            $table->foreignUuid('funding_account_id')
                ->constrained('funding_accounts')
                ->restrictOnDelete();

            $table->boolean('is_active')
                ->default(true);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_profiles');
    }
};
