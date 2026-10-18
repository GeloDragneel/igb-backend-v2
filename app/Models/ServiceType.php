<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $table = 'm_service_type';
    protected $fillable = [
        'name',
    ];
}
