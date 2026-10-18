<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('t_sales_invoice_master', function (Blueprint $t) {
            $t->index(['added_by', 'invoice_no'], 'idx_sim_added_invoice');
        });
        Schema::table('m_charts_of_account', function (Blueprint $t) {
            $t->index(['added_by', 'account_code'], 'idx_coa_added_code');
        });
    }

    public function down(): void
    {
        Schema::table('t_sales_invoice_master', fn (Blueprint $t) => $t->dropIndex('idx_sim_added_invoice'));
        Schema::table('m_charts_of_account', fn (Blueprint $t) => $t->dropIndex('idx_coa_added_code'));
    }
};
