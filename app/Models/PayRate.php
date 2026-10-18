<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayRate extends Model
{
    protected $table = 'm_pay_rate';

    protected $fillable = [
        'code',
        'description',
        'multiplier',
        'is_active',
    ];

    protected $casts = [
        'multiplier' => 'decimal:4',
        'is_active'  => 'boolean',
    ];

    /** code → multiplier */
    public static function map(): array
    {
        return static::query()->where('is_active', true)->pluck('multiplier', 'code')->map(fn ($v) => (float) $v)->all();
    }
}
