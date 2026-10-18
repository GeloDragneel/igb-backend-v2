<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollExclusion extends Model
{
    protected $table = 't_payroll_exclusion';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'skip_sss',
        'skip_philhealth',
        'skip_pagibig',
        'skip_loans',
        'skip_loan_ids',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'skip_sss'        => 'boolean',
        'skip_philhealth' => 'boolean',
        'skip_pagibig'    => 'boolean',
        'skip_loans'      => 'boolean',
        'skip_loan_ids'   => 'array',
    ];
}