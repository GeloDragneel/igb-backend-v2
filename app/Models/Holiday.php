<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'm_holiday';

    protected $fillable = [
        'holiday_date',
        'name',
        'holiday_type',
    ];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
    ];
}
