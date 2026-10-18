<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $table = 'm_province';

    protected $fillable = [
        'description',
        'population',
        'city_count',
        'mun_count',
        'bgy_count',
        'border_type',
        'region_desc',
    ];

    protected $casts = [
        'population' => 'integer',
        'city_count' => 'integer',
        'mun_count'  => 'integer',
        'bgy_count'  => 'integer',
    ];

    public function cities(): HasMany
    {
        return $this->hasMany(CityMun::class, 'province_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'province_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'province_id');
    }

    public function clientAccounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class, 'province_id');
    }
}