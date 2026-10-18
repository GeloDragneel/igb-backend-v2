<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserType extends Model
{
    protected $table = 'm_user_type';

    protected $fillable = [
        'name',
        'description',
    ];

    public function userStaff(): HasMany
    {
        return $this->hasMany(UserStaff::class, 'user_type_id');
    }

    // Employees with this designation
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_id');
    }
}