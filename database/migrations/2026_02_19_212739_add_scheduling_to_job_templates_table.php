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
        Schema::table('job_templates', function (Blueprint $table) {
            $table->boolean('is_scheduled')
                  ->default(false)
                  ->after('updated_at'); // adjust position if needed

            $table->string('cron_expression')
                  ->nullable()
                  ->after('is_scheduled'); // ex: "0 2 * * *"

            $table->timestamp('next_run_at')
                  ->nullable()
                  ->after('cron_expression');

            $table->string('timezone')
                  ->default('UTC')
                  ->after('next_run_at');

            // Optional performance index if you query upcoming jobs
            $table->index(['is_scheduled', 'next_run_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_templates', function (Blueprint $table) {
            $table->dropIndex(['is_scheduled', 'next_run_at']);

            $table->dropColumn([
                'is_scheduled',
                'cron_expression',
                'next_run_at',
                'timezone',
            ]);
        });
    }
};
