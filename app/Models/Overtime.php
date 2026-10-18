<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Overtime extends Model
{
    protected $table = 't_overtime';

    protected $fillable = [
        'employee_id',
        'work_date',
        'time_start',
        'time_end',
        'minutes',
        'pay_rate_code',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'approver_remarks',
        'payroll_run_id',
        'created_by',
    ];

    protected $casts = [
        'work_date'   => 'date:Y-m-d',
        'time_start'  => 'datetime',
        'time_end'    => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** dates go to the frontend as Philippine time "Y-m-d H:i:s" (as stored), not converted to UTC */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }
}