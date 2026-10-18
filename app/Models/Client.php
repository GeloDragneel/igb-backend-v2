<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    protected $table = 'm_client';
    protected $fillable = [
        'client_num',
        'max_num',
        'last_name',
        'first_name',
        'middle_name',
        'suffix',
        'gender',
        'birthdate',
        'civ_stat_id',
        'phone_num',
        'tell_num',
        'email_address',
        'street',
        'brgy_id',
        'city_id',
        'province_id',
        'status_id',
        'business_ownership_type_id',
        'business_ownership_type_detail_id',
        'classification_id',
        'industries_id',
        'compliance_ids',
        'date_of_engagement',
        'date_of_disengagement',
        'date_started',
        'date_registered',
        'added_by_caid',
        'return_type',
        'is_active',
    ];

    protected $casts = [
        'max_num'                           => 'integer',
        'birthdate'                         => 'date:Y-m-d',
        'date_of_engagement'                => 'date:Y-m-d',
        'date_of_disengagement'             => 'date:Y-m-d',
        'date_started'                      => 'date:Y-m-d',
        'date_registered'                   => 'date:Y-m-d',
        'civ_stat_id'                       => 'integer',
        'brgy_id'                           => 'integer',
        'city_id'                           => 'integer',
        'province_id'                       => 'integer',
        'status_id'                         => 'integer',
        'business_ownership_type_id'        => 'integer',
        'business_ownership_type_detail_id' => 'integer',
        'classification_id'                 => 'integer',
        'industries_id'                     => 'integer',
        'added_by_caid'                     => 'integer',
        'is_active'                         => 'boolean',
    ];

    // "Dela Cruz, Juan Santos Jr."
    public function getFullNameAttribute(): string
    {
        $name = trim($this->last_name . ', ' . $this->first_name . ' ' . $this->middle_name);
        return trim($name . ' ' . $this->suffix, ', ');
    }

    /* ---------- relations ---------- */

    public function civilStatus(): BelongsTo
    {
        return $this->belongsTo(CivilStatus::class, 'civ_stat_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'brgy_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(CityMun::class, 'city_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ClientAccountStatus::class, 'status_id');
    }

    public function ownershipType(): BelongsTo
    {
        return $this->belongsTo(BusinessOwnershipType::class, 'business_ownership_type_id');
    }

    public function ownershipTypeDetail(): BelongsTo
    {
        return $this->belongsTo(BusinessOwnershipTypeDetail::class, 'business_ownership_type_detail_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'classification_id');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industries::class, 'industries_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'added_by_caid');
    }

    /* ---------- scopes ---------- */

    // Client::active()->get()
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}