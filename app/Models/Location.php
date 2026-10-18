<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $table = 'm_location';

    protected $fillable = [
        'name',
        'description',
    ];

    public function userStaff(): HasMany
    {
        return $this->hasMany(UserStaff::class, 'location_id');
    }
}