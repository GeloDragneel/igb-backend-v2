<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIR Return → General Journal (instead of an Expenses bill)
 *   t_bir_return_filing  + jv_no (the General Journal reference) + bill_account (the tax payable account)
 *   t_bir_return_payment   one row per payment (its own General Journal entry: Dr payable · Cr cash/bank)
 * Only adds 2 nullable columns and 1 new table – no existing data is changed.
 * Old filings (Expenses bills) keep working with Pay Bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_bir_return_filing', function (Blueprint $t) {
            if (!Schema::hasColumn('t_bir_return_filing', 'jv_no')) $t->string('jv_no', 60)->nullable()->after('invoice_no');
            if (!Schema::hasColumn('t_bir_return_filing', 'bill_account')) $t->string('bill_account', 20)->nullable()->after('jv_no');
        });

        if (!Schema::hasTable('t_bir_return_payment')) {
            Schema::create('t_bir_return_payment', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('filing_id')->index();
                $t->unsignedBigInteger('added_by')->index();
                $t->string('jv_no', 60);                    // General Journal reference of this payment
                $t->date('pay_date');
                $t->decimal('amount', 18, 2);
                $t->string('bank', 20);                     // cash / bank account code
                $t->string('payment_type', 50)->nullable();
                $t->string('ref_data', 255)->nullable();
                $t->string('document_no', 100)->nullable();
                $t->string('attachment', 255)->nullable();  // S3 BIR_Return/<form>/<jv_no>-<attachment>
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_bir_return_payment');
        Schema::table('t_bir_return_filing', function (Blueprint $t) {
            if (Schema::hasColumn('t_bir_return_filing', 'bill_account')) $t->dropColumn('bill_account');
            if (Schema::hasColumn('t_bir_return_filing', 'jv_no')) $t->dropColumn('jv_no');
        });
    }
};