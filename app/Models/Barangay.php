<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $table = 'm_barangay';

    protected $fillable = [
        'description',
        'city_id',
    ];

    protected $casts = [
        'city_id' => 'integer',
    ];

    // Barangay belongs to a city/municipality
    public function city(): BelongsTo
    {
        return $this->belongsTo(CityMun::class, 'city_id');
    }

    // Employees living in this barangay
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'brgy_id');
    }

    // Clients in this barangay
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'brgy_id');
    }
}