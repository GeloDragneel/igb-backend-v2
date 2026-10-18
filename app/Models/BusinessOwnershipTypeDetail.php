<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessOwnershipTypeDetail extends Model
{
    protected $table = 'm_business_ownership_type_detail';

    protected $fillable = [
        'name',
        'business_ownership_type_id',
    ];

    protected $casts = [
        'business_ownership_type_id' => 'integer',
    ];

    // Parent type
    public function type(): BelongsTo
    {
        return $this->belongsTo(BusinessOwnershipType::class, 'business_ownership_type_id');
    }

    // Clients with this sub-type
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'business_ownership_type_detail_id');
    }
}