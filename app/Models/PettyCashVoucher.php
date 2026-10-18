<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashVoucher extends Model
{
    protected $table = 't_petty_cash_voucher';
    protected $fillable = [
        'pcv_no', 'fund_id', 'pcv_date', 'declared_month', 'payee', 'particulars', 'reference',
        'expense_account', 'amount', 'status', 'replenish_no', 'added_by', 'employee_id',
    ];
    protected $casts = ['pcv_date' => 'date', 'amount' => 'decimal:2'];

    public function fund(): BelongsTo { return $this->belongsTo(PettyCashFund::class, 'fund_id'); }
}
