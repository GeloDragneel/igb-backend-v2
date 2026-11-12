<?php
// php artisan make:migration create_trg_purchase_void_delete_payment
// DB trigger: t_purchase_expense_master (Purchases & Expenses) → VOID  ⇒  delete its payments (t_pvoucher_detail / t_pvoucher_master)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_purchase_void_delete_payment');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_purchase_void_delete_payment
AFTER UPDATE ON t_purchase_expense_master
FOR EACH ROW
BEGIN
    IF NOT (OLD.invoice_status_id <=> NEW.invoice_status_id)
       AND NEW.invoice_status_id IN (SELECT id FROM m_invoice_status WHERE UPPER(name) LIKE 'VOID%') THEN

        -- payment vouchers of this client that only pay this bill
        DELETE M FROM t_pvoucher_master M
        WHERE M.added_by = NEW.added_by
          AND M.pvoucher_no IN (SELECT D.pvoucher_no FROM t_pvoucher_detail D WHERE D.ap_invoice_no = NEW.invoice_no)
          AND NOT EXISTS (SELECT 1 FROM t_pvoucher_detail X
                          WHERE X.pvoucher_no = M.pvoucher_no AND NOT (X.ap_invoice_no <=> NEW.invoice_no));

        -- payment lines of this bill (this client's vouchers, or the ones just removed)
        DELETE FROM t_pvoucher_detail
        WHERE ap_invoice_no = NEW.invoice_no
          AND (pvoucher_no IN (SELECT P.pvoucher_no FROM t_pvoucher_master P WHERE P.added_by = NEW.added_by)
               OR pvoucher_no NOT IN (SELECT P2.pvoucher_no FROM t_pvoucher_master P2 WHERE P2.pvoucher_no IS NOT NULL));
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_purchase_void_delete_payment');
    }
};