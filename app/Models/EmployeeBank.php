<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBank extends Model
{
    protected $table = 't_employee_bank';

    protected $fillable = [
        'employee_id',
        'bank_name',
        'branch',
        'account_name',
        'account_no',
        'account_type',
        'is_default',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
