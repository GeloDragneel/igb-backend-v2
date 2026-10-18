<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LendingLoanPayment extends Model
{
    protected $table = 't_lending_loan_payment';

    protected $fillable = [
        'loan_id',
        'schedule_id',
        'payment_date',
        'amount',
        'penalty_paid',
        'source',
        'payroll_item_id',
        'or_number',
        'remarks',
        'received_by',
    ];

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'amount'       => 'decimal:2',
        'penalty_paid' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LendingLoan::class, 'loan_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(LendingLoanSchedule::class, 'schedule_id');
    }
}
