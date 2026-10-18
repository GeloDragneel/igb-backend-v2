<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceiptType extends Model
{
    protected $table = 'm_payment_receipt_type';

    protected $fillable = [
        'name',
        'description',
    ];
}
