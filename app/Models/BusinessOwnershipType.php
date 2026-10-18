<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessOwnershipType extends Model
{
    protected $table = 'm_business_ownership_type';

    protected $fillable = [
        'name',
        'description',
    ];

    // Sub-types (m_business_ownership_type_detail)
    public function details(): HasMany
    {
        return $this->hasMany(BusinessOwnershipTypeDetail::class, 'business_ownership_type_id');
    }

    // Clients with this ownership type
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'business_ownership_type_id');
    }
}