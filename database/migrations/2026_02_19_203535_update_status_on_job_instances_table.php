<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_instances', function (Blueprint $table) {
            /**
             * We ensure the status is a string and indexed for the FE dashboard.
             * Possible values: pending, loading_data, dispatching, processing, finalizing, completed, failed
             */
            $table->string('status', 50)->default('pending')->change();
            $table->index('status'); 
        });
    }

    public function down(): void
    {
        Schema::table('job_instances', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};