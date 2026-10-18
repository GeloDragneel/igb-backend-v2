<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingType extends Model
{
    protected $table = 'm_reporting_type';
    protected $fillable = [
        'name',
    ];
}
