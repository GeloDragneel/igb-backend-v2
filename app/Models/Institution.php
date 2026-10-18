<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $table = 'm_institution';
    protected $fillable = [
        'name',
        'description',
    ];
}
