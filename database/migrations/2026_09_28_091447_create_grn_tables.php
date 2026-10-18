<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // old tbl2_grn_master
        Schema::create('t_grn_master', function (Blueprint $table) {
            $table->id();                                                // ID
            $table->string('grn_no', 20)->nullable()->unique();         // GRNNo
            $table->string('ref_data', 25)->nullable();                 // RefData (document no)
            $table->unsignedBigInteger('vendor_id')->default(0);        // VendorID
            $table->date('grn_date')->nullable();                       // GRNDate
            $table->decimal('total', 15, 4)->default(0);                // Total
            $table->decimal('grand_total', 15, 4)->default(0);          // GrandTotal
            $table->unsignedBigInteger('location_id')->default(0);      // Location
            $table->unsignedBigInteger('receive_by')->default(0);       // ReceiveBy (client user = added_by)
            $table->unsignedBigInteger('invoice_status_id')->default(0);// InvoiceStatus
            $table->unsignedBigInteger('grn_status_id')->default(2);    // GRNStatus
            $table->decimal('sub_total', 15, 4)->default(0);            // SubTotal
            $table->unsignedBigInteger('atc_vt_id')->default(0);        // ATC_VT
            $table->decimal('2307_vt', 15, 4)->default(0);              // 2307_VT
            $table->unsignedBigInteger('atc_it_id')->default(0);        // ATC_IT
            $table->decimal('2307_it', 15, 4)->default(0);              // 2307_IT
            $table->unsignedBigInteger('discount_type_id')->default(0); // DiscountType
            $table->decimal('discount_amount', 15, 4)->default(0);      // DiscountAmount
            $table->string('attachment', 255)->nullable();              // Attachment
            $table->string('declared_month', 20)->nullable();           // DeclaredMonth
            $table->boolean('is_temp')->default(false);                 // Temp
            $table->string('2307_vt_declared_month', 15)->nullable();   // 2307_VT_DeclaredMonth
            $table->string('2307_it_declared_month', 15)->nullable();   // 2307_IT_DeclaredMonth
            $table->timestamps();                                        // DateAdded / DateUpdated

            $table->index(['receive_by', 'declared_month'], 'idx_grnm_by_month');
            $table->index(['receive_by', 'grn_date'], 'idx_grnm_by_date');
        });

        // old tbl2_grn_detail
        Schema::create('t_grn_detail', function (Blueprint $table) {
            $table->id();                                                // ID
            $table->unsignedBigInteger('item_id')->default(0);          // ItemID
            $table->decimal('qty', 15, 2)->default(0);                  // Qty
            $table->decimal('price', 15, 4)->default(0);                // Price
            $table->unsignedBigInteger('unit_type_id')->default(0);     // UnitType
            $table->string('account_code', 20)->nullable();             // AccountCode
            $table->decimal('total', 15, 4)->default(0);                // Total
            $table->decimal('discount_amount', 15, 4)->default(0);      // DiscountAmount
            $table->string('po_number', 40)->nullable();                // PONumber
            $table->unsignedBigInteger('vendor_id')->default(0);        // VendorID
            $table->string('grn_no', 20)->nullable();                   // GRNNo → t_grn_master.grn_no
            $table->date('grn_date')->nullable();                       // GRNDate
            $table->decimal('receive_qty', 15, 4)->default(0);          // ReceiveQty
            $table->unsignedBigInteger('allocation_id')->default(0);    // Allocation
            $table->unsignedBigInteger('po_id')->default(0);            // POID
            $table->boolean('is_imported')->default(false);             // Imported
            $table->string('invoice_no', 40)->nullable();            // APInvoiceNo
            $table->unsignedBigInteger('warehouse_id')->default(0);     // WareHouse
            $table->decimal('input_vat', 15, 4)->default(0);            // inputVAT
            $table->decimal('net_of_vat', 15, 4)->default(0);           // NetOfVAT
            $table->decimal('vat_exempt', 15, 4)->default(0);           // VATExempt
            $table->decimal('non_vat', 15, 4)->default(0);              // NonVAT
            $table->decimal('zero_rated', 15, 4)->default(0);           // ZeroRated
            $table->boolean('is_temp')->default(false);                 // Temp
            $table->string('2307_vt_declared_month', 15)->nullable();   // 2307_VT_DeclaredMonth
            $table->string('2307_it_declared_month', 15)->nullable();   // 2307_IT_DeclaredMonth
            $table->unsignedBigInteger('receive_by')->default(0);       // ReceiveBy
            $table->unsignedBigInteger('grn_status_id')->default(0);    // GRNStatus
            $table->string('declared_month', 20)->nullable();           // DeclaredMonth
            $table->timestamps();                                        // DateAdded / DateUpdated

            $table->foreign('grn_no', 'fk_grnd_grn_no')
                ->references('grn_no')->on('t_grn_master')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->index(['receive_by', 'declared_month'], 'idx_grnd_by_month');
            $table->index(['receive_by', 'grn_date'], 'idx_grnd_by_date');
            $table->index('invoice_no', 'idx_grnd_ap_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_grn_detail');
        Schema::dropIfExists('t_grn_master');
    }
};