<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for Reports → VAT Summary (the list and the E-BIR popup read by login + declared month).
 * Each one is only added when the table + columns exist and no index already starts with those columns.
 */
return new class extends Migration
{
    private array $indexes = [
        't_sales_invoice_detail'    => [['added_by', 'declared_month'], ['invoice_no']],
        't_sales_invoice_master'    => [['added_by', '2307_vt_declared_month'], ['invoice_no']],
        't_purchase_expense_detail' => [['added_by', 'declared_month'], ['invoice_no']],
        't_purchase_expense_master' => [['invoice_no']],
        't_grn_detail'              => [['receive_by', 'declared_month'], ['grn_no']],
        't_grn_master'              => [['grn_no']],
        't_importation_detail'      => [['added_by', 'declared_month'], ['invoice_no']],
        'm_user_client'             => [['caid']],
        'm_client_account'          => [['client_num']],
    ];

    private function name(string $table, array $cols): string
    {
        return 'vat_' . substr(md5($table . implode('_', $cols)), 0, 10);
    }

    public function up(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (!Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            $existing = collect(Schema::getIndexes($table))->map(fn ($i) => implode(',', $i['columns']))->all();
            foreach ($sets as $cols) {
                if (array_diff($cols, $columns)) continue;                      // a column is missing
                $want = implode(',', $cols);
                foreach ($existing as $e) {
                    if ($e === $want || str_starts_with($e, $want . ',')) continue 2; // an index already covers it
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $this->name($table, $cols)));
                $existing[] = $want;
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (!Schema::hasTable($table)) continue;
            $names = collect(Schema::getIndexes($table))->pluck('name')->all();
            foreach ($sets as $cols) {
                $n = $this->name($table, $cols);
                if (in_array($n, $names, true)) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($n));
            }
        }
    }
};
