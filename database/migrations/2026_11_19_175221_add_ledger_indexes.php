<?php

use App\Models\BegBalanceAccount;
use App\Models\ChartsOfAccount;
use App\Models\GRNDetail;
use App\Models\GRNMaster;
use App\Models\Items;
use App\Models\PurchaseExpenseDetail;
use App\Models\PurchaseExpenseMaster;
use App\Models\RVoucherDetail;
use App\Models\RVoucherMaster;
use App\Models\SalesInvoiceDetail;
use App\Models\SalesInvoiceMaster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up General Ledger / Trial Balance / every report built on ledgerEntries():
 * indexes on the columns they filter and join on (added_by + declared_month / date, invoice no).
 * Safe to run again: an index is only added when the table and columns exist and it isn't there yet.
 */
return new class extends Migration
{
    /** table => [ index name => [columns] ] */
    private function plan(): array
    {
        $t = fn (string $model) => class_exists($model) ? (new $model)->getTable() : null;
        $p = [];
        foreach ([SalesInvoiceMaster::class, PurchaseExpenseMaster::class] as $m) {
            $p[$t($m)] = ['lx_by_month' => ['added_by', 'declared_month'], 'lx_by_date' => ['added_by', 'transaction_date'], 'lx_inv' => ['invoice_no']];
        }
        foreach ([SalesInvoiceDetail::class, PurchaseExpenseDetail::class] as $m) {
            $p[$t($m)] = ['lx_inv' => ['invoice_no'], 'lx_by_month' => ['added_by', 'declared_month']];
        }
        $p[$t(RVoucherMaster::class)] = ['lx_by_month' => ['added_by', 'declared_month'], 'lx_by_date' => ['added_by', 'rv_date'], 'lx_rv' => ['rvoucher_no']];
        $p[$t(RVoucherDetail::class)] = ['lx_by_month' => ['added_by', 'declared_month'], 'lx_by_date' => ['added_by', 'rv_date']];
        $p[$t(GRNMaster::class)]      = ['lx_by_month' => ['receive_by', 'declared_month'], 'lx_by_date' => ['receive_by', 'grn_date'], 'lx_grn' => ['grn_no']];
        $p[$t(GRNDetail::class)]      = ['lx_grn' => ['grn_no']];
        $p['t_general_journal']       = ['lx_by_month' => ['added_by', 'declared_month'], 'lx_by_date' => ['added_by', 'transaction_date']];
        $p[$t(ChartsOfAccount::class)] = ['lx_by' => ['added_by', 'account_code']];
        $p[$t(Items::class)]           = ['lx_by' => ['added_by']];
        $p[$t(BegBalanceAccount::class)] = ['lx_by_month' => ['added_by', 'declared_month']];
        unset($p['']);
        return array_filter($p, fn ($v, $k) => $k, ARRAY_FILTER_USE_BOTH);
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn ($r) => $r->Key_name === $name);
    }

    public function up(): void
    {
        foreach ($this->plan() as $table => $indexes) {
            if (!Schema::hasTable($table)) continue;
            foreach ($indexes as $name => $cols) {
                if ($this->hasIndex($table, $name)) continue;
                if (array_filter($cols, fn ($c) => !Schema::hasColumn($table, $c))) continue;
                $list = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$list})");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->plan() as $table => $indexes) {
            if (!Schema::hasTable($table)) continue;
            foreach (array_keys($indexes) as $name) {
                if ($this->hasIndex($table, $name)) DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }
};