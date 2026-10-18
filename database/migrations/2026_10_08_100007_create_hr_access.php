<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * m_hr_access          who is HR (create / compute payroll, approve OT and leave) and who is HR supervisor (approve payroll) — many employees
 * t_payroll_exclusion  for one payroll: skip SSS / PhilHealth / Pag-IBIG / loans of one employee (set before approval)
 * t_payroll_run        + approved_via (direct | override) + approval_requested_by
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_hr_access')) {
            Schema::create('m_hr_access', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('employee_id')->comment('m_employee.id');
                $t->string('role', 20)->comment('hr | supervisor');
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['employee_id', 'role']);
            });
        }

        if (!Schema::hasTable('t_payroll_exclusion')) {
            Schema::create('t_payroll_exclusion', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('payroll_run_id');
                $t->unsignedBigInteger('employee_id');
                $t->boolean('skip_sss')->default(false);
                $t->boolean('skip_philhealth')->default(false);
                $t->boolean('skip_pagibig')->default(false);
                $t->boolean('skip_loans')->default(false);
                $t->string('remarks', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['payroll_run_id', 'employee_id']);
            });
        }

        Schema::table('t_payroll_run', function (Blueprint $t) {
            if (!Schema::hasColumn('t_payroll_run', 'approved_via')) $t->string('approved_via', 20)->nullable()->after('approved_at')->comment('direct | override');
            if (!Schema::hasColumn('t_payroll_run', 'approval_requested_by')) $t->unsignedBigInteger('approval_requested_by')->nullable()->after('approved_via')->comment('HR who asked for the override');
        });
    }

    public function down(): void
    {
        Schema::table('t_payroll_run', function (Blueprint $t) {
            foreach (['approved_via', 'approval_requested_by'] as $c) if (Schema::hasColumn('t_payroll_run', $c)) $t->dropColumn($c);
        });
        Schema::dropIfExists('t_payroll_exclusion');
        Schema::dropIfExists('m_hr_access');
    }
};