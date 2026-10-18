<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $table = 'm_leave_type';

    protected $fillable = [
        'code',
        'name',
        'is_paid',
        'default_days',
        'is_convertible',
        'requires_attachment',
        'is_active',
    ];

    protected $casts = [
        'is_paid'             => 'boolean',
        'default_days'        => 'decimal:2',
        'is_convertible'      => 'boolean',
        'requires_attachment' => 'boolean',
        'is_active'           => 'boolean',
    ];

    public function credits(): HasMany
    {
        return $this->hasMany(EmployeeLeaveCredit::class, 'leave_type_id');
    }
}
