<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_templates', function (Blueprint $table) {

            // Drop index safely (if exists)
            if (Schema::hasColumn('job_templates', 'source_type')) {

                // Default Laravel index name
                $indexName = 'job_templates_source_type_index';

                // Check if index exists before dropping (Postgres-safe)
                $indexes = collect(DB::select("
                    SELECT indexname
                    FROM pg_indexes
                    WHERE tablename = 'job_templates'
                "))->pluck('indexname');

                if ($indexes->contains($indexName)) {
                    $table->dropIndex($indexName);
                }

                $table->dropColumn('source_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_templates', function (Blueprint $table) {

            if (!Schema::hasColumn('job_templates', 'source_type')) {

                $table->string('source_type')
                      ->default('upload');

                $table->index('source_type');
            }
        });
    }
};
