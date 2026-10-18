<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemTaxType extends Model
{
    protected $table = 'm_item_tax_type';
    protected $fillable = [
        'name',
        'description',
    ];
}
