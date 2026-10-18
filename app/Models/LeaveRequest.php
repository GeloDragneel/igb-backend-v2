<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $table = 't_leave_request';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'date_from',
        'date_to',
        'days',
        'is_half_day',
        'half_day_part',
        'reason',
        'attachment',
        'status',
        'approved_by',
        'approved_at',
        'approver_remarks',
        'created_by',
    ];

    protected $casts = [
        'date_from'   => 'date:Y-m-d',
        'date_to'     => 'date:Y-m-d',
        'days'        => 'decimal:2',
        'is_half_day' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}
