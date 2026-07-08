<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         |--------------------------------------------------------------------------
         | Rename approved_by_user_id -> reviewed_by_user_id
         |--------------------------------------------------------------------------
         |
         | PostgreSQL supports renaming columns directly.
         |
         */

        if (
            Schema::hasColumn('reimbursements', 'approved_by_user_id') &&
            !Schema::hasColumn('reimbursements', 'reviewed_by_user_id')
        ) {
            DB::statement(
                'ALTER TABLE reimbursements RENAME COLUMN approved_by_user_id TO reviewed_by_user_id'
            );
        }

        Schema::table('reimbursements', function (Blueprint $table) {

            if (!Schema::hasColumn('reimbursements', 'reviewed_at')) {
                $table->timestamp('reviewed_at')
                    ->nullable()
                    ->after('reviewed_by_user_id');
            }

            if (!Schema::hasColumn('reimbursements', 'rejection_reason')) {
                $table->string('rejection_reason', 255)
                    ->nullable()
                    ->after('reviewed_at');
            }
        });

        /*
         |--------------------------------------------------------------------------
         | Rename foreign key if it exists
         |--------------------------------------------------------------------------
         |
         | Ignore failures if the constraint has a different generated name.
         |
         */

        try {
            DB::statement(
                'ALTER TABLE reimbursements
                 RENAME CONSTRAINT reimbursements_approved_by_user_id_foreign
                 TO reimbursements_reviewed_by_user_id_foreign'
            );
        } catch (\Throwable $e) {
            // Ignore if constraint name differs.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (
            Schema::hasColumn('reimbursements', 'reviewed_by_user_id') &&
            !Schema::hasColumn('reimbursements', 'approved_by_user_id')
        ) {
            DB::statement(
                'ALTER TABLE reimbursements RENAME COLUMN reviewed_by_user_id TO approved_by_user_id'
            );
        }

        Schema::table('reimbursements', function (Blueprint $table) {

            if (Schema::hasColumn('reimbursements', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }

            // Do not drop rejection_reason because it may already have existed.
        });

        try {
            DB::statement(
                'ALTER TABLE reimbursements
                 RENAME CONSTRAINT reimbursements_reviewed_by_user_id_foreign
                 TO reimbursements_approved_by_user_id_foreign'
            );
        } catch (\Throwable $e) {
            // Ignore if constraint name differs.
        }
    }
};
