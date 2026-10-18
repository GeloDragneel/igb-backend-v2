<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAccess extends Model
{
    protected $table = 'm_user_access';

    protected $fillable = [
        'name',
        'description',
    ];

    public function userStaff(): HasMany
    {
        return $this->hasMany(UserStaff::class, 'user_access_id');
    }
}