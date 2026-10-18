<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Citizenship extends Model
{
    protected $table = 'm_citizenship';

    protected $fillable = [
        'name',
        'description',
    ];

    // Employees with this citizenship
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'citizenship_id');
    }
}