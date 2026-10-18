<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemTaxTypeDesc extends Model
{
    protected $table = 'm_item_tax_type_desc';
    protected $fillable = [
        'name',
    ];
}
