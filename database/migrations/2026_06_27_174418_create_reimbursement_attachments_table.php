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
        Schema::create('reimbursement_attachments', function (Blueprint $table) {
            $table->id();
            // Swapped constraint target type to support parent UUID mapping requirements cleanly
            $table->foreignUuid('reimbursement_id')->constrained('reimbursements')->onDelete('cascade');

            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_url');

            $table->foreignId('uploaded_by_user_id')->constrained('users')->onDelete('restrict');
            $table->timestamp('uploaded_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reimbursement_attachments');
    }
};
