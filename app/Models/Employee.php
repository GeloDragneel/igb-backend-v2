<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $table = 'm_employee';

    protected $fillable = [
        'com_id',
        'last_name',
        'first_name',
        'middle_name',
        'suffix',
        'birthdate',
        'gender',
        'phone_num',
        'tell_num',
        'email_address',
        'street',
        'birth_place_id',
        'brgy_id',
        'city_id',
        'province_id',
        'civ_stat_id',
        'religion_id',
        'citizenship_id',
        'designation_id',
        'biometric_num',
        'date_contact',
        'date_contact_exp',
        'employment_stat_id',
        'company_caid',
        'shift_id',
        'work_id',
        'remarks',
        'phic_no',
        'hdmf_no',
        'sss_no',
        'tin',
        'salary_month',
        'salary_day',
        'allowance',
        'mccb_acc',
        'shedule',
        'is_active',
    ];

    protected $casts = [
        'com_id'             => 'integer',
        'birthdate'          => 'date:Y-m-d',
        'date_contact'       => 'date:Y-m-d',
        'date_contact_exp'   => 'date:Y-m-d',
        'birth_place_id'     => 'integer',
        'brgy_id'            => 'integer',
        'city_id'            => 'integer',
        'province_id'        => 'integer',
        'civ_stat_id'        => 'integer',
        'religion_id'        => 'integer',
        'citizenship_id'     => 'integer',
        'designation_id'     => 'integer',
        'employment_stat_id' => 'integer',
        'company_caid'       => 'integer',
        'shift_id'           => 'integer',
        'work_id'            => 'integer',
        'salary_month'       => 'decimal:2',
        'salary_day'         => 'decimal:2',
        'allowance'          => 'decimal:2',
        'is_active'          => 'boolean',
    ];

    // "Dela Cruz, Juan Santos Jr."
    public function getFullNameAttribute(): string
    {
        $name = trim($this->last_name . ', ' . $this->first_name . ' ' . $this->middle_name);
        return trim($name . ' ' . $this->suffix, ', ');
    }

    /* ---------- relations ---------- */

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'com_id');
    }

    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'company_caid');
    }

    public function birthPlace(): BelongsTo
    {
        return $this->belongsTo(CityMun::class, 'birth_place_id');
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

    public function civilStatus(): BelongsTo
    {
        return $this->belongsTo(CivilStatus::class, 'civ_stat_id');
    }

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class, 'religion_id');
    }

    public function citizenship(): BelongsTo
    {
        return $this->belongsTo(Citizenship::class, 'citizenship_id');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'designation_id');
    }

    public function employmentStatus(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatus::class, 'employment_stat_id');
    }

    // Client accounts where this employee is assigned
    public function assignedAsDa(): HasMany
    {
        return $this->hasMany(ClientAccount::class, 'assigned_daid');
    }

    public function assignedAsAu(): HasMany
    {
        return $this->hasMany(ClientAccount::class, 'assigned_auid');
    }

    /* ---------- scopes ---------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}