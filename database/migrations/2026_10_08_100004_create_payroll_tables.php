<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll
 *   t_payroll_run        one cut-off (e.g. Oct 1–15, 2026) with approval
 *   t_payroll_item       one employee in a run: the rate used, days / minutes, every total, the bank it goes to
 *   t_payroll_item_line  every earning / deduction line (OT, ND, holiday, SSS, loan …) so the payslip can be rebuilt
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_payroll_run', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique()->comment('e.g. PR-2026-10-A');
            $t->string('frequency', 20)->default('semi_monthly')->comment('semi_monthly | monthly | weekly');
            $t->date('period_from');
            $t->date('period_to');
            $t->date('pay_date');
            $t->unsignedTinyInteger('cutoff_no')->default(1)->comment('1st or 2nd cut-off of the month');
            $t->boolean('deduct_contributions')->default(true)->comment('SSS / PhilHealth / Pag-IBIG on this cut-off');
            $t->boolean('deduct_loans')->default(true);
            $t->string('status', 20)->default('draft')->comment('draft | computed | approved | released | cancelled');
            $t->decimal('total_gross', 14, 2)->default(0);
            $t->decimal('total_deductions', 14, 2)->default(0);
            $t->decimal('total_net', 14, 2)->default(0);
            $t->unsignedBigInteger('prepared_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('released_at')->nullable();
            $t->string('remarks', 255)->nullable();
            $t->timestamps();
            $t->index(['period_from', 'period_to']);
        });

        Schema::create('t_payroll_item', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained('t_payroll_run')->cascadeOnDelete();
            $t->unsignedBigInteger('employee_id');
            // rate used (copied, so later salary changes do not change old payslips)
            $t->unsignedBigInteger('employee_salary_id')->nullable()->comment('t_employee_salary.id');
            $t->string('salary_type', 10);
            $t->decimal('daily_rate', 12, 2)->default(0);
            $t->decimal('monthly_rate', 12, 2)->default(0);
            $t->decimal('hourly_rate', 12, 4)->default(0);
            // attendance summary
            $t->decimal('days_worked', 6, 2)->default(0);
            $t->decimal('days_absent', 6, 2)->default(0);
            $t->decimal('days_paid_leave', 6, 2)->default(0);
            $t->unsignedInteger('late_minutes')->default(0);
            $t->unsignedInteger('undertime_minutes')->default(0);
            $t->unsignedInteger('ot_minutes')->default(0);
            $t->unsignedInteger('night_diff_minutes')->default(0);
            // earnings
            $t->decimal('basic_pay', 12, 2)->default(0);
            $t->decimal('ot_pay', 12, 2)->default(0);
            $t->decimal('night_diff_pay', 12, 2)->default(0);
            $t->decimal('holiday_pay', 12, 2)->default(0);
            $t->decimal('leave_pay', 12, 2)->default(0);
            $t->decimal('allowance', 12, 2)->default(0);
            $t->decimal('other_earnings', 12, 2)->default(0);
            $t->decimal('gross_pay', 12, 2)->default(0);
            // deductions
            $t->decimal('late_undertime_deduction', 12, 2)->default(0);
            $t->decimal('absent_deduction', 12, 2)->default(0);
            $t->decimal('sss_ee', 10, 2)->default(0);
            $t->decimal('sss_er', 10, 2)->default(0);
            $t->decimal('sss_ec', 10, 2)->default(0);
            $t->decimal('philhealth_ee', 10, 2)->default(0);
            $t->decimal('philhealth_er', 10, 2)->default(0);
            $t->decimal('pagibig_ee', 10, 2)->default(0);
            $t->decimal('pagibig_er', 10, 2)->default(0);
            $t->decimal('taxable_income', 12, 2)->default(0);
            $t->decimal('withholding_tax', 12, 2)->default(0);
            $t->decimal('loan_deduction', 12, 2)->default(0);
            $t->decimal('other_deductions', 12, 2)->default(0);
            $t->decimal('total_deductions', 12, 2)->default(0);
            $t->decimal('net_pay', 12, 2)->default(0);
            // where it is paid
            $t->unsignedBigInteger('employee_bank_id')->nullable()->comment('t_employee_bank.id');
            $t->string('bank_name', 100)->nullable();
            $t->string('account_no', 50)->nullable();
            $t->string('status', 20)->default('draft')->comment('draft | final | hold');
            $t->string('remarks', 255)->nullable();
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id'], 'payroll_item_run_emp_unique');
            $t->index('employee_id');
        });

        Schema::create('t_payroll_item_line', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_item_id')->constrained('t_payroll_item')->cascadeOnDelete();
            $t->string('kind', 10)->comment('earning | deduction | employer');
            $t->string('code', 30)->comment('BASIC, REG_OT, ND, RH, SSS, PHIC, HDMF, WTAX, LOAN, …');
            $t->string('description', 150);
            $t->decimal('quantity', 10, 2)->nullable()->comment('hours / days / minutes');
            $t->decimal('rate', 12, 4)->nullable();
            $t->decimal('amount', 12, 2);
            $t->string('reference_type', 50)->nullable()->comment('e.g. lending_loan, overtime');
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->timestamps();
            $t->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_payroll_item_line');
        Schema::dropIfExists('t_payroll_item');
        Schema::dropIfExists('t_payroll_run');
    }
};
