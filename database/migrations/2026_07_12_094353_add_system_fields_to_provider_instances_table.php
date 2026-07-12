<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('provider_instances', function (Blueprint $table) {
            $table->string('instance_type', 20)
                ->default('USER')
                ->after('provider_id');

            $table->string('system_key', 50)
                ->nullable()
                ->unique()
                ->after('instance_type');
        });
    }

    public function down(): void
    {
        Schema::table('provider_instances', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn([
                'instance_type',
                'system_key',
            ]);
        });
    }
};
