<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE reimbursements DROP CONSTRAINT IF EXISTS reimbursements_status_check;");

        DB::statement("
            ALTER TABLE reimbursements 
            ADD CONSTRAINT reimbursements_status_check 
            CHECK (status::text = ANY (ARRAY[
                'pending'::character varying, 
                'approved'::character varying, 
                'success'::character varying, 
                'rejected'::character varying, 
                'failed'::character varying,
                'cancelled'::character varying
            ]::text[]));
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE reimbursements DROP CONSTRAINT IF EXISTS reimbursements_status_check;");

        DB::statement("
            ALTER TABLE reimbursements 
            ADD CONSTRAINT reimbursements_status_check 
            CHECK (status::text = ANY (ARRAY[
                'pending'::character varying, 
                'approved'::character varying, 
                'success'::character varying, 
                'rejected'::character varying, 
                'failed'::character varying
            ]::text[]));
        ");
    }
};
