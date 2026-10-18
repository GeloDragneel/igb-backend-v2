<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashReplenishment extends Model
{
    protected $table = 't_petty_cash_replenishment';
    protected $fillable = [
        'replenish_no', 'fund_id', 'type', 'txn_date', 'declared_month', 'amount',
        'bank_account', 'reference', 'remarks', 'jv_no', 'added_by', 'employee_id',
    ];
    protected $casts = ['txn_date' => 'date', 'amount' => 'decimal:2'];

    public function fund(): BelongsTo { return $this->belongsTo(PettyCashFund::class, 'fund_id'); }
    public function vouchers(): HasMany { return $this->hasMany(PettyCashVoucher::class, 'replenish_no', 'replenish_no'); }
}
