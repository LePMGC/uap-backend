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
        Schema::table('job_instances', function (Blueprint $table) {
            $table->integer('success_records')->default(0)->after('processed_records')->comment('Rows where execution was SUCCESS');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_instances', function (Blueprint $table) {
            $table->dropColumn('success_records');
        });
    }
};
