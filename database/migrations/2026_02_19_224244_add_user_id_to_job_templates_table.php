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

            // Add user_id column (nullable for backward compatibility)
            $table->unsignedBigInteger('user_id')
                  ->nullable()
                  ->after('id');

            // Add index for performance
            $table->index('user_id');

            // Add foreign key constraint (recommended)
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade'); // or ->nullOnDelete()
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_templates', function (Blueprint $table) {

            // Drop foreign key first
            $table->dropForeign(['user_id']);

            // Drop index
            $table->dropIndex(['user_id']);

            // Drop column
            $table->dropColumn('user_id');
        });
    }
};
