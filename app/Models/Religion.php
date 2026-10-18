<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Religion extends Model
{
    protected $table = 'm_religion';

    protected $fillable = [
        'name',
        'description',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'religion_id');
    }
}