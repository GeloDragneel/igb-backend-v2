<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lending (all dates Philippine time)
 *   m_lending_setting               loan products: interest per day / per month / fixed, penalty, payroll deductible
 *   m_lending_member                anyone who invests or borrows (an employee or an outside person)
 *   t_lending_investment            money put in by a member; earns interest until it is cashed out (anytime)
 *   t_lending_investment_txn        ledger of an investment: deposit, top-up, interest, cash-out
 *   t_lending_loan                  money lent to a member (employee loans can be deducted in payroll)
 *   t_lending_loan_schedule         the dues of a loan
 *   t_lending_loan_payment          every payment: from payroll or manual entry
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_lending_setting', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->comment('e.g. Salary Loan, Emergency Loan, Investment 3%/month');
            $t->string('applies_to', 20)->default('loan')->comment('loan | investment');
            $t->string('interest_type', 20)->comment('per_day | per_month | fixed');
            $t->decimal('interest_rate', 9, 6)->default(0)->comment('0.03 = 3% per month, 0.001 = 0.1% per day');
            $t->decimal('fixed_interest', 12, 2)->default(0)->comment('used when interest_type = fixed');
            $t->decimal('min_amount', 12, 2)->default(0);
            $t->decimal('max_amount', 12, 2)->nullable();
            $t->unsignedSmallInteger('max_term_months')->nullable();
            $t->decimal('penalty_rate_per_day', 9, 6)->default(0)->comment('on the overdue amount');
            $t->unsignedSmallInteger('lock_in_days')->default(0)->comment('investment: no interest if cashed out earlier');
            $t->boolean('payroll_deductible')->default(true);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('m_lending_member', function (Blueprint $t) {
            $t->id();
            $t->string('member_type', 20)->default('employee')->comment('employee | outside');
            $t->unsignedBigInteger('employee_id')->nullable()->unique()->comment('m_employee.id when an employee');
            $t->string('name', 150);
            $t->string('contact_no', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('bank_name', 100)->nullable()->comment('for cash-outs');
            $t->string('account_no', 50)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('t_lending_investment', function (Blueprint $t) {
            $t->id();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('member_id')->constrained('m_lending_member');
            $t->foreignId('lending_setting_id')->nullable()->constrained('m_lending_setting');
            // terms copied from the setting (a later setting change does not touch this investment)
            $t->string('interest_type', 20)->comment('per_day | per_month | fixed');
            $t->decimal('interest_rate', 9, 6)->default(0);
            $t->decimal('fixed_interest', 12, 2)->default(0);
            $t->unsignedSmallInteger('lock_in_days')->default(0);
            $t->date('date_invested');
            $t->decimal('total_invested', 14, 2)->default(0)->comment('all deposits + top-ups');
            $t->decimal('principal_balance', 14, 2)->default(0)->comment('still invested');
            $t->decimal('interest_balance', 14, 2)->default(0)->comment('earned, not yet cashed out');
            $t->decimal('total_interest_earned', 14, 2)->default(0);
            $t->decimal('total_cashed_out', 14, 2)->default(0);
            $t->date('last_accrual_date')->comment('interest is counted up to this date');
            $t->string('status', 20)->default('active')->comment('active | closed');
            $t->date('closed_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['member_id', 'status']);
        });

        Schema::create('t_lending_investment_txn', function (Blueprint $t) {
            $t->id();
            $t->foreignId('investment_id')->constrained('t_lending_investment')->cascadeOnDelete();
            $t->string('txn_type', 20)->comment('deposit | top_up | interest | cashout_interest | cashout_principal');
            $t->date('txn_date');
            $t->decimal('amount', 14, 2);
            $t->unsignedInteger('days')->nullable()->comment('interest: days counted');
            $t->decimal('principal_after', 14, 2);
            $t->decimal('interest_after', 14, 2);
            $t->string('remarks', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['investment_id', 'txn_date']);
        });

        Schema::create('t_lending_loan', function (Blueprint $t) {
            $t->id();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('member_id')->constrained('m_lending_member');
            $t->unsignedBigInteger('employee_id')->nullable()->index()->comment('copied from the member, for payroll');
            $t->foreignId('lending_setting_id')->nullable()->constrained('m_lending_setting');
            $t->string('interest_type', 20)->comment('per_day | per_month | fixed');
            $t->decimal('interest_rate', 9, 6)->default(0);
            $t->decimal('fixed_interest', 12, 2)->default(0);
            $t->decimal('penalty_rate_per_day', 9, 6)->default(0);
            $t->decimal('principal', 14, 2);
            $t->unsignedSmallInteger('term_months');
            $t->string('due_frequency', 20)->default('monthly')->comment('monthly | semi_monthly (follows payroll cut-offs)');
            $t->date('date_released')->nullable();
            $t->date('first_due_date')->nullable();
            $t->date('maturity_date')->nullable();
            $t->decimal('total_interest', 14, 2)->default(0);
            $t->decimal('total_payable', 14, 2)->default(0);
            $t->decimal('amount_per_due', 14, 2)->default(0);
            $t->decimal('total_paid', 14, 2)->default(0);
            $t->decimal('balance', 14, 2)->default(0);
            $t->string('deduction_mode', 20)->default('payroll')->comment('payroll | manual');
            $t->string('purpose', 255)->nullable();
            $t->string('status', 20)->default('pending')->comment('pending | approved | released | paid | rejected | cancelled');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('approver_remarks', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['member_id', 'status']);
        });

        Schema::create('t_lending_loan_schedule', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loan_id')->constrained('t_lending_loan')->cascadeOnDelete();
            $t->unsignedSmallInteger('due_no');
            $t->date('due_date');
            $t->decimal('principal_due', 14, 2);
            $t->decimal('interest_due', 14, 2);
            $t->decimal('amount_due', 14, 2);
            $t->decimal('penalty', 14, 2)->default(0);
            $t->decimal('amount_paid', 14, 2)->default(0);
            $t->string('status', 20)->default('unpaid')->comment('unpaid | partial | paid');
            $t->date('paid_date')->nullable();
            $t->timestamps();
            $t->unique(['loan_id', 'due_no']);
            $t->index(['due_date', 'status']);
        });

        Schema::create('t_lending_loan_payment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loan_id')->constrained('t_lending_loan');
            $t->foreignId('schedule_id')->nullable()->constrained('t_lending_loan_schedule');
            $t->date('payment_date');
            $t->decimal('amount', 14, 2);
            $t->decimal('penalty_paid', 14, 2)->default(0);
            $t->string('source', 20)->default('manual')->comment('payroll | manual');
            $t->unsignedBigInteger('payroll_item_id')->nullable()->comment('t_payroll_item.id when deducted in payroll');
            $t->string('or_number', 50)->nullable();
            $t->string('remarks', 255)->nullable();
            $t->unsignedBigInteger('received_by')->nullable();
            $t->timestamps();
            $t->index(['loan_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_lending_loan_payment');
        Schema::dropIfExists('t_lending_loan_schedule');
        Schema::dropIfExists('t_lending_loan');
        Schema::dropIfExists('t_lending_investment_txn');
        Schema::dropIfExists('t_lending_investment');
        Schema::dropIfExists('m_lending_member');
        Schema::dropIfExists('m_lending_setting');
    }
};
