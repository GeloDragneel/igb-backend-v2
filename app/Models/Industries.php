<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Industries extends Model
{
    protected $table = 'm_industries';

    protected $fillable = [
        'name',
        'description',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'industries_id');
    }
}