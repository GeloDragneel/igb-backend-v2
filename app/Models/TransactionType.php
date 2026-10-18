<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionType extends Model
{
    protected $table = 'm_transaction_type';
    protected $fillable = [
        'name',
    ];
}
