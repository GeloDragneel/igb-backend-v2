<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhicBracket extends Model
{
    protected $table = 'm_phic_bracket';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** the active bracket of a monthly salary */
    public static function forSalary(float $monthly): ?self
    {
        return static::query()->where('is_active', true)->where('range_from', '<=', $monthly)
            ->orderByDesc('range_from')->first();
    }
}