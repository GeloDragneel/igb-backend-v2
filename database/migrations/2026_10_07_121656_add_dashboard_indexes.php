<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_mobile_transaction', function (Blueprint $table) {
            if (!Schema::hasIndex('t_mobile_transaction', 'dsh_scan_month')) {
                $table->index(['created_at', 'assigned_daid'], 'dsh_scan_month');
            }
        });

        Schema::table('t_sales_invoice_master', function (Blueprint $table) {
            if (!Schema::hasIndex('t_sales_invoice_master', 'dsh_sales_month')) {
                $table->index(['created_at', 'assigned_daid'], 'dsh_sales_month');
            }
        });

        Schema::table('t_purchase_expense_master', function (Blueprint $table) {
            if (!Schema::hasIndex('t_purchase_expense_master', 'dsh_pe_month')) {
                $table->index(['created_at', 'assigned_daid'], 'dsh_pe_month');
            }
        });

        Schema::table('t_audit_history', function (Blueprint $table) {
            if (!Schema::hasIndex('t_audit_history', 'dsh_audit_month')) {
                $table->index(['created_at', 'auditor_id'], 'dsh_audit_month');
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_mobile_transaction', fn (Blueprint $table) => $table->dropIndex('dsh_scan_month'));
        Schema::table('t_sales_invoice_master', fn (Blueprint $table) => $table->dropIndex('dsh_sales_month'));
        Schema::table('t_purchase_expense_master', fn (Blueprint $table) => $table->dropIndex('dsh_pe_month'));
        Schema::table('t_audit_history', fn (Blueprint $table) => $table->dropIndex('dsh_audit_month'));
    }
};