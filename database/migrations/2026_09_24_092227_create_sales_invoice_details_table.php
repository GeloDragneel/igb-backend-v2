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
        Schema::create('t_sales_invoice_detail', function (Blueprint $table) {
            $table->id();

            // Link to the invoice master
            $table->string('invoice_no', 60);
            $table->foreign('invoice_no')->references('invoice_no')->on('t_sales_invoice_master')->cascadeOnUpdate()->cascadeOnDelete();
            // Item
            $table->foreignId('item_id')->nullable()->constrained('m_items')->cascadeOnUpdate()->restrictOnDelete();

            $table->unsignedBigInteger('unit_type_id')->nullable();
            $table->decimal('quantity', 10, 2)->default(0.00);
            $table->decimal('unit_price', 12, 4)->default(0.0000);
            $table->decimal('amount', 12, 4)->default(0.0000);
            $table->decimal('discount_amount', 12, 4)->default(0.0000);

            // VAT breakdown
            $table->decimal('input_vat', 12, 4)->default(0.0000);
            $table->decimal('net_of_vat', 12, 4)->default(0.0000);
            $table->decimal('vat_exempt', 12, 4)->default(0.0000);
            $table->decimal('non_vat', 12, 4)->default(0.0000);
            $table->decimal('zero_rated', 12, 4)->default(0.0000);

            // Currency
            $table->string('currency', 10)->default('PHP');
            $table->decimal('ex_rate', 12, 4)->default(1.0000);

            // Cost / inventory
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->decimal('cost_each', 12, 4)->default(0.0000);
            $table->decimal('total_cost', 12, 4)->default(0.0000);
            $table->unsignedBigInteger('allocation_id')->nullable();
            $table->string('account_code', 10)->nullable();

            // BIR 2307
            $table->char('2307_vt_declared_month', 7)->nullable();
            $table->char('2307_it_declared_month', 7)->nullable();

            $table->tinyInteger('is_pos')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->char('declared_month', 7)->nullable();
            $table->foreignId('invoice_status_id')->nullable()->constrained('m_invoice_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('transaction_date')->nullable();
            $table->string('document_no', 100)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('m_vendor_customer')->cascadeOnUpdate()->restrictOnDelete();

            // Audit
            $table->foreignId('assigned_daid')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_auid')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('audit_status_id')->nullable()->default(1)->constrained('m_audit_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('trans_type', 15);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_sales_invoice_detail');
    }
};