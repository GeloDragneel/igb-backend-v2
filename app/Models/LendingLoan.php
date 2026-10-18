<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LendingLoan extends Model
{
    protected $table = 't_lending_loan';

    protected $fillable = [
        'reference_no',
        'member_id',
        'employee_id',
        'lending_setting_id',
        'interest_type',
        'interest_rate',
        'fixed_interest',
        'penalty_rate_per_day',
        'principal',
        'term_months',
        'due_frequency',
        'date_released',
        'first_due_date',
        'maturity_date',
        'total_interest',
        'total_payable',
        'amount_per_due',
        'total_paid',
        'balance',
        'deduction_mode',
        'purpose',
        'status',
        'approved_by',
        'approved_at',
        'approver_remarks',
        'created_by',
    ];

    protected $casts = [
        'interest_rate'        => 'decimal:6',
        'fixed_interest'       => 'decimal:2',
        'penalty_rate_per_day' => 'decimal:6',
        'principal'            => 'decimal:2',
        'term_months'          => 'integer',
        'date_released'        => 'date:Y-m-d',
        'first_due_date'       => 'date:Y-m-d',
        'maturity_date'        => 'date:Y-m-d',
        'total_interest'       => 'decimal:2',
        'total_payable'        => 'decimal:2',
        'amount_per_due'       => 'decimal:2',
        'total_paid'           => 'decimal:2',
        'balance'              => 'decimal:2',
        'approved_at'          => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(LendingMember::class, 'member_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(LendingSetting::class, 'lending_setting_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LendingLoanSchedule::class, 'loan_id')->orderBy('due_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LendingLoanPayment::class, 'loan_id')->orderBy('payment_date');
    }
}
