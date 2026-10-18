<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmploymentStatus extends Model
{
    protected $table = 'm_employment_status';

    protected $fillable = [
        'name',
        'description',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'employment_stat_id');
    }
}