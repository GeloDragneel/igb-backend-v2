<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CityMun extends Model
{
    protected $table = 'm_city_mun';
    protected $fillable = [
        'description',
        'province_id',
        'zip_code',
    ];

    protected $casts = [
        'province_id' => 'integer',
        'zip_code'    => 'integer',
    ];

    // City/municipality belongs to a province
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    // Barangays in this city/municipality
    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class, 'city_id');
    }

    // Employees living in this city
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'city_id');
    }

    // Employees born in this city
    public function employeesBornHere(): HasMany
    {
        return $this->hasMany(Employee::class, 'birth_place_id');
    }

    // Clients in this city
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'city_id');
    }
}