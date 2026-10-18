<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringService extends Model
{
    protected $table = 't_recurring_service';
    protected $fillable = [
        'ca_id',
        'ser_list_id',
        'ser_fee_id',
        'eo_version',
        'qty',
        'service_fee',
        'surcharge',
        'less',
        'total_service_fee',
        'd_vat',
        'd_witholding',
        'd_less_sc',
        'd_less_pwd',
        'd_less_sp',
        'd_less_others',
        'less_other_percentage',
        'is_active',
    ];
}
