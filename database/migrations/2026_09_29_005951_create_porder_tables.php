<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // old tbl2_porder_master
        Schema::create('t_porder_master', function (Blueprint $table) {
            $table->id();                                                // ID
            $table->string('po_number', 25)->nullable()->unique();      // PONumber
            $table->string('document_no', 100)->nullable();             // DocumentNo
            $table->date('po_date')->nullable();                        // PODate
            $table->unsignedBigInteger('vendor_id')->default(0);        // VendorID
            $table->decimal('total', 15, 4)->default(0);                // Total
            $table->decimal('receive_qty', 15, 4)->default(0);          // ReceiveQty
            $table->foreignId('po_status_id')->nullable()->default(1)   // POStatus → m_pos_status
                ->constrained('m_pos_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('added_by')->default(0);         // AddedBy
            $table->unsignedBigInteger('document_type_id')->default(0); // DocumentType
            $table->string('remarks', 255)->nullable();                 // Remarks
            $table->string('declared_month', 20)->nullable();           // DeclaredMonth
            $table->string('trans_type', 20)->nullable();               // TransType
            $table->string('currency', 10)->default('PHP');             // Currency
            $table->decimal('ex_rate', 12, 4)->default(1);              // ExRate
            $table->decimal('sub_total', 15, 4)->default(0);            // SubTotal
            $table->unsignedBigInteger('atc_vt_id')->default(0);        // ATC_VT
            $table->decimal('2307_vt', 15, 4)->default(0);              // 2307_VT
            $table->unsignedBigInteger('atc_it_id')->default(0);        // ATC_IT
            $table->decimal('2307_it', 15, 4)->default(0);              // 2307_IT
            $table->decimal('grand_total', 15, 4)->default(0);          // GrandTotal
            $table->unsignedBigInteger('discount_type_id')->default(0); // DiscountType
            $table->decimal('discount_amount', 15, 4)->default(0);      // DiscountAmount
            $table->string('attachment', 255)->nullable();              // Attachment
            $table->string('account_code', 20)->nullable();             // AccountCode
            $table->boolean('is_temp')->default(false);                 // Temp
            $table->string('2307_vt_declared_month', 15)->nullable();   // 2307_VT_DeclaredMonth
            $table->string('2307_it_declared_month', 15)->nullable();   // 2307_IT_DeclaredMonth
            $table->timestamps();                                        // DateAdded / DateUpdated

            $table->index(['added_by', 'declared_month'], 'idx_pom_added_month');
            $table->index(['added_by', 'po_date'], 'idx_pom_added_date');
            $table->index('vendor_id', 'idx_pom_vendor');
        });

        // old tbl2_porder_detail
        Schema::create('t_porder_detail', function (Blueprint $table) {
            $table->id(); // ID
            $table->unsignedBigInteger('po_master_id')->default(0);  // POMasterID
            $table->unsignedBigInteger('item_id')->default(0); // ItemID
            $table->decimal('qty', 15, 2)->default(0);                  // Qty
            $table->decimal('price', 15, 4)->default(0);                // Price
            $table->decimal('total', 15, 4)->default(0);                // Total
            $table->unsignedBigInteger('unit_type_id')->default(0);     // UnitType
            $table->string('account_code', 20)->nullable();             // AccountCode
            $table->string('currency', 10)->default('PHP');             // Currency
            $table->decimal('ex_rate', 12, 4)->default(1);              // ExRate
            $table->date('receive_date')->nullable();                   // ReceiveDate
            $table->decimal('receive_qty', 15, 4)->default(0);          // ReceiveQty
            $table->string('po_number', 25)->nullable();                // PONumber → t_porder_master.po_number
            $table->foreignId('po_status_id')->nullable()->default(1)   // POStatus → m_pos_status
                ->constrained('m_pos_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('invoice_pv', 40)->nullable();               // InvoicePV
            $table->decimal('input_vat', 15, 4)->default(0);            // inputVAT
            $table->decimal('net_of_vat', 15, 4)->default(0);           // NetOfVAT
            $table->decimal('vat_exempt', 15, 4)->default(0);           // VATExempt
            $table->decimal('non_vat', 15, 4)->default(0);              // NonVAT
            $table->decimal('zero_rated', 15, 4)->default(0);           // ZeroRated
            $table->boolean('is_temp')->default(false);                 // Temp
            $table->decimal('discount_amount', 15, 4)->default(0);      // DiscountAmount
            $table->string('2307_vt_declared_month', 15)->nullable();   // 2307_VT_DeclaredMonth
            $table->string('2307_it_declared_month', 15)->nullable();   // 2307_IT_DeclaredMonth
            $table->date('po_date')->nullable();                        // PODate
            $table->string('document_no', 100)->nullable();             // DocumentNo
            $table->unsignedBigInteger('vendor_id')->default(0);        // VendorID
            $table->string('trans_type', 20)->nullable();               // TransType
            $table->timestamps();                                        // DateAdded / DateUpdated

            $table->foreign('po_number', 'fk_pod_po_number')
                ->references('po_number')->on('t_porder_master')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->index('po_master_id', 'idx_pod_master');
            $table->index('item_id', 'idx_pod_item');
            $table->index('invoice_pv', 'idx_pod_invoice_pv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_porder_detail');
        Schema::dropIfExists('t_porder_master');
    }
};