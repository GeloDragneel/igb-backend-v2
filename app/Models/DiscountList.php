<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountList extends Model
{
    protected $table = 'm_discount_list';
    protected $guarded = ['id'];
    protected $casts = ['value' => 'float'];
}
