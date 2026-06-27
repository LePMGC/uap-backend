<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticket_id')->index(); // Helpdesk trouble ticket linking reference

            // --- SINGLE TRANSACTION COLUMNS ---
            // These are filled only if the user executes a SINGLE manual adjustment entry
            $table->string('msisdn')->nullable()->index();
            $table->string('target_product_id')->nullable();
            $table->decimal('amount', 12, 2)->nullable();

            // --- BULK BATCH TRANSACTION COLUMNS ---
            // These are engaged when processing data from an uploaded file
            $table->boolean('is_bulk')->default(false)->index();

            // This string links the DB record directly to the physical storage disk file
            $table->string('file_reference_id')->nullable()->unique();

            // --- STATUS & WORKFLOW FLOW CONTROL ---
            $table->tinyInteger('required_tier')->default(1);
            $table->enum('status', ['pending', 'approved', 'success', 'rejected', 'failed'])->default('pending')->index();
            $table->text('description')->nullable();
            $table->text('rejection_reason')->nullable();

            // --- AUDIT SYSTEM REFS ---
            $table->foreignId('requested_by_user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
