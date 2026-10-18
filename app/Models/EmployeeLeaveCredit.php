<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveCredit extends Model
{
    protected $table = 't_employee_leave_credit';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'allowed_days',
        'carried_over',
        'used_days',
        'created_by',
    ];

    protected $casts = [
        'year'         => 'integer',
        'allowed_days' => 'decimal:2',
        'carried_over' => 'decimal:2',
        'used_days'    => 'decimal:2',
    ];

    protected $appends = ['remaining_days'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function getRemainingDaysAttribute(): float
    {
        return round((float) $this->allowed_days + (float) $this->carried_over - (float) $this->used_days, 2);
    }
}
