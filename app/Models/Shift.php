<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $table = 'm_shift';

    protected $fillable = [
        'code',
        'name',
        'time_in',
        'break_in',
        'break_out',
        'time_out',
        'is_overnight',
        'grace_minutes',
        'work_days',
        'paid_hours',
        'is_default',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_overnight'  => 'boolean',
        'is_default'    => 'boolean',
        'is_active'     => 'boolean',
        'grace_minutes' => 'integer',
        'paid_hours'    => 'decimal:2',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'shift_id');
    }

    /** the shift used when an employee has none */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->first();
    }

    /** ISO day numbers the shift works (1 = Mon … 7 = Sun) */
    public function workDays(): array
    {
        return array_map('intval', array_filter(explode(',', (string) $this->work_days)));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
