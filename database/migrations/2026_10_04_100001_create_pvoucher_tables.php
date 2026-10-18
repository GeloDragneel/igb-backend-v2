<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Voucher (PAY BILL) – old tbl2_pvoucher_master / tbl2_pvoucher_detail
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_pvoucher_master')) {
            Schema::create('t_pvoucher_master', function (Blueprint $t) {
                $t->id();                                                // ID
                $t->string('pvoucher_no', 60)->unique();                 // PVoucherNo
                $t->date('pv_date')->nullable();                         // PVDate
                $t->unsignedBigInteger('vendor_id')->default(0);         // VendorID
                $t->string('pay_to', 255)->nullable();                   // PayTo
                $t->text('particulars')->nullable();                     // Particulars
                $t->decimal('total_amount', 15, 4)->default(0);          // Total_Amount
                $t->decimal('total_f_amount', 15, 4)->default(0);        // TotalF_Amount (foreign currency)
                $t->decimal('sub_total', 15, 4)->default(0);             // SubTotal
                $t->decimal('base_sub_total', 15, 4)->default(0);        // BaseSubTotal
                $t->string('bank', 20)->nullable();                      // Bank (cash / bank account code)
                $t->unsignedInteger('pv_status')->default(0);            // PVStatus
                $t->string('payment_type', 50)->nullable();              // PaymentType
                $t->string('ref_data', 255)->nullable();                 // RefData (check no., ref.)
                $t->string('ap_invoice_no', 60)->nullable()->index();    // APInvoiceNo
                $t->string('document_no', 100)->nullable();              // DocumentNo
                $t->dateTime('last_update')->nullable();                 // LastUpdate
                $t->unsignedBigInteger('added_by')->index();             // AddedBy (user client id)
                $t->timestamps();                                        // DateAdded / DateUpdated
                $t->index(['added_by', 'pv_date']);
            });
        }

        if (!Schema::hasTable('t_pvoucher_detail')) {
            Schema::create('t_pvoucher_detail', function (Blueprint $t) {
                $t->id();                                                // ID
                $t->string('pvoucher_no', 60)->index();                  // PVoucherNo
                $t->date('voucher_date')->nullable();                    // VoucherDate
                $t->string('acc_code', 20)->nullable();                  // Acc_Code
                $t->string('account_no', 100)->nullable();               // AccountNo
                $t->unsignedBigInteger('item_id')->default(0);           // ItemID
                $t->string('ap_invoice_no', 60)->nullable()->index();    // APInvoiceNo
                $t->unsignedBigInteger('ap_id')->default(0);             // APID (vendor invoice id)
                $t->unsignedBigInteger('po_id')->default(0);             // POID
                $t->decimal('qty', 15, 4)->default(0);                   // Qty
                $t->decimal('f_amount', 15, 4)->default(0);              // F_Amount
                $t->decimal('amount', 15, 4)->default(0);                // Amount
                $t->string('ref_data', 255)->nullable();                 // RefData
                $t->string('payment_type', 50)->nullable();              // PaymentType
                $t->timestamps();                                        // DateAdded / DateUpdated
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_pvoucher_detail');
        Schema::dropIfExists('t_pvoucher_master');
    }
};
