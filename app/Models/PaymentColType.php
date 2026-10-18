<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentColType extends Model
{
    protected $table = 'm_payment_col_type';

    protected $fillable = [
        'name',
    ];
}
