<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BegBalanceAccount extends Model
{
    protected $table = 't_beg_balance_accounts';

    protected $fillable = [
        'account_code', 'declared_month', 'assets_expenses', 'liability_equity_income', 'added_by',
    ];

    protected $casts = [
        'assets_expenses'         => 'float',
        'liability_equity_income' => 'float',
    ];
}