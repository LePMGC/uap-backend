<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_template_id')->constrained('job_templates')->onDelete('cascade');
            
            // Status: pending, processing, completed, failed
            $table->string('status')->default('pending');

            // Any runtime-specific parameters (overrides)
            $table->json('instance_parameters')->nullable();

            // Progress tracking for the UI
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('failed_records')->default(0);

            // Scheduling and timing
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_instances');
    }
};