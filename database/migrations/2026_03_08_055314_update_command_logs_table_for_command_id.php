<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            // 1. Add the new foreign key column
            // We allow it to be nullable initially if you have existing logs
            $table->foreignId('command_id')
                  ->after('provider_instance_id')
                  ->nullable() 
                  ->constrained('commands')
                  ->onDelete('set null');

            // 2. We keep command_name for historical "snapshot" purposes, 
            // but we can make it nullable or rename it to 'command_key_snapshot'
            $table->string('command_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('command_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('command_id');
            $table->string('command_name')->nullable(false)->change();
        });
    }
};