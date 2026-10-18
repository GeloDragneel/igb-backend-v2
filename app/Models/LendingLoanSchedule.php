<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LendingLoanSchedule extends Model
{
    protected $table = 't_lending_loan_schedule';

    protected $fillable = [
        'loan_id',
        'due_no',
        'due_date',
        'principal_due',
        'interest_due',
        'amount_due',
        'penalty',
        'amount_paid',
        'status',
        'paid_date',
    ];

    protected $casts = [
        'due_no'        => 'integer',
        'due_date'      => 'date:Y-m-d',
        'principal_due' => 'decimal:2',
        'interest_due'  => 'decimal:2',
        'amount_due'    => 'decimal:2',
        'penalty'       => 'decimal:2',
        'amount_paid'   => 'decimal:2',
        'paid_date'     => 'date:Y-m-d',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LendingLoan::class, 'loan_id');
    }
}
