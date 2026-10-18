<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BegBalanceProduct extends Model
{
    protected $table = 't_beg_balance_product';

    protected $fillable = [
        'item_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'added_by',
        'account_period',
        'declared_month',
    ];

    protected $casts = [
        'item_id'    => 'integer',
        'added_by'   => 'integer',
        'quantity'   => 'decimal:2',
        'unit_cost'  => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Items::class, 'item_id');
    }
}