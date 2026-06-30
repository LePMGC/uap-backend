<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            // Add mode columns to match front-end payloads
            $table->string('reimbursement_mode')->default('AUTO')->after('reimbursement_type');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn(['reimbursement_type', 'reimbursement_mode']);
        });
    }
};
