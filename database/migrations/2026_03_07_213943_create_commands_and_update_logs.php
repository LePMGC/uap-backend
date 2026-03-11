<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create the simplified commands table
        Schema::create('commands', function (Blueprint $table) {
            $table->id();
            $table->string('category_slug');
            $table->string('name');
            $table->string('command_key'); // e.g., 'Refill', 'submit_sm'
            $table->string('action')->default('view');
            $table->text('description')->nullable();
            $table->text('request_payload')->nullable(); // Raw XML/MML/Binary sample
            $table->json('system_params')->nullable();    // Headers/Meta
            $table->boolean('is_custom')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Index for faster lookups during execution
            $table->index(['category_slug', 'command_key']);
        });

        // 2. Update command_logs to link to the new commands table
        Schema::table('command_logs', function (Blueprint $table) {
            $table->foreignId('command_id')->nullable()->after('id')->constrained('commands')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('command_id');
        });

        Schema::dropIfExists('commands');
    }
};