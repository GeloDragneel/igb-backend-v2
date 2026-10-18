<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // old tbl2_rvoucher_master
        Schema::create('t_rvoucher_master', function (Blueprint $table) {
            $table->id();                                                // ID
            $table->unsignedBigInteger('customer_id')->default(0);      // CustomerID
            $table->string('rvoucher_no', 25)->nullable();              // RVoucherNo
            $table->string('document_no', 40)->nullable();              // DocumentNo
            $table->string('invoice_no', 40)->nullable();               // InvoiceNo
            $table->date('rv_date')->nullable();                        // RVDate
            $table->string('rv_status', 15)->nullable();                // RVStatus
            $table->string('account_code', 15)->nullable();             // AccountCode
            $table->decimal('sub_total', 15, 4)->default(0);            // SubTotal
            $table->decimal('total', 15, 4)->default(0);                // Total
            $table->string('bank', 20)->nullable();                     // Bank
            $table->unsignedBigInteger('added_by')->default(0);         // AddedBy
            $table->string('attachment', 255)->nullable();              // Attachment
            $table->string('declared_month', 10)->nullable();           // DeclaredMonth
            $table->unsignedBigInteger('atc_vt_id')->nullable()->default(0); // ATC_VT
            $table->decimal('2307_vt', 15, 4)->default(0);              // 2307_VT
            $table->unsignedBigInteger('atc_it_id')->nullable()->default(0); // ATC_IT
            $table->decimal('2307_it', 15, 4)->default(0);              // 2307_IT
            $table->string('2307_vt_declared_month', 10)->nullable();   // 2307_VT_DeclaredMonth
            $table->string('2307_it_declared_month', 10)->nullable();   // 2307_IT_DeclaredMonth
            $table->timestamps();                                        // DateAdded / DateUpdated

            $table->index(['added_by', 'declared_month'], 'idx_rvm_added_month');
            $table->index('rvoucher_no', 'idx_rvm_rvno');
            $table->index('invoice_no', 'idx_rvm_invoice');
        });

        // old tbl2_rvoucher_detail
        Schema::create('t_rvoucher_detail', function (Blueprint $table) {
            $table->id(); // ID
            $table->string('account_code', 15)->nullable(); // AccountCode
            $table->unsignedBigInteger('customer_id')->default(0); // CustomerID
            $table->string('rvoucher_no', 25)->nullable(); // RVoucherNo
            $table->string('invoice_no', 40)->nullable(); // InvoiceNo
            $table->date('rv_date')->nullable(); // RVDate
            $table->decimal('sub_total', 15, 4)->default(0); // SubTotal
            $table->unsignedBigInteger('inv_master_id')->default(0);    // InvMasterID
            $table->string('rv_master_id', 255)->nullable(); // RVMasterID (varchar in the old table)
            $table->string('declared_month', 10)->nullable(); // DeclaredMonth
            $table->unsignedBigInteger('atc_vt_id')->nullable()->default(0); // ATC_VT
            $table->decimal('2307_vt', 15, 4)->default(0); // 2307_VT
            $table->unsignedBigInteger('atc_it_id')->nullable()->default(0); // ATC_IT
            $table->decimal('2307_it', 15, 4)->default(0); // 2307_IT
            $table->string('2307_vt_declared_month', 10)->nullable(); // 2307_VT_DeclaredMonth
            $table->string('2307_it_declared_month', 10)->nullable();   // 2307_IT_DeclaredMonth
            $table->unsignedBigInteger('added_by')->default(0); // AddedBy
            $table->timestamps(); // DateAdded / DateUpdated

            $table->index('rvoucher_no', 'idx_rvd_rvno');
            $table->index(['added_by', 'invoice_no'], 'idx_rvd_added_invoice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_rvoucher_detail');
        Schema::dropIfExists('t_rvoucher_master');
    }
};