<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classification extends Model
{
    protected $table = 'm_classification';

    protected $fillable = [
        'name',
        'description',
    ];

    // Clients with this classification
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'classification_id');
    }
}