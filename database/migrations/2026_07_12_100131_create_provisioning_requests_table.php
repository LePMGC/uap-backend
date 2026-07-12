<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('provisioning_requests', function (Blueprint $table) {

            $table->uuid('id')->primary();

            /*
            |--------------------------------------------------------------------------
            | Business Context
            |--------------------------------------------------------------------------
            */

            $table->foreignUuid('reimbursement_id')
                ->constrained('reimbursements')
                ->cascadeOnDelete();

            $table->foreignUuid('profile_id')
                ->constrained('provisioning_profiles')
                ->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Execution Status
            |--------------------------------------------------------------------------
            */

            $table->string('status', 20)
                ->default('PENDING');
            // PENDING | RUNNING | SUCCESS | FAILED

            $table->string('execution_type', 20);
            // COMMAND | BATCH

            $table->string('funding_strategy', 20);
            // SELF_DEBIT | PROVIDER_DEBIT

            /*
            |--------------------------------------------------------------------------
            | Execution References
            |--------------------------------------------------------------------------
            */

            // Funding account debit command (used only for SELF_DEBIT)
            $table->foreignUuid('debit_command_log_id')
                ->nullable()
                ->constrained('command_logs')
                ->nullOnDelete();

            // Main provisioning command execution (single subscriber)
            $table->foreignUuid('execution_command_log_id')
                ->nullable()
                ->constrained('command_logs')
                ->nullOnDelete();

            // Main provisioning batch execution (bulk reimbursement)
            $table->foreignUuid('execution_batch_job_id')
                ->nullable()
                ->constrained('job_templates')
                ->nullOnDelete();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_requests');
    }
};
