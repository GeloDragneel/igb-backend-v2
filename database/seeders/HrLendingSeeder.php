<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting values (Philippine rules). Check them against the latest SSS / PhilHealth / Pag-IBIG / BIR circulars and
 * DOLE advisories before the first payroll — all of them can be edited in the settings tables.
 *   php artisan db:seed --class=HrLendingSeeder
 */
class HrLendingSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('Asia/Manila');

        // default shift (only when there is no shift yet)
        if (!DB::table('m_shift')->exists()) {
            DB::table('m_shift')->insert([
                'code' => 'DAY', 'name' => 'Day shift 8:00 AM – 5:00 PM', 'time_in' => '08:00:00', 'break_in' => '12:00:00',
                'break_out' => '13:00:00', 'time_out' => '17:00:00', 'is_overnight' => false, 'grace_minutes' => 0,
                'work_days' => '1,2,3,4,5,6', 'paid_hours' => 8, 'is_default' => true, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // night differential: 10:00 PM – 6:00 AM, +10% (Labor Code Art. 86)
        DB::table('m_night_diff_setting')->insertOrIgnore([
            'start_time' => '22:00:00', 'end_time' => '06:00:00', 'rate' => 0.10, 'effective_from' => '2000-01-01',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // DOLE premium / overtime multipliers
        $rates = [
            ['REG_OT', 'Overtime on a regular day', 1.25],
            ['RD', 'Rest day', 1.30],
            ['RD_OT', 'Overtime on a rest day', 1.69],
            ['SH', 'Special non-working day', 1.30],
            ['SH_OT', 'Overtime on a special day', 1.69],
            ['SH_RD', 'Special day that is also a rest day', 1.50],
            ['SH_RD_OT', 'Overtime on a special day + rest day', 1.95],
            ['RH', 'Regular holiday (worked)', 2.00],
            ['RH_OT', 'Overtime on a regular holiday', 2.60],
            ['RH_RD', 'Regular holiday that is also a rest day', 2.60],
            ['RH_RD_OT', 'Overtime on a regular holiday + rest day', 3.38],
            ['DH', 'Double holiday (worked)', 3.00],
            ['DH_OT', 'Overtime on a double holiday', 3.90],
        ];
        foreach ($rates as [$code, $desc, $mult]) {
            DB::table('m_pay_rate')->updateOrInsert(['code' => $code], ['description' => $desc, 'multiplier' => $mult, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // leave types (days per year are company policy — edit as needed)
        $leaves = [
            ['SIL', 'Service Incentive Leave', true, 5],
            ['VL', 'Vacation Leave', true, 5],
            ['SL', 'Sick Leave', true, 5],
            ['ML', 'Maternity Leave (RA 11210)', true, 105],
            ['PL', 'Paternity Leave (RA 8187)', true, 7],
            ['SPL', 'Solo Parent Leave (RA 11861)', true, 7],
            ['VAWC', 'VAWC Leave (RA 9262)', true, 10],
            ['MCW', 'Special Leave for Women (RA 9710)', true, 60],
            ['LWOP', 'Leave Without Pay', false, 0],
        ];
        foreach ($leaves as [$code, $name, $paid, $days]) {
            DB::table('m_leave_type')->updateOrInsert(['code' => $code], ['name' => $name, 'is_paid' => $paid, 'default_days' => $days, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // contributions (2025 rates)
        $contrib = [
            // SSS: 15% of the monthly salary credit (EE 5%, ER 10%), MSC 5,000 – 35,000 in 500 steps, EC 10 / 30
            ['type' => 'SSS', 'effective_from' => '2025-01-01', 'ee_rate' => 0.05, 'er_rate' => 0.10, 'salary_floor' => 5000, 'salary_ceiling' => 35000,
                'bracket_step' => 500, 'ec_threshold' => 15000, 'ec_low' => 10, 'ec_high' => 30, 'remarks' => 'SSS 2025 schedule'],
            // PhilHealth: 5% split 50/50, salary floor 10,000, ceiling 100,000
            ['type' => 'PHILHEALTH', 'effective_from' => '2024-01-01', 'ee_rate' => 0.025, 'er_rate' => 0.025, 'salary_floor' => 10000, 'salary_ceiling' => 100000,
                'remarks' => 'PhilHealth 5% premium'],
            // Pag-IBIG: 2% / 2% on up to 10,000; 1% employee share at 1,500 and below
            ['type' => 'PAGIBIG', 'effective_from' => '2024-02-01', 'ee_rate' => 0.02, 'er_rate' => 0.02, 'salary_floor' => 0, 'salary_ceiling' => 10000,
                'low_salary_limit' => 1500, 'low_ee_rate' => 0.01, 'remarks' => 'HDMF Circular 460 (max fund salary 10,000)'],
        ];
        foreach ($contrib as $c) {
            DB::table('m_contribution_setting')->updateOrInsert(['type' => $c['type'], 'effective_from' => $c['effective_from']],
                $c + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        // BIR withholding tax (TRAIN law, 2023 onwards)
        $tax = [
            'monthly' => [[0, 20832.99, 0, 0], [20833, 33332.99, 0, 0.15], [33333, 66666.99, 1875, 0.20], [66667, 166666.99, 8541.80, 0.25], [166667, 666666.99, 33541.80, 0.30], [666667, null, 183541.80, 0.35]],
            'semi_monthly' => [[0, 10416.99, 0, 0], [10417, 16666.99, 0, 0.15], [16667, 33332.99, 937.50, 0.20], [33333, 83332.99, 4270.70, 0.25], [83333, 333332.99, 16770.70, 0.30], [333333, null, 91770.70, 0.35]],
            'weekly' => [[0, 4807.99, 0, 0], [4808, 7691.99, 0, 0.15], [7692, 15384.99, 432.60, 0.20], [15385, 38461.99, 1971.20, 0.25], [38462, 153845.99, 7740.45, 0.30], [153846, null, 42355.65, 0.35]],
        ];
        foreach ($tax as $freq => $rows) {
            foreach ($rows as [$from, $to, $base, $rate]) {
                DB::table('m_withholding_tax')->updateOrInsert(['frequency' => $freq, 'effective_from' => '2023-01-01', 'compensation_from' => $from],
                    ['compensation_to' => $to, 'base_tax' => $base, 'rate_over' => $rate, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // lending defaults (company policy — edit as needed)
        $lending = [
            ['name' => 'Salary Loan', 'applies_to' => 'loan', 'interest_type' => 'per_month', 'interest_rate' => 0.02, 'min_amount' => 1000,
                'max_term_months' => 12, 'penalty_rate_per_day' => 0.001, 'payroll_deductible' => true, 'is_default' => true],
            ['name' => 'Investment (1% per month)', 'applies_to' => 'investment', 'interest_type' => 'per_month', 'interest_rate' => 0.01, 'min_amount' => 1000,
                'lock_in_days' => 30, 'payroll_deductible' => false, 'is_default' => true],
        ];
        foreach ($lending as $l) {
            DB::table('m_lending_setting')->updateOrInsert(['name' => $l['name']], $l + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
