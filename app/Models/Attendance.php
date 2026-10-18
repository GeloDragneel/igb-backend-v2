<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $table = 't_attendance';

    protected $fillable = [
        'employee_id',
        'work_date',
        'shift_id',
        'sched_time_in',
        'sched_break_in',
        'sched_break_out',
        'sched_time_out',
        'time_in',
        'break_in',
        'break_out',
        'time_out',
        'worked_minutes',
        'late_minutes',
        'undertime_minutes',
        'overbreak_minutes',
        'night_diff_minutes',
        'approved_ot_minutes',
        'day_type',
        'status',
        'source',
        'remarks',
        'payroll_run_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'work_date'       => 'date:Y-m-d',
        'sched_time_in'   => 'datetime',
        'sched_break_in'  => 'datetime',
        'sched_break_out' => 'datetime',
        'sched_time_out'  => 'datetime',
        'time_in'         => 'datetime',
        'break_in'        => 'datetime',
        'break_out'       => 'datetime',
        'time_out'        => 'datetime',
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}