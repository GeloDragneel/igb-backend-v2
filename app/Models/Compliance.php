<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Compliance extends Model
{
    protected $table = 'm_compliance';

    protected $fillable = [
        'name',
        'description',
    ];
}