<?php

namespace App\Services;

use App\Models\LendingLoan;
use App\Models\LendingMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lending — the IGBLending Excel rules for loans (interest_type = "flat").
 *
 *   interest  FLAT on the original amount, the rate is PER DUE
 *             "2.5% Bi-Monthly" = 2.5% each 15th / end-of-month due · "5% Monthly" = 5% each month · "1.25% Weekly" = 1.25% each week
 *             total interest = principal × rate × number of dues
 *   dues      term = number of dues (not months)
 *             semi_monthly → every 15th and last day of the month after the release date
 *             monthly      → same day every month after the release date
 *             weekly       → every 7 days after the release date
 *   each due  principal ÷ dues  +  principal × rate   (the last due takes the rounding)
 *   penalty   penalty_rate_per_day on the overdue due (the Excel uses 5% a month = 0.05 / 30 a day)
 *
 * Every other interest type (per_day / per_month / fixed) and everything else
 * (investments, payments, payroll dues) stays in LendingCalculator.
 *
 * The helpers are named flat… so they never clash with LendingCalculator's own methods.
 *
 * Bound in AppServiceProvider::register():
 *   $this->app->bind(\App\Services\LendingCalculator::class, \App\Services\LendingFlatCalculator::class);
 */
class LendingFlatCalculator extends LendingCalculator
{
    public const FLAT = 'flat';
    public const EXCEL_PENALTY_PER_DAY = 0.001667;   // 5% a month ÷ 30

    /** dues · per-due amount · schedule (flat), or the old computation for the other interest types */
    public function loanTerms($principal = 0, $type = 'per_month', $rate = 0, $fixed = 0, $terms = 1, $date = null, $frequency = 'monthly', ...$more): array
    {
        if ($type !== self::FLAT) {
            if ($frequency === 'weekly') abort(422, 'Weekly dues are only for Flat (per due) loans.');
            return parent::loanTerms($principal, $type, $rate, $fixed, $terms, $date, $frequency, ...$more);
        }

        $principal = round((float) $principal, 2);
        $dues = max(1, (int) $terms);
        $rate = (float) $rate;
        $start = CarbonImmutable::parse($date ?: static::today())->startOfDay();
        if ($principal <= 0) abort(422, 'Enter the principal.');

        $interestPerDue = round($principal * $rate, 2);
        $principalPerDue = round($principal / $dues, 2);
        $dates = $this->flatDueDates($start, (string) $frequency, $dues);

        $schedule = [];
        $principalLeft = $principal;
        for ($i = 1; $i <= $dues; $i++) {
            $p = $i === $dues ? round($principalLeft, 2) : $principalPerDue;
            $principalLeft = round($principalLeft - $p, 2);
            $schedule[] = [
                'due_no' => $i, 'due_date' => $dates[$i - 1],
                'principal_due' => $p, 'interest_due' => $interestPerDue, 'amount_due' => round($p + $interestPerDue, 2),
            ];
        }
        $interest = round($interestPerDue * $dues, 2);

        return [
            'dues'           => $dues,
            'term_months'    => $this->flatMonths($dues, (string) $frequency),
            'amount_per_due' => $schedule[0]['amount_due'],
            'interest'       => $interest,
            'total_interest' => $interest,
            'total_payable'  => round($principal + $interest, 2),
            'first_due_date' => $dates[0],
            'maturity_date'  => $dates[$dues - 1],
            'schedule'       => $schedule,
        ];
    }

    /** file a flat loan (pending) — the others go to LendingCalculator */
    public function applyLoan($member = null, $d = [], $by = null, ...$more): LendingLoan
    {
        if (($d['interest_type'] ?? null) !== self::FLAT) return parent::applyLoan($member, $d, $by, ...$more);

        /** @var LendingMember $member */
        $freq = $d['due_frequency'] ?? 'semi_monthly';
        $t = $this->loanTerms((float) $d['principal'], self::FLAT, (float) ($d['interest_rate'] ?? 0), 0,
            (int) $d['term_months'], $d['date_released'] ?? static::today(), $freq);

        return DB::transaction(fn () => LendingLoan::create([
            'reference_no'         => $this->nextFlatLoanNo(),
            'member_id'            => $member->id,
            'employee_id'          => $member->employee_id,
            'lending_setting_id'   => $d['lending_setting_id'] ?? null,
            'interest_type'        => self::FLAT,
            'interest_rate'        => (float) ($d['interest_rate'] ?? 0),
            'fixed_interest'       => 0,
            'penalty_rate_per_day' => (float) ($d['penalty_rate_per_day'] ?? self::EXCEL_PENALTY_PER_DAY),
            'principal'            => round((float) $d['principal'], 2),
            'term_months'          => $t['term_months'],
            'dues'                 => $t['dues'],
            'due_frequency'        => $freq,
            'first_due_date'       => $t['first_due_date'],
            'maturity_date'        => $t['maturity_date'],
            'total_interest'       => $t['total_interest'],
            'total_payable'        => $t['total_payable'],
            'amount_per_due'       => $t['amount_per_due'],
            'total_paid'           => 0,
            'balance'              => $t['total_payable'],
            'deduction_mode'       => $member->employee_id ? ($d['deduction_mode'] ?? 'payroll') : 'manual',
            'purpose'              => $d['purpose'] ?? null,
            'co_maker'             => $d['co_maker'] ?? null,
            'collection_method'    => $d['collection_method'] ?? null,
            'status'               => 'pending',
            'created_by'           => $by,
        ]));
    }

    /** release a flat loan: the dues are counted from the release date — the others go to LendingCalculator */
    public function release($loan = null, $date = null, $by = null, ...$more): LendingLoan
    {
        /** @var LendingLoan $loan */
        if ($loan->interest_type !== self::FLAT) return parent::release($loan, $date, $by, ...$more);
        if ($loan->status !== 'approved') abort(422, "This loan is {$loan->status}; only an approved loan can be released.");

        $dues = (int) ($loan->dues ?: $loan->term_months);
        $t = $this->loanTerms((float) $loan->principal, self::FLAT, (float) $loan->interest_rate, 0, $dues, $date ?: static::today(), $loan->due_frequency);

        DB::transaction(function () use ($loan, $t, $date) {
            $loan->schedules()->delete();
            $now = now();
            $loan->schedules()->insert(array_map(fn ($s) => $s + [
                'loan_id' => $loan->id, 'penalty' => 0, 'amount_paid' => 0, 'status' => 'unpaid', 'created_at' => $now, 'updated_at' => $now,
            ], $t['schedule']));
            $loan->update([
                'date_released'  => CarbonImmutable::parse($date ?: static::today())->toDateString(),
                'first_due_date' => $t['first_due_date'],
                'maturity_date'  => $t['maturity_date'],
                'dues'           => $t['dues'],
                'term_months'    => $t['term_months'],
                'total_interest' => $t['total_interest'],
                'total_payable'  => $t['total_payable'],
                'amount_per_due' => $t['amount_per_due'],
                'balance'        => round($t['total_payable'] - (float) $loan->total_paid, 2),
                'status'         => 'released',
            ]);
        });

        return $loan->fresh(['member', 'schedules', 'payments']);
    }

    /* ---------- helpers ---------- */
    /** the due dates after $start (the release date) */
    public function flatDueDates(CarbonImmutable $start, string $frequency, int $n): array
    {
        $out = [];
        if ($frequency === 'weekly') {
            for ($i = 1; $i <= $n; $i++) $out[] = $start->addDays(7 * $i)->toDateString();
            return $out;
        }
        if ($frequency === 'monthly') {
            for ($i = 1; $i <= $n; $i++) $out[] = $start->addMonthsNoOverflow($i)->toDateString();
            return $out;
        }
        // semi-monthly: the 15th and the last day of each month, strictly after the release date
        $d = $start;
        while (count($out) < $n) {
            $mid = $d->day(15);
            $end = $d->endOfMonth()->startOfDay();
            foreach ([$mid, $end] as $c) {
                if ($c->greaterThan($start) && count($out) < $n && !in_array($c->toDateString(), $out, true)) $out[] = $c->toDateString();
            }
            $d = $d->startOfMonth()->addMonthNoOverflow();
        }
        return $out;
    }

    /** about how many months the dues cover (term_months is kept for the payroll / reports) */
    public function flatMonths(int $dues, string $frequency): int
    {
        return max(1, (int) match ($frequency) {
            'semi_monthly' => ceil($dues / 2),
            'weekly'       => ceil($dues * 7 / 30),
            default        => $dues,
        });
    }

    /** LN-0001, LN-0002 … (continues after the imported Excel numbers) */
    public function nextFlatLoanNo(): string
    {
        $max = (int) DB::table('t_lending_loan')->where('reference_no', 'like', 'LN-%')
            ->selectRaw("MAX(CAST(SUBSTRING(reference_no, 4) AS UNSIGNED)) AS n")->value('n');
        return 'LN-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}