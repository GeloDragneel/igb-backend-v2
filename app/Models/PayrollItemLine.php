<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItemLine extends Model
{
    protected $table = 't_payroll_item_line';

    protected $fillable = [
        'payroll_item_id',
        'kind',
        'code',
        'description',
        'quantity',
        'rate',
        'amount',
        'reference_type',
        'reference_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'rate'     => 'decimal:4',
        'amount'   => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }
}
