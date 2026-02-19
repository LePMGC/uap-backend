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

            // Allows pausing schedule without disabling entire template
            $table->boolean('schedule_active')
                  ->default(true)
                  ->after('is_scheduled');

            // When schedule becomes valid
            $table->timestamp('starts_at')
                  ->nullable()
                  ->after('timezone');

            // When schedule expires
            $table->timestamp('ends_at')
                  ->nullable()
                  ->after('starts_at');

            // Optional index for scheduler performance
            $table->index([
                'is_scheduled',
                'schedule_active',
                'starts_at',
                'ends_at'
            ], 'job_templates_schedule_control_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_templates', function (Blueprint $table) {

            $table->dropIndex('job_templates_schedule_control_index');

            $table->dropColumn([
                'schedule_active',
                'starts_at',
                'ends_at',
            ]);
        });
    }
};
