<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingBankAccount extends Model
{
    protected $table = 'm_accounting_bank_account';

    protected $fillable = [
        'abaid',
        'com_id',
        'account_name',
        'account_number',
        'contact_num',
        'is_active',
    ];
}
