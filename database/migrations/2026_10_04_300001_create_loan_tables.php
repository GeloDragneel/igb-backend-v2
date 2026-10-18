<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task → Loan Management
 *   t_loans          one row per loan (payable = we borrowed, receivable = we lent)
 *   t_loan_payments  payments / collections, each linked to a General Journal entry
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_loans')) {
            Schema::create('t_loans', function (Blueprint $t) {
                $t->id();
                $t->string('loan_no', 30)->unique();                      // LN-261004-001
                $t->string('direction', 12)->default('payable');          // payable | receivable
                $t->unsignedBigInteger('party_id')->nullable();           // m_vendor_customer (optional)
                $t->string('party_name', 150);                            // lender / borrower
                $t->string('reference', 100)->nullable();                 // bank loan / PN no.
                $t->decimal('principal', 15, 2);
                $t->decimal('annual_rate', 8, 4)->default(0);             // percent per year, e.g. 12.0000
                $t->unsignedInteger('term_months');
                $t->string('method', 15)->default('diminishing');         // diminishing | straight
                $t->date('release_date');
                $t->date('first_due_date');
                $t->string('loan_account', 20);                           // Loans Payable / Loans Receivable
                $t->string('interest_account', 20);                       // Interest Expense / Interest Income
                $t->string('cash_account', 20)->nullable();               // default bank / cash
                $t->string('status', 12)->default('active');              // active | closed | void
                $t->string('release_jv_no', 60)->nullable();              // t_general_journal.jv_no
                $t->string('remarks', 255)->nullable();
                $t->unsignedBigInteger('added_by')->index();              // user client id
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->timestamps();
                $t->index(['added_by', 'direction', 'status']);
            });
        }

        if (!Schema::hasTable('t_loan_payments')) {
            Schema::create('t_loan_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('loan_id')->index();
                $t->string('payment_no', 30)->unique();                   // LP-261004-001
                $t->unsignedInteger('installment_no')->nullable();        // schedule row it pays
                $t->date('payment_date');
                $t->string('declared_month', 7)->nullable();
                $t->decimal('principal', 15, 2)->default(0);
                $t->decimal('interest', 15, 2)->default(0);
                $t->decimal('penalty', 15, 2)->default(0);                // booked with interest
                $t->decimal('total', 15, 2)->default(0);
                $t->string('cash_account', 20);
                $t->string('reference', 100)->nullable();                 // OR / check no.
                $t->string('status', 12)->default('posted');              // posted | void
                $t->string('jv_no', 60)->nullable();                      // t_general_journal.jv_no
                $t->unsignedBigInteger('added_by')->index();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_loan_payments');
        Schema::dropIfExists('t_loans');
    }
};
