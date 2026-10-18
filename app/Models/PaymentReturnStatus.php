<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReturnStatus extends Model
{
    protected $table = 'm_payment_return_status';

    protected $fillable = [
        'name',
    ];

    public $timestamps = true;
}
