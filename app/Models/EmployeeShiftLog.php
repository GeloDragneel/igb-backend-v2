<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeShiftLog extends Model
{
    protected $table = 't_employee_shift_log';

    protected $fillable = [
        'employee_id',
        'shift_id',
        'previous_shift_id',
        'effective_from',
        'reason',
        'changed_by',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}
