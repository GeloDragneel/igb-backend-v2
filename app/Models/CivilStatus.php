<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CivilStatus extends Model
{
    protected $table = 'm_civil_status';

    protected $fillable = [
        'name',
        'description',
    ];

    // Employees with this civil status
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'civ_stat_id');
    }

    // Clients with this civil status
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'civ_stat_id');
    }
}