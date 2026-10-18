<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class PayrollCutoff extends Model
{
    protected $table = 'm_payroll_cutoff';

    protected $fillable = [
        'code',
        'name',
        'frequency',
        'start_day',
        'end_day',
        'pay_days_after',
        'sss_share',
        'philhealth_share',
        'pagibig_share',
        'deduct_loans',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'start_day'        => 'integer',
        'end_day'          => 'integer',
        'pay_days_after'   => 'integer',
        'sss_share'        => 'float',
        'philhealth_share' => 'float',
        'pagibig_share'    => 'float',
        'deduct_loans'     => 'boolean',
        'is_active'        => 'boolean',
    ];

    /**
     * the period of this cut-off for a month ("2026-10" = the month the period ENDS)
     *   11 – 25 → Oct 11 – Oct 25 · 26 – 10 → Sep 26 – Oct 10 · pay date = end + pay_days_after
     */
    public function period(string $month): array
    {
        $m = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Manila');
        $to = $m->setDay(min($this->end_day, $m->daysInMonth));
        $startMonth = $this->end_day < $this->start_day ? $m->subMonthNoOverflow() : $m;
        $from = $startMonth->setDay(min($this->start_day, $startMonth->daysInMonth));
        return [
            'period_from' => $from->toDateString(),
            'period_to'   => $to->toDateString(),
            'pay_date'    => $to->addDays($this->pay_days_after)->toDateString(),
        ];
    }
}