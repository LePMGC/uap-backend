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

            /*
            |--------------------------------------------------------------------------
            | Source Definition (Contract)
            |--------------------------------------------------------------------------
            | Defines how input data is retrieved and interpreted.
            */

            $table->string('source_type')
                  ->default('upload') // upload, sftp, db, api
                  ->after('user_id');

            $table->json('source_config')
                  ->nullable()
                  ->after('source_type');

            $table->json('expected_columns')
                  ->nullable()
                  ->after('source_config');

            // Optional: index for filtering by source type
            $table->index('source_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_templates', function (Blueprint $table) {

            $table->dropIndex(['source_type']);

            $table->dropColumn([
                'source_type',
                'source_config',
                'expected_columns',
            ]);
        });
    }
};
