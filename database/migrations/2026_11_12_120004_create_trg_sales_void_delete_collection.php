<?php
// php artisan make:migration create_trg_sales_void_delete_collection
// DB trigger: t_sales_invoice_master → VOID  ⇒  delete its collections (t_rvoucher_detail / t_rvoucher_master)
// Works for every way of voiding (DB::table, model, ticket approval, SQL).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sales_void_delete_collection');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_sales_void_delete_collection
AFTER UPDATE ON t_sales_invoice_master
FOR EACH ROW
BEGIN
    IF NOT (OLD.invoice_status_id <=> NEW.invoice_status_id)
       AND NEW.invoice_status_id IN (SELECT id FROM m_invoice_status WHERE UPPER(name) LIKE 'VOID%') THEN

        -- receipts of this invoice that have no lines for other invoices
        DELETE M FROM t_rvoucher_master M
        WHERE M.added_by = NEW.added_by
          AND (M.invoice_no = NEW.invoice_no
               OR M.rvoucher_no IN (SELECT D.rvoucher_no FROM t_rvoucher_detail D
                                    WHERE D.added_by = NEW.added_by AND D.invoice_no = NEW.invoice_no))
          AND NOT EXISTS (SELECT 1 FROM t_rvoucher_detail X
                          WHERE X.added_by = NEW.added_by AND X.rvoucher_no = M.rvoucher_no
                            AND X.invoice_no <> NEW.invoice_no);

        -- collection lines of this invoice
        DELETE FROM t_rvoucher_detail
        WHERE added_by = NEW.added_by AND invoice_no = NEW.invoice_no;
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sales_void_delete_collection');
    }
};