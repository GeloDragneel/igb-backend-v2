<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitType extends Model
{
    protected $table = 'm_unit_type';

    protected $fillable = ['name'];

    public function items(): HasMany
    {
        return $this->hasMany(Items::class, 'unit_type_id');
    }
}