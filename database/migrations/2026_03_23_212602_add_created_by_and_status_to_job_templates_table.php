<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_templates', function (Blueprint $blueprint) {

            $blueprint->enum('status', ['active', 'failed', 'paused', 'completed'])
                      ->default('active')
                      ->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_templates', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['status']);
        });
    }
};
