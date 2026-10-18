<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory → Stocks → ADD (old tbl2_inventory_adjustment + tbl2_inventory_adjustment_reasons)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_inventory_adjustment_reasons')) {
            Schema::create('m_inventory_adjustment_reasons', function (Blueprint $t) {
                $t->id();                                  // reason_id
                $t->string('name', 150);                   // reason_name
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_inventory_adjustment')) {
            Schema::create('t_inventory_adjustment', function (Blueprint $t) {
                $t->id();                                              // ID
                $t->date('transaction_date');                          // TransactionDate
                $t->string('declared_month', 7)->nullable();           // DeclaredMonth
                $t->unsignedBigInteger('item_id')->index();            // ItemID
                $t->string('account_code', 20)->nullable();           // AccountCode (item inventory account)
                $t->decimal('qty', 15, 4)->default(0);                 // Qty  (+ add / − remove)
                $t->unsignedBigInteger('reason_id')->default(0);       // ReasonID
                $t->unsignedBigInteger('added_by')->default(0);        // AddedBy
                $t->unsignedBigInteger('emp_id')->default(0);          // EmpID
                $t->timestamps();
                $t->index(['added_by', 'item_id', 'transaction_date'], 'idx_invadj_by_item_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_inventory_adjustment');
        Schema::dropIfExists('m_inventory_adjustment_reasons');
    }
};
