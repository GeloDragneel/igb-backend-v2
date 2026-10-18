<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanPayment extends Model
{
    protected $table = 't_loan_payments';
    protected $fillable = [
        'loan_id', 'payment_no', 'installment_no', 'payment_date', 'declared_month', 'principal', 'interest',
        'penalty', 'total', 'cash_account', 'reference', 'status', 'jv_no', 'added_by', 'employee_id',
    ];
    protected $casts = [
        'payment_date' => 'date', 'principal' => 'decimal:2', 'interest' => 'decimal:2',
        'penalty' => 'decimal:2', 'total' => 'decimal:2', 'installment_no' => 'integer',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
