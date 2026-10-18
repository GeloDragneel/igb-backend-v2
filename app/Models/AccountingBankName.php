<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingBankName extends Model
{
    protected $table = 'm_accounting_bank_name';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];
}
