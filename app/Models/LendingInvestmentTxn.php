<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LendingInvestmentTxn extends Model
{
    protected $table = 't_lending_investment_txn';

    protected $fillable = [
        'investment_id',
        'txn_type',
        'txn_date',
        'amount',
        'days',
        'principal_after',
        'interest_after',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'txn_date'        => 'date:Y-m-d',
        'amount'          => 'decimal:2',
        'days'            => 'integer',
        'principal_after' => 'decimal:2',
        'interest_after'  => 'decimal:2',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(LendingInvestment::class, 'investment_id');
    }
}
