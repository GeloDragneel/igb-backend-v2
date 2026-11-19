<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales Order = same tables as the sales invoice
 *   t_sales_order_master  ← copy of t_sales_invoice_master  + so_number (the order's own number)
 *   t_sales_order_detail  ← copy of t_sales_invoice_detail  + so_number (line → its order)
 *   invoice_no (already in both copies) = the sales invoice made from this order (empty until invoiced)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_sales_order_master')) {
            DB::statement('CREATE TABLE t_sales_order_master LIKE t_sales_invoice_master');
            Schema::table('t_sales_order_master', function (Blueprint $t) {
                $t->string('so_number', 50)->nullable()->after('id')->index();
            });
            // empty until the order is invoiced
            DB::statement('ALTER TABLE t_sales_order_master MODIFY invoice_no VARCHAR(100) NULL DEFAULT NULL');
            Schema::table('t_sales_order_master', fn (Blueprint $t) => $t->index('invoice_no', 'so_master_invoice_no_idx'));
        }

        if (!Schema::hasTable('t_sales_order_detail')) {
            DB::statement('CREATE TABLE t_sales_order_detail LIKE t_sales_invoice_detail');
            Schema::table('t_sales_order_detail', function (Blueprint $t) {
                $t->string('so_number', 50)->nullable()->after('id')->index();
            });
            DB::statement('ALTER TABLE t_sales_order_detail MODIFY invoice_no VARCHAR(100) NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_sales_order_detail');
        Schema::dropIfExists('t_sales_order_master');
    }
};