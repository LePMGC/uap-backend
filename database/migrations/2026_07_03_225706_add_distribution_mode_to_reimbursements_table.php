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
        Schema::table('reimbursements', function (Blueprint $table) {
            // Adds the enum column restricting inputs to specific FE schema modes
            $table->enum('distribution_mode', ['SINGLE_SINGLE', 'MANY_SINGLE', 'MANY_MANY'])
                  ->nullable() // Permissive initially if old data exists, or set a default
                  ->after('reimbursement_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn('distribution_mode');
        });
    }
};
