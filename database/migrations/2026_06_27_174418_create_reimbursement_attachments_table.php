<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('reimbursement_attachments', function (Blueprint $table) {
            // Define primary key as a UUID string instead of auto-incrementing bigint
            $table->uuid('id')->primary();

            // Relational target foreign key matching your parent Reimbursements table UUID type
            $table->uuid('reimbursement_id');

            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_url');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->onDelete('restrict');

            $table->timestamps();

            // Explicit database index constraint link
            $table->foreign('reimbursement_id')
                  ->references('id')
                  ->on('reimbursements')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_attachments');
    }
};
