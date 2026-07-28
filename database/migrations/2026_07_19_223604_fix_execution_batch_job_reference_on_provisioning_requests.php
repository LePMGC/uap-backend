<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {

            $table->dropForeign([
                'execution_batch_job_id'
            ]);

            $table->foreign('execution_batch_job_id')
                ->references('id')
                ->on('job_instances')
                ->nullOnDelete();

        });
    }


    public function down(): void
    {
        Schema::table('provisioning_requests', function (Blueprint $table) {

            $table->dropForeign([
                'execution_batch_job_id'
            ]);

            $table->foreign('execution_batch_job_id')
                ->references('id')
                ->on('job_templates')
                ->nullOnDelete();

        });
    }
};
