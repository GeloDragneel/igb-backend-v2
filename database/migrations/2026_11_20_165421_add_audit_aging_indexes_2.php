<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Monitoring speed (2): read only the picked month / day.
 *   history made in the period · invoices audited / reverted / entered in the period · invoice by its number
 * An index that already exists (same name, or the same first column for invoice_no) is skipped.
 */
return new class extends Migration
{
    private const INDEXES = [
        't_audit_history' => [
            'idx_ah_ref_status_at' => ['reference_no', 'audit_status_id', 'created_at'],
            'idx_ah_status_at'     => ['audit_status_id', 'created_at'],
        ],
        't_sales_invoice_master' => [
            'idx_sim_status_upd'   => ['audit_status_id', 'updated_at'],
            'idx_sim_status_cre'   => ['audit_status_id', 'created_at'],
            'idx_sim_invoice_no'   => ['invoice_no'],
        ],
        't_purchase_expense_master' => [
            'idx_pem_status_upd'   => ['audit_status_id', 'updated_at'],
            'idx_pem_status_cre'   => ['audit_status_id', 'created_at'],
            'idx_pem_invoice_no'   => ['invoice_no'],
        ],
    ];

    private function has(string $table, string $name, array $cols): bool
    {
        if (DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name])) return true;
        // a one-column index: any index that already starts with that column will do
        return count($cols) === 1 && (bool) DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Seq_in_index = 1", [$cols[0]]);
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $list) {
            if (!Schema::hasTable($table)) continue;
            foreach ($list as $name => $cols) {
                if ($this->has($table, $name, $cols)) continue;
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` (`" . implode('`, `', $cols) . "`)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $list) {
            if (!Schema::hasTable($table)) continue;
            foreach (array_keys($list) as $name) {
                if (DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name])) DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }
};