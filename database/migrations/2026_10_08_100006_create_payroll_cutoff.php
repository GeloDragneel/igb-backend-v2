<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll cut-offs (e.g. 11 – 25 and 26 – 10) and which benefits each cut-off deducts.
 *   start_day / end_day : day of the month (31 = end of the month). end_day < start_day → starts the month before (26 – 10)
 *   *_share             : 0 = not deducted · 0.5 = half of the monthly amount · 1 = the whole monthly amount
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_payroll_cutoff')) {
            Schema::create('m_payroll_cutoff', function (Blueprint $t) {
                $t->id();
                $t->string('code', 10)->unique()->comment('used in the payroll code: PR-2026-10-A');
                $t->string('name', 50)->comment('e.g. 11 – 25');
                $t->string('frequency', 20)->default('semi_monthly')->comment('semi_monthly | monthly');
                $t->unsignedTinyInteger('start_day');
                $t->unsignedTinyInteger('end_day')->comment('31 = end of the month');
                $t->unsignedTinyInteger('pay_days_after')->default(5)->comment('pay date = period end + days');
                $t->decimal('sss_share', 3, 2)->default(0);
                $t->decimal('philhealth_share', 3, 2)->default(0);
                $t->decimal('pagibig_share', 3, 2)->default(0);
                $t->boolean('deduct_loans')->default(true);
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
            });
            $now = now();
            DB::table('m_payroll_cutoff')->insert([
                ['code' => 'A', 'name' => '11 – 25', 'frequency' => 'semi_monthly', 'start_day' => 11, 'end_day' => 25, 'pay_days_after' => 5,
                    'sss_share' => 0, 'philhealth_share' => 0, 'pagibig_share' => 0, 'deduct_loans' => true, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'B', 'name' => '26 – 10', 'frequency' => 'semi_monthly', 'start_day' => 26, 'end_day' => 10, 'pay_days_after' => 5,
                    'sss_share' => 1, 'philhealth_share' => 1, 'pagibig_share' => 1, 'deduct_loans' => true, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        Schema::table('t_payroll_run', function (Blueprint $t) {
            if (!Schema::hasColumn('t_payroll_run', 'cutoff_id')) $t->unsignedBigInteger('cutoff_id')->nullable()->after('cutoff_no')->comment('m_payroll_cutoff.id');
            if (!Schema::hasColumn('t_payroll_run', 'sss_share')) $t->decimal('sss_share', 3, 2)->nullable()->after('deduct_contributions');
            if (!Schema::hasColumn('t_payroll_run', 'philhealth_share')) $t->decimal('philhealth_share', 3, 2)->nullable()->after('sss_share');
            if (!Schema::hasColumn('t_payroll_run', 'pagibig_share')) $t->decimal('pagibig_share', 3, 2)->nullable()->after('philhealth_share');
        });
    }

    public function down(): void
    {
        Schema::table('t_payroll_run', function (Blueprint $t) {
            foreach (['cutoff_id', 'sss_share', 'philhealth_share', 'pagibig_share'] as $c) {
                if (Schema::hasColumn('t_payroll_run', $c)) $t->dropColumn($c);
            }
        });
        Schema::dropIfExists('m_payroll_cutoff');
    }
};