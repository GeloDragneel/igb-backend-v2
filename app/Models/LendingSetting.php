<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LendingSetting extends Model
{
    protected $table = 'm_lending_setting';

    protected $fillable = [
        'name',
        'applies_to',
        'interest_type',
        'interest_rate',
        'fixed_interest',
        'min_amount',
        'max_amount',
        'max_term_months',
        'penalty_rate_per_day',
        'lock_in_days',
        'payroll_deductible',
        'is_default',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'interest_rate'        => 'decimal:6',
        'fixed_interest'       => 'decimal:2',
        'min_amount'           => 'decimal:2',
        'max_amount'           => 'decimal:2',
        'penalty_rate_per_day' => 'decimal:6',
        'lock_in_days'         => 'integer',
        'max_term_months'      => 'integer',
        'payroll_deductible'   => 'boolean',
        'is_default'           => 'boolean',
        'is_active'            => 'boolean',
    ];
}
