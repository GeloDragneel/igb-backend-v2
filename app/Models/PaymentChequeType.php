<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentChequeType extends Model
{
    protected $table = 'm_payment_cheque_type';

    protected $fillable = [
        'name',
    ];
}
