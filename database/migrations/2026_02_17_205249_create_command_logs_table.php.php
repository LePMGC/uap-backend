<?php

// database/migrations/xxxx_xx_xx_create_command_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('provider_instance_id')->constrained()->onDelete('cascade');
            
            $table->string('command_name'); // e.g., GetAccumulators
            $table->string('category_slug'); // e.g., ericsson-ucip
            
            // Payloads stored as JSON for flexibility
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            
            $table->boolean('is_successful')->default(false);
            $table->integer('response_code')->nullable(); // The specific UCIP/CAI code
            
            // Performance and Timing
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->float('execution_time_ms')->nullable(); // Useful for latency monitoring
            
            $table->string('ip_address')->nullable(); // Audit: where did the user trigger this from?
            $table->timestamps();

            // Indexes for faster history lookup
            $table->index(['user_id', 'created_at']);
            $table->index('provider_instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_logs');
    }
};