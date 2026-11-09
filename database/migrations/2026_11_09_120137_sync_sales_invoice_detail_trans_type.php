<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * t_sales_invoice_detail.trans_type is ALWAYS the same as its t_sales_invoice_master.trans_type:
 *   - master updated        → all its detail lines follow
 *   - detail line inserted  → takes the master's value
 *   - detail line updated   → forced back to the master's value
 * Also fixes the rows that are already different.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->down();

        DB::unprepared("
            CREATE TRIGGER trg_sim_trans_type_au
            AFTER UPDATE ON t_sales_invoice_master
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.trans_type <=> NEW.trans_type) OR NOT (OLD.invoice_no <=> NEW.invoice_no) THEN
                    UPDATE t_sales_invoice_detail
                       SET trans_type = NEW.trans_type
                     WHERE invoice_no = NEW.invoice_no;
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_sid_trans_type_bi
            BEFORE INSERT ON t_sales_invoice_detail
            FOR EACH ROW
            BEGIN
                DECLARE ms VARCHAR(50);
                SELECT trans_type INTO ms FROM t_sales_invoice_master WHERE invoice_no = NEW.invoice_no LIMIT 1;
                IF ms IS NOT NULL THEN
                    SET NEW.trans_type = ms;
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_sid_trans_type_bu
            BEFORE UPDATE ON t_sales_invoice_detail
            FOR EACH ROW
            BEGIN
                DECLARE ms VARCHAR(50);
                SELECT trans_type INTO ms FROM t_sales_invoice_master WHERE invoice_no = NEW.invoice_no LIMIT 1;
                IF ms IS NOT NULL THEN
                    SET NEW.trans_type = ms;
                END IF;
            END
        ");

        DB::statement("
            UPDATE t_sales_invoice_detail d
              JOIN t_sales_invoice_master m ON m.invoice_no = d.invoice_no
               SET d.trans_type = m.trans_type
             WHERE NOT (d.trans_type <=> m.trans_type)
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sim_trans_type_au');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sid_trans_type_bi');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sid_trans_type_bu');
    }
};