<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Buyer extends Model
{
    protected $table = 'm_buyers';
    protected $guarded = ['id'];

    protected $fillable = [
        'name',
        'address',
        'tin',
        'added_by',
    ];

}
