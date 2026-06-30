<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            // Adds 'reimbursement_type' column to match front-end exactly
            $table->string('reimbursement_type')->default('BUNDLE')->after('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn('reimbursement_type');
        });
    }
};
