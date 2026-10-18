<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserLevel extends Model
{
    protected $table = 'm_user_level';

    protected $fillable = [
        'name',
        'description',
    ];

    public function userStaff(): HasMany
    {
        return $this->hasMany(UserStaff::class, 'user_level_id');
    }
}