<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Audit Monitoring speed: find the audit history of an invoice + audited / reverted invoices fast */
return new class extends Migration
{
    private const INDEXES = [
        't_audit_history'           => ['idx_ah_ref_status_at' => ['reference_no', 'audit_status_id', 'created_at']],
        't_sales_invoice_master'    => ['idx_sim_audit_status' => ['audit_status_id', 'added_by']],
        't_purchase_expense_master' => ['idx_pem_audit_status' => ['audit_status_id', 'added_by']],
    ];

    private function has(string $table, string $name): bool
    {
        return (bool) DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]);
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $list) {
            if (!Schema::hasTable($table)) continue;
            foreach ($list as $name => $cols) {
                if ($this->has($table, $name)) continue;
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` (`" . implode('`, `', $cols) . "`)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $list) {
            if (!Schema::hasTable($table)) continue;
            foreach (array_keys($list) as $name) {
                if ($this->has($table, $name)) DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }
};