<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignId('user_id')->constrained();
            
            // Link to the Ericsson node or other target
            $table->foreignId('provider_instance_id')->constrained('provider_instances');
            
            // Link to your existing Data Sources (SFTP, DB, etc.)
            $table->foreignId('data_source_id')->constrained('data_sources');
            
            // Specifics: e.g., which table in the DB or filename pattern in SFTP
            $table->json('job_specific_config');

            // Mapping: e.g., {"subscriberNumber": "CSV_Col_1"}
            $table->json('column_mapping');

            // Steps: e.g., ["Refill"]
            $table->json('workflow_steps');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_templates');
    }
};