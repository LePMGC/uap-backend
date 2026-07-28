<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            // 1. Drop the incorrect FK pointing execution_batch_job_id to job_instances
            $table->dropForeign('provisioning_requests_execution_batch_job_id_foreign');

            // 2. Point execution_batch_job_id correctly to job_templates
            $table->foreign('execution_batch_job_id')
                  ->references('id')
                  ->on('job_templates')
                  ->onDelete('set null');

            // 3. Drop the redundant instance FK and column from provisioning_requests
            $table->dropForeign('provisioning_requests_execution_job_instance_id_foreign');
            $table->dropColumn('execution_job_instance_id');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            // Re-add execution_job_instance_id
            $table->uuid('execution_job_instance_id')->nullable();
            $table->foreign('execution_job_instance_id')
                  ->references('id')
                  ->on('job_instances')
                  ->onDelete('set null');

            // Revert execution_batch_job_id back to job_instances
            $table->dropForeign(['execution_batch_job_id']);
            $table->foreign('execution_batch_job_id')
                  ->references('id')
                  ->on('job_instances')
                  ->onDelete('set null');
        });
    }
};
