<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $table = 't_service';
    protected $fillable = [
        'trans_number   ',
        'service_period',
        'ser_list_id',
        'ser_fee_id',
        'ca_id',
        'qty',
        'service_fee',
        'surcharge',
        'less',
        'total_service_fee',
        'inherited_vat',
        'inherited_wh',
        'inherited_discount_amount',
        'd_vat',
        'd_witholding',
        'd_less_sc',
        'd_less_pwd',
        'd_less_sp',
        'd_less_others',
        'less_other_percentage',
        'prep_target_date',
        'is_surcharge',
        'is_interest',
        'is_paid',
        'is_print',
        'note',
        'payment_id',
        'return_status_id',
        'invoice_status_id',
    ];
}
