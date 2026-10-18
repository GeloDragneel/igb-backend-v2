<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WtbManual extends Model
{
    protected $table = 't_wtb_manual';

    protected $fillable = [
        'caid', 'period', 'account_code',
        'beg_debit', 'beg_credit', 'adj_debit', 'adj_credit', 'added_by',
    ];

    protected $casts = [
        'beg_debit'  => 'float',
        'beg_credit' => 'float',
        'adj_debit'  => 'float',
        'adj_credit' => 'float',
    ];
}