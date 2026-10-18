<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR settings (all times are Philippine time, Asia/Manila)
 *   m_shift                  shift setup: time in, break in, break out, time out — one can be the default
 *   m_night_diff_setting     night differential window + rate (DOLE: 10:00 PM – 6:00 AM, 10%)
 *   m_pay_rate               pay multipliers: OT, rest day, special / regular holiday …
 *   m_holiday                holidays per date (regular / special non-working)
 *   m_leave_type             leave types and the default days per year
 *   m_contribution_setting   SSS / PhilHealth / Pag-IBIG rates (effective dated, keeps history)
 *   m_withholding_tax        BIR withholding tax table per pay frequency (effective dated)
 *
 * m_employee.shift_id already exists and points to m_shift.id.
 * If m_shift already exists, only the missing columns are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- m_shift ----------
        $shiftColumns = function (Blueprint $t, callable $missing) {
            if ($missing('code')) $t->string('code', 20)->nullable()->comment('e.g. DAY, MID, GRAVE');
            if ($missing('name')) $t->string('name', 100)->nullable();
            if ($missing('time_in')) $t->time('time_in')->nullable();
            if ($missing('break_in')) $t->time('break_in')->nullable()->comment('start of break');
            if ($missing('break_out')) $t->time('break_out')->nullable()->comment('end of break');
            if ($missing('time_out')) $t->time('time_out')->nullable();
            if ($missing('is_overnight')) $t->boolean('is_overnight')->default(false)->comment('time out is the next day');
            if ($missing('grace_minutes')) $t->unsignedSmallInteger('grace_minutes')->default(0)->comment('late allowance');
            if ($missing('work_days')) $t->string('work_days', 20)->default('1,2,3,4,5')->comment('ISO days 1=Mon … 7=Sun');
            if ($missing('paid_hours')) $t->decimal('paid_hours', 5, 2)->default(8)->comment('hours per day (break not included)');
            if ($missing('is_default')) $t->boolean('is_default')->default(false)->comment('used when the employee has no shift');
            if ($missing('is_active')) $t->boolean('is_active')->default(true);
            if ($missing('created_by')) $t->unsignedBigInteger('created_by')->nullable()->comment('m_employee.id');
        };
        if (!Schema::hasTable('m_shift')) {
            Schema::create('m_shift', function (Blueprint $t) use ($shiftColumns) {
                $t->id();
                $shiftColumns($t, fn () => true);
                $t->timestamps();
            });
        } else {
            Schema::table('m_shift', function (Blueprint $t) use ($shiftColumns) {
                $shiftColumns($t, fn (string $c) => !Schema::hasColumn('m_shift', $c));
            });
        }

        // ---------- m_night_diff_setting ----------
        Schema::create('m_night_diff_setting', function (Blueprint $t) {
            $t->id();
            $t->time('start_time')->default('22:00:00');
            $t->time('end_time')->default('06:00:00');
            $t->decimal('rate', 6, 4)->default(0.10)->comment('0.10 = +10% of the hourly rate');
            $t->date('effective_from');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        // ---------- m_pay_rate ----------
        Schema::create('m_pay_rate', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique()->comment('REG_OT, RD, RD_OT, SH, SH_OT, RH, RH_OT, …');
            $t->string('description', 150);
            $t->decimal('multiplier', 6, 4)->comment('1.25 = 125% of the hourly / daily rate');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---------- m_holiday ----------
        Schema::create('m_holiday', function (Blueprint $t) {
            $t->id();
            $t->date('holiday_date')->unique();
            $t->string('name', 150);
            $t->string('holiday_type', 20)->comment('regular | special | special_working');
            $t->timestamps();
        });

        // ---------- m_leave_type ----------
        Schema::create('m_leave_type', function (Blueprint $t) {
            $t->id();
            $t->string('code', 10)->unique()->comment('VL, SL, SIL, ML, PL, SPL, …');
            $t->string('name', 100);
            $t->boolean('is_paid')->default(true);
            $t->decimal('default_days', 5, 2)->default(0)->comment('days per year given to each employee');
            $t->boolean('is_convertible')->default(false)->comment('unused days can be converted to cash');
            $t->boolean('requires_attachment')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ---------- m_contribution_setting ----------
        Schema::create('m_contribution_setting', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20)->comment('SSS | PHILHEALTH | PAGIBIG');
            $t->date('effective_from');
            $t->decimal('ee_rate', 7, 4)->comment('employee share, 0.05 = 5%');
            $t->decimal('er_rate', 7, 4)->comment('employer share');
            $t->decimal('salary_floor', 12, 2)->default(0)->comment('minimum salary base (SSS: lowest MSC)');
            $t->decimal('salary_ceiling', 12, 2)->nullable()->comment('maximum salary base');
            $t->decimal('bracket_step', 10, 2)->nullable()->comment('SSS MSC step (500)');
            $t->decimal('low_salary_limit', 12, 2)->nullable()->comment('Pag-IBIG: salary at or below this uses low_ee_rate');
            $t->decimal('low_ee_rate', 7, 4)->nullable();
            $t->decimal('ec_threshold', 12, 2)->nullable()->comment('SSS EC: MSC below this pays ec_low, else ec_high');
            $t->decimal('ec_low', 10, 2)->nullable();
            $t->decimal('ec_high', 10, 2)->nullable();
            $t->string('remarks', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['type', 'effective_from']);
        });

        // ---------- m_withholding_tax ----------
        Schema::create('m_withholding_tax', function (Blueprint $t) {
            $t->id();
            $t->string('frequency', 20)->comment('monthly | semi_monthly | weekly | daily');
            $t->date('effective_from');
            $t->decimal('compensation_from', 14, 2);
            $t->decimal('compensation_to', 14, 2)->nullable()->comment('null = and above');
            $t->decimal('base_tax', 14, 2)->default(0);
            $t->decimal('rate_over', 7, 4)->default(0)->comment('rate on the excess over compensation_from');
            $t->timestamps();
            $t->index(['frequency', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_withholding_tax');
        Schema::dropIfExists('m_contribution_setting');
        Schema::dropIfExists('m_leave_type');
        Schema::dropIfExists('m_holiday');
        Schema::dropIfExists('m_pay_rate');
        Schema::dropIfExists('m_night_diff_setting');
        // m_shift is left alone: it may have existed before this migration
    }
};
