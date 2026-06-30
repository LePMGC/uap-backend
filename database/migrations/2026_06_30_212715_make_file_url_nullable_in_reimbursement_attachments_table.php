<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('reimbursement_attachments', function (Blueprint $table) {
            // Changes the column type rules to allow null insertions
            $table->string('file_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reimbursement_attachments', function (Blueprint $table) {
            $table->string('file_url')->nullable(false)->change();
        });
    }
};
