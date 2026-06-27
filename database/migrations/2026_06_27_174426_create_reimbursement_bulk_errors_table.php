<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('reimbursement_bulk_errors', function (Blueprint $table) {
            $table->id();

            // Relational link string matching the unique reference code in the reimbursements table
            $table->string('file_reference_id')->index();

            $table->integer('row'); // Physical row number inside the Excel/CSV workbook sheet
            $table->string('identifier')->nullable(); // The specific MSISDN that caused the error
            $table->text('reason'); // Error reason description mapping

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_bulk_errors');
    }
};
