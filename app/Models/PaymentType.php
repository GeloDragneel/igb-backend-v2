<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentType extends Model
{
    protected $table = 'm_payment_type';

    protected $fillable = [
        'name',
        'is_active',
    ];
}
