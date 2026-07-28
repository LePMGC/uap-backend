<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->uuid('execution_job_instance_id')
                ->nullable()
                ->after('execution_batch_job_id');

            $table->foreign('execution_job_instance_id')
                ->references('id')
                ->on('job_instances')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {
            $table->dropForeign(['execution_job_instance_id']);
            $table->dropColumn('execution_job_instance_id');
        });
    }
};
