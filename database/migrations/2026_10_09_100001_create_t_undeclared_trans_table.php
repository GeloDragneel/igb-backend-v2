<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT Summary → E-BIR "Undeclare" (old accounting_dbms_new_2.tbl2_undeclared_trans)
 *
 *   old column          new column
 *   ID                  id
 *   Report              report                 'EBIR'
 *   ReferenceNo         reference_no           invoice no. that is moved
 *   OldDeclaredMonth    old_declared_month     YYYY-MM it was declared in
 *   NewDeclaredMonth    new_declared_month     YYYY-MM it moves to
 *   NewWHDeclaredMonth  new_wh_declared_month  YYYY-MM for the 2307 VT / IT
 *   ClientID            client_id              m_client.id
 *   DateAdded           created_at
 *   DateUpdated         updated_at
 *   IsApproved          is_approved            0 = waiting, 1 = approved (the invoice was moved)
 *
 * Also t_summary_remarks (old tbl2_summary_remarks: ClientNum, DeclaredMonth, Remarks) for the Remarks button,
 * only when the new database doesn't have a remarks table yet.
 */
return new class extends Migration
{
    /**
     * Use the same collation as the invoice tables: reference_no is joined to invoice_no,
     * and MySQL refuses to compare two different collations ("Illegal mix of collations").
     */
    private function collationOf(string $table, string $column): ?array
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') return null;
        $r = DB::selectOne('SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
        return ($r && $r->cs && $r->co) ? [$r->cs, $r->co] : null;
    }

    public function up(): void
    {
        $coll = $this->collationOf('t_sales_invoice_master', 'invoice_no');
        $client = $this->collationOf('m_client', 'client_num') ?? $coll;

        if (!Schema::hasTable('t_undeclared_trans')) {
            Schema::create('t_undeclared_trans', function (Blueprint $t) use ($coll) {
                if ($coll) { $t->charset = $coll[0]; $t->collation = $coll[1]; }
                $t->id();
                $t->string('report', 20)->default('EBIR');
                $t->string('reference_no', 100);
                $t->string('old_declared_month', 7);
                $t->string('new_declared_month', 7);
                $t->string('new_wh_declared_month', 7)->nullable();
                $t->unsignedBigInteger('client_id')->default(0);
                $t->timestamp('created_at')->nullable()->useCurrent();
                $t->timestamp('updated_at')->nullable();
                $t->unsignedTinyInteger('is_approved')->default(0);

                $t->index(['reference_no', 'old_declared_month', 'is_approved'], 'undecl_ref_old_idx');
                $t->index(['client_id', 'old_declared_month', 'is_approved'], 'undecl_client_idx');
                $t->index(['is_approved', 'new_declared_month'], 'undecl_new_idx');
            });
        }

        if (!Schema::hasTable('t_summary_remarks') && !Schema::hasTable('tbl2_summary_remarks')) {
            Schema::create('t_summary_remarks', function (Blueprint $t) use ($client) {
                if ($client) { $t->charset = $client[0]; $t->collation = $client[1]; }
                $t->id();
                $t->string('client_num', 50);
                $t->string('declared_month', 7);
                $t->longText('remarks')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_undeclared_trans');
        // t_summary_remarks is kept: it may hold remarks typed in other pages
    }
};
