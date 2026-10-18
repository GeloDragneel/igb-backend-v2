<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LendingInvestment extends Model
{
    protected $table = 't_lending_investment';

    protected $fillable = [
        'reference_no',
        'member_id',
        'lending_setting_id',
        'interest_type',
        'interest_rate',
        'fixed_interest',
        'lock_in_days',
        'date_invested',
        'total_invested',
        'principal_balance',
        'interest_balance',
        'total_interest_earned',
        'total_cashed_out',
        'last_accrual_date',
        'status',
        'closed_at',
        'created_by',
    ];

    protected $casts = [
        'interest_rate'         => 'decimal:6',
        'fixed_interest'        => 'decimal:2',
        'lock_in_days'          => 'integer',
        'date_invested'         => 'date:Y-m-d',
        'total_invested'        => 'decimal:2',
        'principal_balance'     => 'decimal:2',
        'interest_balance'      => 'decimal:2',
        'total_interest_earned' => 'decimal:2',
        'total_cashed_out'      => 'decimal:2',
        'last_accrual_date'     => 'date:Y-m-d',
        'closed_at'             => 'date:Y-m-d',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(LendingMember::class, 'member_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(LendingSetting::class, 'lending_setting_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LendingInvestmentTxn::class, 'investment_id')->orderBy('txn_date')->orderBy('id');
    }
}
