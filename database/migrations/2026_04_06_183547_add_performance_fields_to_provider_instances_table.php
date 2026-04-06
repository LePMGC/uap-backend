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
        Schema::table('provider_instances', function (Blueprint $table) {
            $table->integer('tps_limit')
                  ->nullable()
                  ->after('is_active');

            $table->integer('latency_ms')
                  ->nullable()
                  ->after('tps_limit');

            $table->float('health_score')
                  ->nullable()
                  ->after('latency_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_instances', function (Blueprint $table) {
            $table->dropColumn([
                'tps_limit',
                'latency_ms',
                'health_score'
            ]);
        });
    }
};
