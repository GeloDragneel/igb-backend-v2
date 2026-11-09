<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * t_purchase_expense_detail.record_status is ALWAYS the same as its t_purchase_expense_master.record_status:
 *   - master updated        → all its detail lines follow
 *   - detail line inserted  → takes the master's value
 *   - detail line updated   → forced back to the master's value
 * Also fixes the rows that are already different.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_pem_record_status_au');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ped_record_status_bi');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ped_record_status_bu');

        DB::unprepared("
            CREATE TRIGGER trg_pem_record_status_au
            AFTER UPDATE ON t_purchase_expense_master
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.record_status <=> NEW.record_status) OR NOT (OLD.invoice_no <=> NEW.invoice_no) THEN
                    UPDATE t_purchase_expense_detail
                       SET record_status = NEW.record_status
                     WHERE invoice_no = NEW.invoice_no;
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_ped_record_status_bi
            BEFORE INSERT ON t_purchase_expense_detail
            FOR EACH ROW
            BEGIN
                DECLARE ms VARCHAR(50);
                SELECT record_status INTO ms FROM t_purchase_expense_master WHERE invoice_no = NEW.invoice_no LIMIT 1;
                IF ms IS NOT NULL THEN
                    SET NEW.record_status = ms;
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_ped_record_status_bu
            BEFORE UPDATE ON t_purchase_expense_detail
            FOR EACH ROW
            BEGIN
                DECLARE ms VARCHAR(50);
                SELECT record_status INTO ms FROM t_purchase_expense_master WHERE invoice_no = NEW.invoice_no LIMIT 1;
                IF ms IS NOT NULL THEN
                    SET NEW.record_status = ms;
                END IF;
            END
        ");

        DB::statement("
            UPDATE t_purchase_expense_detail d
              JOIN t_purchase_expense_master m ON m.invoice_no = d.invoice_no
               SET d.record_status = m.record_status
             WHERE NOT (d.record_status <=> m.record_status)
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_pem_record_status_au');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ped_record_status_bi');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ped_record_status_bu');
    }
};