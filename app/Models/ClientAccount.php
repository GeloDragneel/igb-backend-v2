<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccount extends Model
{
    protected $table = 'm_client_account';

    protected $fillable = [
        'client_num',
        'branch_code',
        'branch_type',
        'trade_name',
        'tax_registration',
        'nob_id',
        'street',
        'brgy_id',
        'city_id',
        'province_id',
        'phone_num',
        'tell_num',
        'email_address',
        'status_id',
        'assigned_daid',
        'assigned_auid',
        'is_active',
    ];

    protected $casts = [
        'nob_id'        => 'integer',
        'brgy_id'       => 'integer',
        'city_id'       => 'integer',
        'province_id'   => 'integer',
        'status_id'     => 'integer',
        'assigned_daid' => 'integer',
        'assigned_auid' => 'integer',
        'is_active'     => 'boolean',
    ];

    /* ---------- relations ---------- */

    public function natureOfBusiness(): BelongsTo
    {
        return $this->belongsTo(NatureOfBusiness::class, 'nob_id');
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

    public function assignedDa(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_daid');
    }

    public function assignedAu(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_auid');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'com_id');
    }

    public function clientsAdded(): HasMany
    {
        return $this->hasMany(Client::class, 'added_by_caid');
    }

    public function users(): HasMany
    {
        return $this->hasMany(UserClient::class, 'caid');
    }

    /* ---------- scopes ---------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}