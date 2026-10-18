<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithholdingTax extends Model
{
    protected $table = 'm_withholding_tax';

    protected $fillable = [
        'frequency',
        'effective_from',
        'compensation_from',
        'compensation_to',
        'base_tax',
        'rate_over',
    ];

    protected $casts = [
        'effective_from'    => 'date:Y-m-d',
        'compensation_from' => 'decimal:2',
        'compensation_to'   => 'decimal:2',
        'base_tax'          => 'decimal:2',
        'rate_over'         => 'decimal:4',
    ];
}
