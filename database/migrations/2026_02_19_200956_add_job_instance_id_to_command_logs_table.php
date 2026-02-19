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
        Schema::table('command_logs', function (Blueprint $table) {
            $table->uuid('job_instance_id')
                  ->nullable()
                  ->after('provider_instance_id');

            // Optional: add index for performance
            $table->index('job_instance_id');

            // Optional: add foreign key if job_instances table exists
            // $table->foreign('job_instance_id')
            //       ->references('id')
            //       ->on('job_instances')
            //       ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            // If you added FK, drop it first
            // $table->dropForeign(['job_instance_id']);

            $table->dropIndex(['job_instance_id']);
            $table->dropColumn('job_instance_id');
        });
    }
};
