<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ContributionSetting;
use App\Models\Employee;
use App\Models\EmployeeBank;
use App\Models\EmployeeSalary;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LendingLoan;
use App\Models\NightDiffSetting;
use App\Models\Overtime;
use App\Models\PayRate;
use App\Models\PhicBracket;
use App\Models\SssBracket;
use App\Models\PayrollItem;
use App\Models\PayrollExclusion;
use App\Models\PayrollItemLine;
use App\Models\PayrollCutoff;
use App\Models\PayrollRun;
use App\Models\WithholdingTax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Payroll computations — Philippine rules, Philippine time.
 *
 *   rate      : the employee's default approved salary on the cut-off end (t_employee_salary), else m_employee.salary_day / salary_month
 *               monthly ↔ daily: daily = monthly × 12 ÷ work days per year (313 by default)
 *               hourly = daily ÷ shift paid hours (8)
 *   basic     : monthly-paid → monthly ÷ 2 per semi-monthly cut-off (− absences, late, undertime)
 *               daily-paid   → days worked × daily (+ paid leave days, + unworked regular holidays)
 *   premiums  : worked hours on rest day / special / regular holiday × hourly × (multiplier − 1)   (m_pay_rate)
 *   overtime  : approved OT hours × hourly × multiplier (REG_OT 1.25, RD_OT 1.69 …)
 *   night diff: night minutes ÷ 60 × hourly × ND rate (10%)
 *   SSS / PhilHealth / Pag-IBIG: monthly amounts from m_contribution_setting × the run's share (cut-off setting: none / half / whole)
 *   tax       : (gross − allowance − employee contributions) through m_withholding_tax for the run's frequency
 *   loans     : unpaid lending dues up to the pay date's half-month (15th / month end) (deduction mode = payroll), never more than the net pay
 *
 * compute() can be run again while the run is draft / computed; approve() posts the loan payments and locks the attendance.
 */
class PayrollCalculator
{
    const TZ = 'Asia/Manila';

    public function __construct(private LendingCalculator $lending, private AttendanceCalculator $attendance)
    {
    }

    /* ---------------- contributions + tax ---------------- */

    /** monthly SSS / PhilHealth / Pag-IBIG of a monthly salary → [ee, er, ec] per type */
    /**
     * $monthly   : the monthly rate (Pag-IBIG and the formula fallback)
     * $bracketOn : the amount the SSS / PhilHealth bracket is looked up with — payroll passes the GROSS PAY of the cut-off
     */
    public function contributions(float $monthly, string $date, ?float $bracketOn = null): array
    {
        $out = [];
        foreach (['SSS', 'PHILHEALTH', 'PAGIBIG'] as $type) {
            $s = ContributionSetting::inForce($type, $date);
            if (!$s || $monthly <= 0) { $out[$type] = ['ee' => 0.0, 'er' => 0.0, 'ec' => 0.0, 'base' => 0.0]; continue; }
            $floor = (float) $s->salary_floor;
            $ceiling = $s->salary_ceiling !== null ? (float) $s->salary_ceiling : INF;
            $base = min(max($monthly, $floor), $ceiling);
            if ($type === 'SSS' && (float) $s->bracket_step > 0) {          // monthly salary credit: nearest step
                $step = (float) $s->bracket_step;
                $base = min(max(floor($monthly / $step + 0.5) * $step, $floor), $ceiling);
            }
            if ($type === 'PAGIBIG') $base = min($monthly, $ceiling);
            $eeRate = ($s->low_salary_limit !== null && $monthly <= (float) $s->low_salary_limit && $s->low_ee_rate !== null)
                ? (float) $s->low_ee_rate : (float) $s->ee_rate;
            $ec = 0.0;
            if ($type === 'SSS' && $s->ec_threshold !== null) $ec = $base < (float) $s->ec_threshold ? (float) $s->ec_low : (float) $s->ec_high;
            $out[$type] = ['ee' => round($base * $eeRate, 2), 'er' => round($base * (float) $s->er_rate, 2), 'ec' => round($ec, 2), 'base' => $base];
        }

        // SSS and PhilHealth: the bracket tables (m_sss_bracket / m_phic_bracket) win when the amount has a bracket
        $on = $bracketOn ?? $monthly;
        if ($bracketOn !== null && $on <= 0) {
            $out['SSS'] = $out['PHILHEALTH'] = ['ee' => 0.0, 'er' => 0.0, 'ec' => 0.0, 'base' => 0.0];
            return $out;
        }
        if ($on > 0 && ($b = $this->bracket(SssBracket::class, $on))) {
            $out['SSS'] = ['ee' => (float) $b->ee, 'er' => (float) $b->er, 'ec' => (float) $b->ec, 'base' => (float) $b->mo_sal_credit, 'bracket' => $b->bracket_code];
        }
        if ($on > 0 && ($b = $this->bracket(PhicBracket::class, $on))) {
            $out['PHILHEALTH'] = ['ee' => (float) $b->ps, 'er' => (float) $b->es, 'ec' => 0.0, 'base' => (float) $b->mo_sal_credit, 'bracket' => $b->bracket_code];
        }
        return $out;
    }

    /** the bracket of a monthly salary (null when the table is not there yet) */
    private function bracket(string $model, float $monthly): ?object
    {
        static $has = [];
        $table = (new $model)->getTable();
        $has[$table] ??= \Illuminate\Support\Facades\Schema::hasTable($table);
        return $has[$table] ? $model::forSalary($monthly) : null;
    }

    /** withholding tax of a taxable compensation for a frequency */
    public function withholdingTax(float $taxable, string $frequency, string $date): float
    {
        if ($taxable <= 0) return 0.0;
        $effective = WithholdingTax::query()->where('frequency', $frequency)->where('effective_from', '<=', $date)->max('effective_from');
        if (!$effective) return 0.0;
        $b = WithholdingTax::query()->where('frequency', $frequency)->where('effective_from', $effective)
            ->where('compensation_from', '<=', $taxable)->orderByDesc('compensation_from')->first();
        return $b ? round((float) $b->base_tax + ($taxable - (float) $b->compensation_from) * (float) $b->rate_over, 2) : 0.0;
    }

    /* ---------------- run ---------------- */

    /** compute (or re-compute) every active employee of a run */
    public function compute(PayrollRun $run, ?array $employeeIds = null): PayrollRun
    {
        if (!in_array($run->status, ['draft', 'computed'], true)) throw new RuntimeException('Only a draft / computed payroll can be computed.');
        $employees = Employee::query()->where('is_active', 1)->when($employeeIds, fn ($q) => $q->whereIn('id', $employeeIds))->get();

        // not approved yet: always use the cut-off's CURRENT setting (SSS / PhilHealth / Pag-IBIG share, loan dues)
        if ($run->cutoff_id && ($cut = PayrollCutoff::find($run->cutoff_id))) {
            $run->update([
                'sss_share' => $cut->sss_share, 'philhealth_share' => $cut->philhealth_share, 'pagibig_share' => $cut->pagibig_share,
                'deduct_loans' => (bool) $cut->deduct_loans,
            ]);
            $run->refresh();
        }

        DB::transaction(function () use ($run, $employees) {
            // items are updated in place (same id), so an open payslip / list never points to a deleted row
            foreach ($employees as $e) $this->computeEmployee($run, $e);
            $run->update([
                'status'           => 'computed',
                'total_gross'      => round((float) $run->items()->sum('gross_pay'), 2),
                'total_deductions' => round((float) $run->items()->sum('total_deductions'), 2),
                'total_net'        => round((float) $run->items()->sum('net_pay'), 2),
            ]);
        });
        return $run->fresh();
    }

    public function computeEmployee(PayrollRun $run, Employee $e): PayrollItem
    {
        $from = CarbonImmutable::parse($run->period_from, self::TZ)->toDateString();
        $to = CarbonImmutable::parse($run->period_to, self::TZ)->toDateString();
        $semi = $run->frequency === 'semi_monthly';
        $perMonth = $semi ? 2 : ($run->frequency === 'weekly' ? 52 / 12 : 1);

        // rate
        $salary = EmployeeSalary::defaultFor($e->id, $to);
        $type = $salary?->salary_type ?? ((float) $e->salary_month > 0 ? 'monthly' : 'daily');
        $daysPerYear = (float) ($salary?->work_days_per_year ?? 313) ?: 313;
        $monthly = (float) ($salary?->monthly_rate ?? $e->salary_month ?? 0);
        $daily = (float) ($salary?->daily_rate ?? $e->salary_day ?? 0);
        if ($type === 'monthly' && $daily <= 0) $daily = round($monthly * 12 / $daysPerYear, 2);
        if ($type === 'daily' && $monthly <= 0) $monthly = round($daily * $daysPerYear / 12, 2);
        $shift = $this->attendance->shiftOf($e);
        $paidHours = (float) ($shift?->paid_hours ?? 8) ?: 8;
        $hourly = round($daily / $paidHours, 4);
        $perMinute = $hourly / 60;

        $rates = PayRate::map();
        $lines = [];
        $line = function (string $kind, string $code, string $desc, float $amount, ?float $qty = null, ?float $rate = null, ?string $refType = null, ?int $refId = null) use (&$lines) {
            if (abs($amount) < 0.005) return;
            $lines[] = compact('kind', 'code', 'amount', 'qty', 'rate', 'refType', 'refId') + ['description' => $desc];
        };

        // attendance of the cut-off
        $att = Attendance::query()->where('employee_id', $e->id)->whereBetween('work_date', [$from, $to])->get();
        $worked = $att->filter(fn ($a) => $a->worked_minutes > 0);
        // days present: a day with time worked counts 1 (half day 0.5) · late / undertime are deducted by the minute below
        $daysWorked = round($worked->sum(fn ($a) => $a->status === 'half_day' ? 0.5 : 1), 2);
        $late = (int) $att->sum('late_minutes');
        $under = (int) $att->sum('undertime_minutes') + (int) $att->sum('overbreak_minutes');
        $ndMinutes = (int) $att->sum('night_diff_minutes');

        // approved paid leave in the cut-off: date → days (0.5 for a half day)
        $paidLeave = [];
        foreach (LeaveRequest::query()->with('leaveType')->where('employee_id', $e->id)->where('status', 'approved')
                     ->where('date_from', '<=', $to)->where('date_to', '>=', $from)->get() as $l) {
            if (!$l->leaveType?->is_paid) continue;
            for ($d = CarbonImmutable::parse($l->date_from); $d->lessThanOrEqualTo(CarbonImmutable::parse($l->date_to)); $d = $d->addDay()) {
                $key = $d->toDateString();
                if ($key < $from || $key > $to) continue;
                if ($shift && $this->attendance->dayType($shift, $key) !== 'regular') continue;
                $paidLeave[$key] = $l->is_half_day ? 0.5 : 1.0;
            }
        }
        $leaveDays = round(array_sum($paidLeave), 2);

        // absences from the calendar: regular work days with no work and no paid leave
        $absentDays = 0.0;
        $workedOn = $worked->keyBy(fn ($a) => $a->work_date->format('Y-m-d'));
        for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $key = $d->toDateString();
            if ($shift && $this->attendance->dayType($shift, $key) !== 'regular') continue;
            if ($workedOn->has($key)) continue;
            $absentDays += 1 - ($paidLeave[$key] ?? 0);
        }

        // basic = days present × daily rate (monthly-paid too: daily = monthly × 12 ÷ work days a year)
        //   absent days are simply not paid · paid leave and overtime are their own earnings
        $basic = round($daysWorked * $daily, 2);
        $line('earning', 'BASIC', 'Basic pay (days present)', $basic, $daysWorked, $daily);
        $absentDeduction = 0.0;
        $leavePay = round($leaveDays * $daily, 2);
        $line('earning', 'LEAVE', 'Paid leave', $leavePay, $leaveDays, $daily);
        $lateDeduction = round(($late + $under) * $perMinute, 2);
        $line('deduction', 'LATE_UT', 'Late / undertime', $lateDeduction, $late + $under, $perMinute);

        // holiday / rest day premiums on worked days + unworked regular holiday for daily-paid
        $holidayPay = 0.0;
        $premiumCode = ['rest_day' => 'RD', 'special' => 'SH', 'regular_holiday' => 'RH'];
        foreach ($worked as $a) {
            if (!isset($premiumCode[$a->day_type])) continue;
            $code = $premiumCode[$a->day_type];
            $mult = $rates[$code] ?? 1;
            $amt = round($a->worked_minutes * $perMinute * ($mult - 1), 2);
            $holidayPay += $amt;
            $line('earning', $code, "Premium {$a->work_date->format('M d')} ({$code})", $amt, round($a->worked_minutes / 60, 2), $hourly * ($mult - 1));
        }
        {
            // regular holiday not worked: paid (one day), monthly- and daily-paid
            $workedDates = $worked->map(fn ($a) => $a->work_date->format('Y-m-d'))->all();
            foreach (Holiday::query()->where('holiday_type', 'regular')->whereBetween('holiday_date', [$from, $to])->get() as $h) {
                if (in_array($h->holiday_date->format('Y-m-d'), $workedDates, true)) continue;
                $holidayPay += $daily;
                $line('earning', 'RH_UNWORKED', "Regular holiday {$h->holiday_date->format('M d')}", $daily, 1, $daily);
            }
        }
        $holidayPay = round($holidayPay, 2);

        // overtime (approved)
        $otPay = 0.0;
        $otMinutes = 0;
        foreach (Overtime::query()->where('employee_id', $e->id)->where('status', 'approved')->whereBetween('work_date', [$from, $to])->get() as $ot) {
            $code = $ot->pay_rate_code ?: 'REG_OT';
            $mult = $rates[$code] ?? 1.25;
            $amt = round($ot->minutes * $perMinute * $mult, 2);
            $otPay += $amt;
            $otMinutes += (int) $ot->minutes;
            $line('earning', $code, "Overtime {$ot->work_date->format('M d')}", $amt, round($ot->minutes / 60, 2), $hourly * $mult, 'overtime', $ot->id);
        }
        $otPay = round($otPay, 2);

        // night differential
        $ndRate = (float) (NightDiffSetting::inForce($to)?->rate ?? 0);
        $ndPay = round($ndMinutes * $perMinute * $ndRate, 2);
        $line('earning', 'ND', 'Night differential', $ndPay, round($ndMinutes / 60, 2), $hourly * $ndRate);

        $allowance = round((float) ($salary?->allowance ?? $e->allowance ?? 0) / $perMonth, 2);
        $line('earning', 'ALLOW', 'Allowance', $allowance);

        $gross = round($basic + $leavePay + $holidayPay + $otPay + $ndPay + $allowance - $absentDeduction - $lateDeduction, 2);

        // contributions: SSS / PhilHealth bracket of this cut-off's GROSS PAY (Pag-IBIG: monthly rate)
        //   × the share this cut-off deducts (0 none · 0.5 half · 1 whole)
        $c = $this->contributions($monthly, $to, $gross);
        // skipped for this employee in this payroll (set before approval)
        $skip = PayrollExclusion::query()->where('payroll_run_id', $run->id)->where('employee_id', $e->id)->first();
        $skipped = ['SSS' => (bool) $skip?->skip_sss, 'PHILHEALTH' => (bool) $skip?->skip_philhealth, 'PAGIBIG' => (bool) $skip?->skip_pagibig];
        $part = function (string $type) use ($c, $run, $skipped) {
            $share = $skipped[$type] ? 0.0 : $run->shareOf($type);
            return array_map(fn ($v) => round((float) $v * $share, 2), ['ee' => $c[$type]['ee'], 'er' => $c[$type]['er'], 'ec' => $c[$type]['ec']]);
        };
        $sss = $part('SSS');
        $phic = $part('PHILHEALTH');
        $hdmf = $part('PAGIBIG');
        $label = function (string $name, string $type) use ($c, $run, $skipped) {
            $share = $skipped[$type] ? 0.0 : $run->shareOf($type);
            $code = $c[$type]['bracket'] ?? null;
            return trim($name . ($code ? " · {$code}" : '') . ($share > 0 && $share < 1 ? ' (half)' : ''));
        };
        $line('deduction', 'SSS', $label('SSS', 'SSS'), $sss['ee']);
        $line('deduction', 'PHIC', $label('PhilHealth', 'PHILHEALTH'), $phic['ee']);
        $line('deduction', 'HDMF', 'Pag-IBIG', $hdmf['ee']);
        $line('employer', 'SSS_ER', 'SSS (employer)', $sss['er'] + $sss['ec']);
        $line('employer', 'PHIC_ER', 'PhilHealth (employer)', $phic['er']);
        $line('employer', 'HDMF_ER', 'Pag-IBIG (employer)', $hdmf['er']);
        $eeContrib = $sss['ee'] + $phic['ee'] + $hdmf['ee'];

        // tax
        $taxable = round(max(0, $gross - $allowance - $eeContrib), 2);
        $tax = $this->withholdingTax($taxable, $run->frequency, $to);
        $line('deduction', 'WTAX', 'Withholding tax', $tax);

        // loans (never more than what is left)
        $loanTotal = 0.0;
        if ($run->deduct_loans && !$skip?->skip_loans) {
            $left = max(0, $gross - $eeContrib - $tax);
            $skipLoans = array_map('intval', (array) ($skip?->skip_loan_ids ?? []));
            foreach ($this->lending->payrollDues($e->id, $to, $run->pay_date) as $due) { // dues up to the pay date's half-month
                if (in_array((int) $due['loan']->id, $skipLoans, true)) continue; // this loan is not deducted this cut-off
                $amt = round(min($due['amount'], $left), 2);
                if ($amt <= 0) break;
                $left -= $amt;
                $loanTotal += $amt;
                $line('deduction', 'LOAN', "Loan {$due['loan']->reference_no}", $amt, null, null, 'lending_loan', $due['loan']->id);
            }
        }
        $loanTotal = round($loanTotal, 2);

        $deductions = round($eeContrib + $tax + $loanTotal, 2);
        $bank = EmployeeBank::query()->where('employee_id', $e->id)->where('is_active', true)->orderByDesc('is_default')->first();

        $item = PayrollItem::query()->firstOrNew(['payroll_run_id' => $run->id, 'employee_id' => $e->id]);
        $item->fill([
            'payroll_run_id' => $run->id, 'employee_id' => $e->id, 'employee_salary_id' => $salary?->id,
            'salary_type' => $type, 'daily_rate' => $daily, 'monthly_rate' => $monthly, 'hourly_rate' => $hourly,
            'days_worked' => $daysWorked, 'days_absent' => $absentDays, 'days_paid_leave' => $leaveDays,
            'late_minutes' => $late, 'undertime_minutes' => $under, 'ot_minutes' => $otMinutes, 'night_diff_minutes' => $ndMinutes,
            'basic_pay' => $basic, 'ot_pay' => $otPay, 'night_diff_pay' => $ndPay, 'holiday_pay' => $holidayPay, 'leave_pay' => $leavePay,
            'allowance' => $allowance, 'gross_pay' => $gross,
            'late_undertime_deduction' => $lateDeduction, 'absent_deduction' => $absentDeduction,
            'sss_ee' => $sss['ee'], 'sss_er' => $sss['er'], 'sss_ec' => $sss['ec'],
            'philhealth_ee' => $phic['ee'], 'philhealth_er' => $phic['er'], 'pagibig_ee' => $hdmf['ee'], 'pagibig_er' => $hdmf['er'],
            'taxable_income' => $taxable, 'withholding_tax' => $tax, 'loan_deduction' => $loanTotal,
            'total_deductions' => $deductions, 'net_pay' => round($gross - $deductions, 2),
            'employee_bank_id' => $bank?->id, 'bank_name' => $bank?->bank_name, 'account_no' => $bank?->account_no ?? $e->mccb_acc,
            'status' => 'draft',
        ])->save();
        PayrollItemLine::query()->where('payroll_item_id', $item->id)->delete();
        foreach ($lines as $l) {
            PayrollItemLine::create([
                'payroll_item_id' => $item->id, 'kind' => $l['kind'], 'code' => $l['code'], 'description' => $l['description'],
                'quantity' => $l['qty'], 'rate' => $l['rate'], 'amount' => round($l['amount'], 2),
                'reference_type' => $l['refType'], 'reference_id' => $l['refId'],
            ]);
        }
        return $item;
    }

    /** approve: post the loan deductions as payments, lock attendance / OT to this run */
    public function approve(PayrollRun $run, ?int $by = null): PayrollRun
    {
        if ($run->status !== 'computed') throw new RuntimeException('Compute the payroll first.');
        DB::transaction(function () use ($run, $by) {
            foreach ($run->items()->with('lines')->get() as $item) {
                foreach ($item->lines->where('reference_type', 'lending_loan') as $l) {
                    $this->lending->pay(LendingLoan::findOrFail($l->reference_id), (float) $l->amount, $run->pay_date, 'payroll', $item->id, $by, null, "Payroll {$run->code}");
                }
                $item->update(['status' => 'final']);
            }
            Attendance::query()->whereBetween('work_date', [$run->period_from, $run->period_to])->whereIn('employee_id', $run->items()->pluck('employee_id'))
                ->update(['payroll_run_id' => $run->id]);
            Overtime::query()->where('status', 'approved')->whereBetween('work_date', [$run->period_from, $run->period_to])
                ->whereIn('employee_id', $run->items()->pluck('employee_id'))->update(['payroll_run_id' => $run->id]);
            $run->update(['status' => 'approved', 'approved_by' => $by, 'approved_at' => now(self::TZ)]);
        });
        return $run->fresh();
    }

    /** undo approval: remove the loan payments it posted, unlock attendance / OT, back to computed */
    public function undoApprove(PayrollRun $run): PayrollRun
    {
        if ($run->status === 'released') throw new RuntimeException('Undo the release first.');
        if ($run->status !== 'approved') throw new RuntimeException('Only an approved payroll can be undone.');
        DB::transaction(function () use ($run) {
            $itemIds = $run->items()->pluck('id')->all();
            $this->lending->reversePayrollPayments($itemIds);
            Attendance::query()->where('payroll_run_id', $run->id)->update(['payroll_run_id' => null]);
            Overtime::query()->where('payroll_run_id', $run->id)->update(['payroll_run_id' => null]);
            PayrollItem::query()->whereIn('id', $itemIds ?: [0])->update(['status' => 'draft']);
            $run->update(['status' => 'computed', 'approved_by' => null, 'approved_at' => null]);
        });
        return $run->fresh();
    }
}