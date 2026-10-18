<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxType extends Model
{
    protected $table = 'm_tax_type';

    protected $fillable = ['name'];

    public function items(): HasMany
    {
        return $this->hasMany(Items::class, 'tax_type_id');
    }
}