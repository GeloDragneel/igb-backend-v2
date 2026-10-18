<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashFund extends Model
{
    protected $table = 't_petty_cash_fund';
    protected $fillable = ['name', 'custodian', 'account_code', 'fund_amount', 'is_active', 'added_by'];
    protected $casts = ['fund_amount' => 'decimal:2', 'is_active' => 'boolean'];

    public function vouchers(): HasMany { return $this->hasMany(PettyCashVoucher::class, 'fund_id'); }
    public function replenishments(): HasMany { return $this->hasMany(PettyCashReplenishment::class, 'fund_id'); }
}
