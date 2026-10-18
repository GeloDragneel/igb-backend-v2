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
        Schema::create('m_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 50)->nullable();
            $table->string('upc_sku', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('item_class_id')->nullable()->constrained('m_item_class')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tax_type_id')->nullable()->constrained('m_tax_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('unit_type_id')->nullable()->constrained('m_unit_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->double('last_unit_cost')->default(0);
            $table->double('inventory_qty')->default(0);
            $table->double('equivalent_conversion')->default(0);
            $table->double('price')->default(0);
            $table->double('minimum_stock')->default(0);
            $table->double('reorder_quantity')->default(0);
            $table->double('allocated_qty')->default(0);
            $table->string('gl_sales_account', 15)->nullable();
            $table->string('gl_inventory_account', 15)->nullable();
            $table->string('gl_cost_of_sales_account', 15)->nullable();
            $table->string('input_vat_account', 15)->nullable();
            $table->string('output_vat_account', 15)->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_items');
    }
};
