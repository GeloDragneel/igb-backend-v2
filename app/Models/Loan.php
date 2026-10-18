<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $table = 't_loans';
    protected $fillable = [
        'loan_no', 'direction', 'party_id', 'party_name', 'reference', 'principal', 'annual_rate', 'term_months',
        'method', 'release_date', 'first_due_date', 'loan_account', 'interest_account', 'cash_account',
        'status', 'release_jv_no', 'remarks', 'added_by', 'employee_id',
    ];
    protected $casts = [
        'principal' => 'decimal:2', 'annual_rate' => 'decimal:4', 'term_months' => 'integer',
        'release_date' => 'date', 'first_due_date' => 'date',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class, 'loan_id');
    }
}
