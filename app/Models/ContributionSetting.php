<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContributionSetting extends Model
{
    protected $table = 'm_contribution_setting';

    protected $fillable = [
        'type',
        'effective_from',
        'ee_rate',
        'er_rate',
        'salary_floor',
        'salary_ceiling',
        'bracket_step',
        'low_salary_limit',
        'low_ee_rate',
        'ec_threshold',
        'ec_low',
        'ec_high',
        'remarks',
        'is_active',
    ];

    protected $casts = [
        'effective_from'   => 'date:Y-m-d',
        'ee_rate'          => 'decimal:4',
        'er_rate'          => 'decimal:4',
        'salary_floor'     => 'decimal:2',
        'salary_ceiling'   => 'decimal:2',
        'bracket_step'     => 'decimal:2',
        'low_salary_limit' => 'decimal:2',
        'low_ee_rate'      => 'decimal:4',
        'ec_threshold'     => 'decimal:2',
        'ec_low'           => 'decimal:2',
        'ec_high'          => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    /** the SSS / PHILHEALTH / PAGIBIG setting in force on a date */
    public static function inForce(string $type, string $date): ?self
    {
        return static::query()->where('type', $type)->where('is_active', true)->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->first();
    }
}
