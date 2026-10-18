<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee HR data (employee_id = m_employee.id)
 *   t_employee_salary        every rate the employee ever had (history) — one row is the default for payroll
 *                            (m_employee.salary_month / salary_day keep the current default, synced by the model)
 *   t_employee_shift_log     every shift change (m_employee.shift_id is the current shift)
 *   t_employee_bank          bank accounts for payroll — one is the default
 *   t_employee_leave_credit  leave days allowed per employee, per type, per year
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_employee_salary', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('salary_type', 10)->comment('daily | monthly — how the employee is paid');
            $t->decimal('daily_rate', 12, 2)->default(0);
            $t->decimal('monthly_rate', 12, 2)->default(0);
            $t->decimal('allowance', 12, 2)->default(0)->comment('per month, not taxable / not in contributions');
            $t->decimal('work_days_per_year', 6, 2)->default(313)->comment('factor for daily ↔ monthly (313, 261, 365 …)');
            $t->string('description', 150)->nullable()->comment('e.g. Main rate, Project rate');
            $t->string('reason', 255)->nullable()->comment('e.g. DOLE Wage Order NCR-26, promotion');
            $t->date('effective_from');
            $t->date('effective_to')->nullable()->comment('null = still in use');
            $t->boolean('is_default')->default(false)->comment('the rate payroll uses');
            $t->string('status', 20)->default('approved')->comment('pending | approved | rejected');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'is_default']);
            $t->index(['employee_id', 'effective_from']);
        });

        Schema::create('t_employee_shift_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id')->index();
            $t->unsignedBigInteger('shift_id')->nullable()->comment('m_shift.id, null = default shift');
            $t->unsignedBigInteger('previous_shift_id')->nullable();
            $t->date('effective_from');
            $t->string('reason', 255)->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
        });

        Schema::create('t_employee_bank', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id')->index();
            $t->string('bank_name', 100);
            $t->string('branch', 100)->nullable();
            $t->string('account_name', 150);
            $t->string('account_no', 50);
            $t->string('account_type', 20)->nullable()->comment('savings | checking | payroll | e-wallet');
            $t->boolean('is_default')->default(false)->comment('payroll goes here');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('t_employee_leave_credit', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');
            $t->foreignId('leave_type_id')->constrained('m_leave_type');
            $t->unsignedSmallInteger('year');
            $t->decimal('allowed_days', 5, 2)->default(0);
            $t->decimal('carried_over', 5, 2)->default(0);
            $t->decimal('used_days', 5, 2)->default(0)->comment('updated when a leave is approved / cancelled');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'leave_type_id', 'year'], 'emp_leave_credit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_employee_leave_credit');
        Schema::dropIfExists('t_employee_bank');
        Schema::dropIfExists('t_employee_shift_log');
        Schema::dropIfExists('t_employee_salary');
    }
};
