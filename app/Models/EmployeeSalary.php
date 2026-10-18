<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalary extends Model
{
    protected $table = 't_employee_salary';

    protected $fillable = [
        'employee_id',
        'salary_type',
        'daily_rate',
        'monthly_rate',
        'allowance',
        'work_days_per_year',
        'description',
        'reason',
        'effective_from',
        'effective_to',
        'is_default',
        'status',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'daily_rate'         => 'decimal:2',
        'monthly_rate'       => 'decimal:2',
        'allowance'          => 'decimal:2',
        'work_days_per_year' => 'decimal:2',
        'effective_from'     => 'date:Y-m-d',
        'effective_to'       => 'date:Y-m-d',
        'is_default'         => 'boolean',
        'approved_at'        => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** the rate payroll uses for an employee on a date (default, approved, in force) */
    public static function defaultFor(int $employeeId, ?string $date = null): ?self
    {
        $date ??= now('Asia/Manila')->toDateString();
        return static::query()->where('employee_id', $employeeId)->where('is_default', true)->where('status', 'approved')
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->first();
    }

    /** hourly rate from the daily rate and the shift's paid hours */
    public function hourlyRate(float $paidHours = 8): float
    {
        return $paidHours > 0 ? round((float) $this->daily_rate / $paidHours, 4) : 0;
    }
}
