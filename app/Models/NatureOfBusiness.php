<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NatureOfBusiness extends Model
{
    protected $table = 'm_nature_of_business';

    protected $fillable = [
        'name',
        'description',
    ];

    public function clientAccounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class, 'nob_id');
    }
}