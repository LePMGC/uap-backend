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
        Schema::table('job_batches', function (Blueprint $table) {
            // Adding a nullable user_id column referencing your users table
            $table->foreignId('user_id')
                  ->nullable()
                  ->after('name') // Positions it clearly near the top metadata
                  ->constrained('users')
                  ->nullOnDelete(); // Keeps the batch record even if a user is deleted
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_batches', function (Blueprint $table) {
            // Drop the foreign key constraint first, then the column
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
