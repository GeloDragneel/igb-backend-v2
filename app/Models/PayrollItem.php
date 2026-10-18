<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollItem extends Model
{
    protected $table = 't_payroll_item';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'employee_salary_id',
        'salary_type',
        'daily_rate',
        'monthly_rate',
        'hourly_rate',
        'days_worked',
        'days_absent',
        'days_paid_leave',
        'late_minutes',
        'undertime_minutes',
        'ot_minutes',
        'night_diff_minutes',
        'basic_pay',
        'ot_pay',
        'night_diff_pay',
        'holiday_pay',
        'leave_pay',
        'allowance',
        'other_earnings',
        'gross_pay',
        'late_undertime_deduction',
        'absent_deduction',
        'sss_ee',
        'sss_er',
        'sss_ec',
        'philhealth_ee',
        'philhealth_er',
        'pagibig_ee',
        'pagibig_er',
        'taxable_income',
        'withholding_tax',
        'loan_deduction',
        'other_deductions',
        'total_deductions',
        'net_pay',
        'employee_bank_id',
        'bank_name',
        'account_no',
        'status',
        'remarks',
    ];

    protected $casts = [
        'daily_rate'               => 'decimal:2',
        'monthly_rate'             => 'decimal:2',
        'days_worked'              => 'decimal:2',
        'days_absent'              => 'decimal:2',
        'days_paid_leave'          => 'decimal:2',
        'basic_pay'                => 'decimal:2',
        'ot_pay'                   => 'decimal:2',
        'night_diff_pay'           => 'decimal:2',
        'holiday_pay'              => 'decimal:2',
        'leave_pay'                => 'decimal:2',
        'allowance'                => 'decimal:2',
        'other_earnings'           => 'decimal:2',
        'gross_pay'                => 'decimal:2',
        'late_undertime_deduction' => 'decimal:2',
        'absent_deduction'         => 'decimal:2',
        'sss_ee'                   => 'decimal:2',
        'sss_er'                   => 'decimal:2',
        'sss_ec'                   => 'decimal:2',
        'philhealth_ee'            => 'decimal:2',
        'philhealth_er'            => 'decimal:2',
        'pagibig_ee'               => 'decimal:2',
        'pagibig_er'               => 'decimal:2',
        'taxable_income'           => 'decimal:2',
        'withholding_tax'          => 'decimal:2',
        'loan_deduction'           => 'decimal:2',
        'other_deductions'         => 'decimal:2',
        'total_deductions'         => 'decimal:2',
        'net_pay'                  => 'decimal:2',
        'hourly_rate'              => 'decimal:4',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollItemLine::class, 'payroll_item_id');
    }
}
