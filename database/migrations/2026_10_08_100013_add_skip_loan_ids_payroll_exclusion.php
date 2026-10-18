<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** skip one loan (of several due) for an employee in a payroll: t_payroll_exclusion.skip_loan_ids = [loan ids] */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_payroll_exclusion', 'skip_loan_ids')) {
            Schema::table('t_payroll_exclusion', function (Blueprint $t) {
                $t->text('skip_loan_ids')->nullable()->after('skip_loans')->comment('JSON list of t_lending_loan.id not deducted');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_payroll_exclusion', 'skip_loan_ids')) {
            Schema::table('t_payroll_exclusion', function (Blueprint $t) {
                $t->dropColumn('skip_loan_ids');
            });
        }
    }
};