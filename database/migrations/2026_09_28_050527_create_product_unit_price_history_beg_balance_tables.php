<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // old tbl2_product_unit
        Schema::create('m_product_unit', function (Blueprint $table) {
            $table->id();                                              // ID
            $table->unsignedBigInteger('product_id')->default(0);     // ProductID
            $table->unsignedBigInteger('unit_type_id')->default(0);   // UnitType
            $table->unsignedBigInteger('user_id')->default(0);        // UserID
            $table->string('description', 50)->nullable();            // Description (the "Value")
            $table->decimal('price', 15, 4)->default(0);              // Price
            $table->decimal('cost', 15, 4)->default(0);               // Cost
            $table->timestamps();                                      // DateAdded / DateUpdated

            $table->index('product_id', 'idx_pu_product');
            $table->index('user_id', 'idx_pu_user');
        });

        // old tbl2_price_history
        Schema::create('t_price_history', function (Blueprint $table) {
            $table->id();                                              // ID
            $table->unsignedBigInteger('item_id')->default(0);        // ItemID
            $table->decimal('price', 15, 4)->default(0);              // Price
            $table->timestamps();                                      // DateAdded

            $table->index('item_id', 'idx_ph_item');
        });

        // old tbl2_beg_balances_product
        Schema::create('t_beg_balance_product', function (Blueprint $table) {
            $table->id();                                              // ID
            $table->unsignedBigInteger('item_id')->default(0);        // ItemID
            $table->decimal('quantity', 15, 2)->default(0);           // Quantity
            $table->decimal('unit_cost', 15, 4)->default(0);          // UnitCost
            $table->decimal('total_cost', 15, 4)->default(0);         // TotalCost
            $table->unsignedBigInteger('added_by')->default(0);       // AddedBy
            $table->string('account_period', 100)->nullable();        // AccountPeriod
            $table->string('declared_month', 20)->nullable();         // DeclaredMonth
            $table->timestamps();                                      // DateAdded

            $table->index(['added_by', 'account_period'], 'idx_bbp_added_period');
            $table->index('item_id', 'idx_bbp_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_beg_balance_product');
        Schema::dropIfExists('t_price_history');
        Schema::dropIfExists('m_product_unit');
    }
};