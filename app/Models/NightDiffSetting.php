<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NightDiffSetting extends Model
{
    protected $table = 'm_night_diff_setting';

    protected $fillable = [
        'start_time',
        'end_time',
        'rate',
        'effective_from',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'rate'           => 'decimal:4',
        'effective_from' => 'date:Y-m-d',
        'is_active'      => 'boolean',
    ];

    /** the setting in force on a date */
    public static function inForce(string $date): ?self
    {
        return static::query()->where('is_active', true)->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->first();
    }
}
