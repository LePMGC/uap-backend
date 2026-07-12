<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('commands', function (Blueprint $table) {

            $table->string('command_type', 20)
                ->default('USER')
                ->after('provider_instance_id');

            $table->string('system_key', 50)
                ->nullable()
                ->unique()
                ->after('command_type');
        });
    }

    public function down(): void
    {
        Schema::table('commands', function (Blueprint $table) {

            $table->dropUnique(['system_key']);

            $table->dropColumn([
                'command_type',
                'system_key',
            ]);
        });
    }
};
