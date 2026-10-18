<?php

namespace App\Services;

use App\Models\LendingInvestment;
use App\Models\LendingInvestmentTxn;
use App\Models\LendingLoan;
use App\Models\LendingLoanPayment;
use App\Models\LendingLoanSchedule;
use App\Models\LendingMember;
use App\Models\LendingSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lending computations — Philippine time (Asia/Manila), simple interest (no compounding).
 *
 * INVESTMENT (invest and cash out anytime)
 *   Interest is counted on the principal still invested, day by day, from the last counted date:
 *     per_day   : principal × rate × days
 *     per_month : principal × rate × days ÷ 30          (a month = 30 days)
 *     fixed     : a flat amount, earned on the full cash-out
 *   Days = calendar days between the two dates (Oct 1 → Oct 31 = 30 days).
 *   Before every top-up / cash-out the interest up to that day is counted first, so each peso earns only for the days it was in.
 *   Cash-out pays the earned interest first, then principal. Inside the lock-in days the interest is forfeited.
 *
 * LOAN
 *   Interest (add-on, on the original principal):
 *     per_month : principal × rate × term months
 *     per_day   : principal × rate × days from release to maturity
 *     fixed     : flat amount
 *   Total payable = principal + interest, split evenly into the dues (last due takes the centavo difference).
 *   monthly dues      : same day every month after release
 *   semi_monthly dues : every payroll cut-off (15th and last day of the month)
 *   Payments (payroll or manual) go to the oldest unpaid due: penalty first, then the due.
 *   Penalty = overdue amount × penalty rate per day × days late.
 */
class LendingCalculator
{
    const TZ = 'Asia/Manila';
    const DAYS_PER_MONTH = 30;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    public static function date($value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::TZ)->startOfDay();
    }

    /** interest on an amount for some days (per_day / per_month); fixed is handled by the caller */
    public function interestFor(string $type, float $rate, float $amount, int $days): float
    {
        if ($days <= 0 || $amount <= 0) return 0.0;
        return match ($type) {
            'per_day'   => $amount * $rate * $days,
            'per_month' => $amount * $rate * $days / self::DAYS_PER_MONTH,
            default     => 0.0,
        };
    }

    /* =====================================================================
       INVESTMENT
       ===================================================================== */

    /** open an investment with its first deposit */
    public function invest(LendingMember $member, float $amount, $date, ?LendingSetting $setting = null, array $terms = [], ?int $by = null): LendingInvestment
    {
        if ($amount <= 0) throw new RuntimeException('Amount must be more than zero.');
        $date = self::date($date);
        $setting ??= LendingSetting::query()->where('applies_to', 'investment')->where('is_default', true)->where('is_active', true)->first();

        return DB::transaction(function () use ($member, $amount, $date, $setting, $terms, $by) {
            $inv = LendingInvestment::create([
                'reference_no'      => $this->nextReference('INV', LendingInvestment::class),
                'member_id'         => $member->id,
                'lending_setting_id' => $setting?->id,
                'interest_type'     => $terms['interest_type'] ?? $setting?->interest_type ?? 'per_month',
                'interest_rate'     => $terms['interest_rate'] ?? $setting?->interest_rate ?? 0,
                'fixed_interest'    => $terms['fixed_interest'] ?? $setting?->fixed_interest ?? 0,
                'lock_in_days'      => $terms['lock_in_days'] ?? $setting?->lock_in_days ?? 0,
                'date_invested'     => $date->toDateString(),
                'total_invested'    => $amount,
                'principal_balance' => $amount,
                'interest_balance'  => 0,
                'total_interest_earned' => 0,
                'total_cashed_out'  => 0,
                'last_accrual_date' => $date->toDateString(),
                'status'            => 'active',
                'created_by'        => $by,
            ]);
            $this->txn($inv, 'deposit', $date, $amount, null, 'Initial investment', $by);
            return $inv;
        });
    }

    /** count the interest up to a date and save it */
    public function accrue(LendingInvestment $inv, $asOf, ?int $by = null): float
    {
        $asOf = self::date($asOf);
        $from = self::date($inv->last_accrual_date);
        $days = (int) $from->diffInDays($asOf, false);
        if ($inv->status !== 'active' || $days <= 0) return 0.0;

        $interest = round($this->interestFor($inv->interest_type, (float) $inv->interest_rate, (float) $inv->principal_balance, $days), 2);
        $inv->last_accrual_date = $asOf->toDateString();
        if ($interest > 0) {
            $inv->interest_balance = round((float) $inv->interest_balance + $interest, 2);
            $inv->total_interest_earned = round((float) $inv->total_interest_earned + $interest, 2);
            $this->txn($inv, 'interest', $asOf, $interest, $days, "Interest {$from->toDateString()} to {$asOf->toDateString()}", $by);
        }
        $inv->save();
        return $interest;
    }

    /** what the investment is worth on a date, without saving (for the "if I cash out today" view) */
    public function preview(LendingInvestment $inv, $asOf = null): array
    {
        $asOf = self::date($asOf ?? self::today());
        $days = max(0, (int) self::date($inv->last_accrual_date)->diffInDays($asOf, false));
        $pending = round($this->interestFor($inv->interest_type, (float) $inv->interest_rate, (float) $inv->principal_balance, $days), 2);
        $heldDays = (int) self::date($inv->date_invested)->diffInDays($asOf, false);
        $locked = $heldDays < (int) $inv->lock_in_days;
        $fixed = $inv->interest_type === 'fixed' && !$locked ? (float) $inv->fixed_interest : 0.0;
        $interest = $locked ? 0.0 : round((float) $inv->interest_balance + $pending + $fixed, 2);

        return [
            'as_of'             => $asOf->toDateString(),
            'days_invested'     => max(0, $heldDays),
            'principal'         => (float) $inv->principal_balance,
            'interest_earned'   => $interest,
            'in_lock_in'        => $locked,
            'lock_in_ends'      => self::date($inv->date_invested)->addDays((int) $inv->lock_in_days)->toDateString(),
            'cashout_value'     => round((float) $inv->principal_balance + $interest, 2),
        ];
    }

    /** add money to an active investment */
    public function topUp(LendingInvestment $inv, float $amount, $date, ?int $by = null): LendingInvestment
    {
        if ($amount <= 0) throw new RuntimeException('Amount must be more than zero.');
        return DB::transaction(function () use ($inv, $amount, $date, $by) {
            $inv = LendingInvestment::query()->lockForUpdate()->findOrFail($inv->id);
            $this->accrue($inv, $date, $by);
            $inv->principal_balance = round((float) $inv->principal_balance + $amount, 2);
            $inv->total_invested = round((float) $inv->total_invested + $amount, 2);
            $inv->save();
            $this->txn($inv, 'top_up', self::date($date), $amount, null, 'Top-up', $by);
            return $inv;
        });
    }

    /**
     * cash out on a date: $amount = null → everything (closes the investment).
     * Pays the interest first, then principal.
     */
    public function cashout(LendingInvestment $inv, ?float $amount, $date, ?int $by = null): array
    {
        return DB::transaction(function () use ($inv, $amount, $date, $by) {
            $inv = LendingInvestment::query()->lockForUpdate()->findOrFail($inv->id);
            if ($inv->status !== 'active') throw new RuntimeException('This investment is already closed.');
            $date = self::date($date);
            $this->accrue($inv, $date, $by);

            $locked = (int) self::date($inv->date_invested)->diffInDays($date, false) < (int) $inv->lock_in_days;
            if ($locked && (float) $inv->interest_balance > 0) {        // inside the lock-in: interest is forfeited
                $this->txn($inv, 'interest', $date, -(float) $inv->interest_balance, null, 'Interest forfeited (within lock-in)', $by);
                $inv->total_interest_earned = round((float) $inv->total_interest_earned - (float) $inv->interest_balance, 2);
                $inv->interest_balance = 0;
            }

            $full = $amount === null;
            if ($full && $inv->interest_type === 'fixed' && !$locked && (float) $inv->fixed_interest > 0) {
                $inv->interest_balance = round((float) $inv->interest_balance + (float) $inv->fixed_interest, 2);
                $inv->total_interest_earned = round((float) $inv->total_interest_earned + (float) $inv->fixed_interest, 2);
                $this->txn($inv, 'interest', $date, (float) $inv->fixed_interest, null, 'Fixed interest', $by);
            }

            $available = round((float) $inv->principal_balance + (float) $inv->interest_balance, 2);
            $amount = $full ? $available : round($amount, 2);
            if ($amount <= 0 || $amount - $available > 0.004) throw new RuntimeException("Cash-out must be between 0.01 and {$available}.");

            $fromInterest = min($amount, (float) $inv->interest_balance);
            $fromPrincipal = round($amount - $fromInterest, 2);
            if ($fromInterest > 0) {
                $inv->interest_balance = round((float) $inv->interest_balance - $fromInterest, 2);
                $this->txn($inv, 'cashout_interest', $date, $fromInterest, null, 'Cash-out (interest)', $by);
            }
            if ($fromPrincipal > 0) {
                $inv->principal_balance = round((float) $inv->principal_balance - $fromPrincipal, 2);
                $this->txn($inv, 'cashout_principal', $date, $fromPrincipal, null, 'Cash-out (principal)', $by);
            }
            $inv->total_cashed_out = round((float) $inv->total_cashed_out + $amount, 2);
            if ((float) $inv->principal_balance <= 0.004 && (float) $inv->interest_balance <= 0.004) {
                $inv->status = 'closed';
                $inv->closed_at = $date->toDateString();
            }
            $inv->save();

            return ['paid' => $amount, 'from_interest' => round($fromInterest, 2), 'from_principal' => $fromPrincipal, 'investment' => $inv->fresh()];
        });
    }

    private function txn(LendingInvestment $inv, string $type, CarbonImmutable $date, float $amount, ?int $days, string $remarks, ?int $by): void
    {
        LendingInvestmentTxn::create([
            'investment_id'   => $inv->id,
            'txn_type'        => $type,
            'txn_date'        => $date->toDateString(),
            'amount'          => round($amount, 2),
            'days'            => $days,
            'principal_after' => (float) $inv->principal_balance,
            'interest_after'  => (float) $inv->interest_balance,
            'remarks'         => $remarks,
            'created_by'      => $by,
        ]);
    }

    /* =====================================================================
       LOAN
       ===================================================================== */

    /** the numbers of a loan before it is saved (for the application form) */
    public function loanTerms(float $principal, string $type, float $rate, float $fixed, int $termMonths, $releaseDate, string $frequency = 'monthly'): array
    {
        if ($principal <= 0 || $termMonths <= 0) throw new RuntimeException('Principal and term must be more than zero.');
        $release = self::date($releaseDate);
        $maturity = $release->addMonthsNoOverflow($termMonths);
        $termDays = (int) $release->diffInDays($maturity);

        $interest = round(match ($type) {
            'per_month' => $principal * $rate * $termMonths,
            'per_day'   => $principal * $rate * $termDays,
            'fixed'     => $fixed,
            default     => 0,
        }, 2);
        $dueDates = $this->dueDates($release, $termMonths, $frequency);
        $n = count($dueDates);
        $total = round($principal + $interest, 2);
        $perDue = round($total / $n, 2);

        $schedule = [];
        $pLeft = $principal;
        $iLeft = $interest;
        foreach ($dueDates as $i => $d) {
            $last = $i === $n - 1;
            $p = $last ? round($pLeft, 2) : round($principal / $n, 2);
            $int = $last ? round($iLeft, 2) : round($interest / $n, 2);
            $pLeft -= $p;
            $iLeft -= $int;
            $schedule[] = ['due_no' => $i + 1, 'due_date' => $d->toDateString(), 'principal_due' => $p, 'interest_due' => $int, 'amount_due' => round($p + $int, 2)];
        }

        return [
            'principal' => round($principal, 2), 'interest' => $interest, 'total_payable' => $total,
            'dues' => $n, 'amount_per_due' => $perDue, 'term_days' => $termDays,
            'date_released' => $release->toDateString(), 'first_due_date' => $schedule[0]['due_date'],
            'maturity_date' => end($schedule)['due_date'], 'schedule' => $schedule,
        ];
    }

    /** monthly: same day each month · semi_monthly: every 15th and month-end after the release */
    public function dueDates(CarbonImmutable $release, int $termMonths, string $frequency): array
    {
        if ($frequency !== 'semi_monthly') {
            return array_map(fn ($i) => $release->addMonthsNoOverflow($i), range(1, $termMonths));
        }
        $dates = [];
        $cursor = $release;
        while (count($dates) < $termMonths * 2) {
            $mid = $cursor->day(15);
            $end = $cursor->endOfMonth()->startOfDay();
            foreach ([$mid, $end] as $d) {
                if ($d->greaterThan($release) && count($dates) < $termMonths * 2) $dates[] = $d;
            }
            $cursor = $cursor->startOfMonth()->addMonth();
        }
        return $dates;
    }

    /** save a loan application (status pending) with its computed totals */
    public function applyLoan(LendingMember $member, array $data, ?int $by = null): LendingLoan
    {
        $setting = !empty($data['lending_setting_id']) ? LendingSetting::findOrFail($data['lending_setting_id']) : null;
        $type = $data['interest_type'] ?? $setting?->interest_type ?? 'per_month';
        $rate = (float) ($data['interest_rate'] ?? $setting?->interest_rate ?? 0);
        $fixed = (float) ($data['fixed_interest'] ?? $setting?->fixed_interest ?? 0);
        $freq = $data['due_frequency'] ?? 'monthly';
        $terms = $this->loanTerms((float) $data['principal'], $type, $rate, $fixed, (int) $data['term_months'], $data['date_released'] ?? self::today(), $freq);

        return LendingLoan::create([
            'reference_no'         => $this->nextReference('LN', LendingLoan::class),
            'member_id'            => $member->id,
            'employee_id'          => $member->employee_id,
            'lending_setting_id'   => $setting?->id,
            'interest_type'        => $type,
            'interest_rate'        => $rate,
            'fixed_interest'       => $fixed,
            'penalty_rate_per_day' => (float) ($data['penalty_rate_per_day'] ?? $setting?->penalty_rate_per_day ?? 0),
            'principal'            => $terms['principal'],
            'term_months'          => (int) $data['term_months'],
            'due_frequency'        => $freq,
            'total_interest'       => $terms['interest'],
            'total_payable'        => $terms['total_payable'],
            'amount_per_due'       => $terms['amount_per_due'],
            'total_paid'           => 0,
            'balance'              => $terms['total_payable'],
            'deduction_mode'       => $member->employee_id ? ($data['deduction_mode'] ?? 'payroll') : 'manual',
            'purpose'              => $data['purpose'] ?? null,
            'status'               => 'pending',
            'created_by'           => $by,
        ]);
    }

    /** release an approved loan on a date: recompute from that date and build the schedule */
    public function release(LendingLoan $loan, $date, ?int $by = null): LendingLoan
    {
        if (!in_array($loan->status, ['approved'], true)) throw new RuntimeException('Only an approved loan can be released.');
        return DB::transaction(function () use ($loan, $date) {
            $terms = $this->loanTerms((float) $loan->principal, $loan->interest_type, (float) $loan->interest_rate, (float) $loan->fixed_interest,
                (int) $loan->term_months, $date, $loan->due_frequency);
            $loan->schedules()->delete();
            foreach ($terms['schedule'] as $s) LendingLoanSchedule::create($s + ['loan_id' => $loan->id]);
            $loan->update([
                'date_released' => $terms['date_released'], 'first_due_date' => $terms['first_due_date'], 'maturity_date' => $terms['maturity_date'],
                'total_interest' => $terms['interest'], 'total_payable' => $terms['total_payable'], 'amount_per_due' => $terms['amount_per_due'],
                'balance' => round($terms['total_payable'] - (float) $loan->total_paid, 2), 'status' => 'released',
            ]);
            return $loan->fresh('schedules');
        });
    }

    /** add the penalty of every overdue due up to a date (saved on the schedule) */
    public function applyPenalties(LendingLoan $loan, $asOf): float
    {
        $rate = (float) $loan->penalty_rate_per_day;
        if ($rate <= 0) return 0.0;
        $asOf = self::date($asOf);
        $added = 0.0;
        foreach ($loan->schedules()->where('status', '!=', 'paid')->where('due_date', '<', $asOf->toDateString())->get() as $s) {
            $days = (int) self::date($s->due_date)->diffInDays($asOf);
            $penalty = round(max(0, (float) $s->amount_due - (float) $s->amount_paid) * $rate * $days, 2);
            if ($penalty > (float) $s->penalty) {
                $added += $penalty - (float) $s->penalty;
                $s->update(['penalty' => $penalty]);
            }
        }
        return round($added, 2);
    }

    /**
     * pay a loan (manual entry or payroll): oldest unpaid due first, its penalty first.
     * Returns the payment rows created.
     */
    public function pay(LendingLoan $loan, float $amount, $date, string $source = 'manual', ?int $payrollItemId = null, ?int $by = null, ?string $orNumber = null, ?string $remarks = null): array
    {
        if ($amount <= 0) throw new RuntimeException('Amount must be more than zero.');
        return DB::transaction(function () use ($loan, $amount, $date, $source, $payrollItemId, $by, $orNumber, $remarks) {
            $loan = LendingLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'released') throw new RuntimeException('Only a released loan can be paid.');
            $date = self::date($date);
            $this->applyPenalties($loan, $date);

            $left = round($amount, 2);
            $rows = [];
            foreach ($loan->schedules()->where('status', '!=', 'paid')->orderBy('due_no')->get() as $s) {
                if ($left <= 0) break;
                $penaltyOwed = round((float) $s->penalty - $this->penaltyPaid($s), 2);
                $penaltyPaid = min($left, max(0, $penaltyOwed));
                $left = round($left - $penaltyPaid, 2);
                $dueOwed = round((float) $s->amount_due - (float) $s->amount_paid, 2);
                $duePaid = min($left, $dueOwed);
                $left = round($left - $duePaid, 2);
                if ($penaltyPaid + $duePaid <= 0) continue;

                $s->amount_paid = round((float) $s->amount_paid + $duePaid, 2);
                $s->status = $s->amount_paid + 0.004 >= (float) $s->amount_due ? 'paid' : 'partial';
                if ($s->status === 'paid') $s->paid_date = $date->toDateString();
                $s->save();

                $rows[] = LendingLoanPayment::create([
                    'loan_id' => $loan->id, 'schedule_id' => $s->id, 'payment_date' => $date->toDateString(),
                    'amount' => $duePaid, 'penalty_paid' => $penaltyPaid, 'source' => $source, 'payroll_item_id' => $payrollItemId,
                    'or_number' => $orNumber, 'remarks' => $remarks, 'received_by' => $by,
                ]);
            }
            if ($left > 0.004) throw new RuntimeException("Payment is more than the balance by {$left}.");

            $paid = round((float) $loan->payments()->sum('amount'), 2);
            $loan->update([
                'total_paid' => $paid,
                'balance'    => round((float) $loan->total_payable - $paid, 2),
                'status'     => (float) $loan->total_payable - $paid <= 0.004 ? 'paid' : 'released',
            ]);
            return $rows;
        });
    }

    private function penaltyPaid(LendingLoanSchedule $s): float
    {
        return (float) LendingLoanPayment::query()->where('schedule_id', $s->id)->sum('penalty_paid');
    }

    /** undo the loan payments a payroll posted (payroll approval undone) */
    public function reversePayrollPayments(array $payrollItemIds): int
    {
        if (!$payrollItemIds) return 0;
        return DB::transaction(function () use ($payrollItemIds) {
            $payments = LendingLoanPayment::query()->whereIn('payroll_item_id', $payrollItemIds)->get();
            foreach ($payments as $p) {
                $s = $p->schedule_id ? LendingLoanSchedule::query()->lockForUpdate()->find($p->schedule_id) : null;
                if ($s) {
                    $s->amount_paid = max(0, round((float) $s->amount_paid - (float) $p->amount, 2));
                    $s->status = $s->amount_paid <= 0.004 ? 'unpaid' : ($s->amount_paid + 0.004 >= (float) $s->amount_due ? 'paid' : 'partial');
                    if ($s->status !== 'paid') $s->paid_date = null;
                    $s->save();
                }
                $p->delete();
            }
            foreach ($payments->pluck('loan_id')->unique() as $loanId) {
                $loan = LendingLoan::query()->lockForUpdate()->find($loanId);
                if (!$loan) continue;
                $paid = round((float) $loan->payments()->sum('amount'), 2);
                $balance = round((float) $loan->total_payable - $paid, 2);
                $loan->update([
                    'total_paid' => $paid, 'balance' => $balance,
                    'status' => in_array($loan->status, ['paid', 'released'], true) ? ($balance <= 0.004 ? 'paid' : 'released') : $loan->status,
                ]);
            }
            return $payments->count();
        });
    }

    /**
     * what payroll should deduct from an employee for a cut-off: every unpaid due up to the cut-off end
     * of his released loans set to "payroll".  → [[loan, amount], …]
     */
    /**
     * the last due date a payroll deducts: the later of
     *   the cut-off end · the pay date · the end of the pay date's half-month (15th or month end)
     * so a due on the 15th is deducted in the payroll paid on the 15th, a due on the 31st in the one paid on the 30th
     */
    public static function payrollDueLimit($periodTo, $payDate = null): string
    {
        $limit = self::date($periodTo);
        if ($payDate) {
            $pay = self::date($payDate);
            $half = $pay->day <= 15 ? $pay->setDay(15) : $pay->endOfMonth()->startOfDay();
            foreach ([$pay, $half] as $d) if ($d->greaterThan($limit)) $limit = $d;
        }
        return $limit->toDateString();
    }

    public function payrollDues(int $employeeId, $periodTo, $payDate = null): array
    {
        $periodTo = self::payrollDueLimit($periodTo, $payDate);
        $out = [];
        $loans = LendingLoan::query()->where('employee_id', $employeeId)->where('status', 'released')->where('deduction_mode', 'payroll')->get();
        foreach ($loans as $loan) {
            $this->applyPenalties($loan, $periodTo);
            $due = 0.0;
            foreach ($loan->schedules()->where('status', '!=', 'paid')->where('due_date', '<=', self::date($periodTo)->toDateString())->get() as $s) {
                $due += (float) $s->amount_due - (float) $s->amount_paid + (float) $s->penalty - $this->penaltyPaid($s);
            }
            if ($due > 0.004) $out[] = ['loan' => $loan, 'amount' => round($due, 2)];
        }
        return $out;
    }

    /** INV-2026-000001 / LN-2026-000001 */
    private function nextReference(string $prefix, string $model): string
    {
        $year = self::today()->year;
        $last = $model::query()->where('reference_no', 'like', "{$prefix}-{$year}-%")->orderByDesc('id')->value('reference_no');
        $n = $last ? (int) substr($last, -6) + 1 : 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $n);
    }
}