<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $table = 't_payroll_run';

    protected $fillable = [
        'code',
        'frequency',
        'period_from',
        'period_to',
        'pay_date',
        'cutoff_no',
        'cutoff_id',
        'deduct_contributions',
        'sss_share',
        'philhealth_share',
        'pagibig_share',
        'deduct_loans',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'prepared_by',
        'approved_by',
        'approved_at',
        'approved_via',
        'approval_requested_by',
        'released_at',
        'remarks',
    ];

    protected $casts = [
        'period_from'          => 'date:Y-m-d',
        'period_to'            => 'date:Y-m-d',
        'pay_date'             => 'date:Y-m-d',
        'deduct_contributions' => 'boolean',
        'deduct_loans'         => 'boolean',
        'sss_share'            => 'float',
        'philhealth_share'     => 'float',
        'pagibig_share'        => 'float',
        'total_gross'          => 'decimal:2',
        'total_deductions'     => 'decimal:2',
        'total_net'            => 'decimal:2',
        'approved_at'          => 'datetime',
        'released_at'          => 'datetime',
    ];

    /** share of the monthly SSS / PHILHEALTH / PAGIBIG this run deducts (0, 0.5, 1) */
    public function shareOf(string $type): float
    {
        $col = ['SSS' => 'sss_share', 'PHILHEALTH' => 'philhealth_share', 'PAGIBIG' => 'pagibig_share'][$type];
        return $this->{$col} !== null ? (float) $this->{$col} : ($this->deduct_contributions ? 1.0 : 0.0);
    }

    public function cutoff(): BelongsTo
    {
        return $this->belongsTo(PayrollCutoff::class, 'cutoff_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'payroll_run_id');
    }
}