<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileTransaction extends Model
{
    protected $table = 't_mobile_transaction';

    protected $fillable = [
        'filename',
        'trans_type',
        'account_code',
        'reference_no',
        'trans_status',
        'added_by',
        'employee_id',
        'assigned_daid',
        'assigned_auid',
    ];

    protected $casts = [
        'trans_status'  => 'integer',
        'added_by'      => 'integer',
        'employee_id'   => 'integer',
        'assigned_daid' => 'integer',
        'assigned_auid' => 'integer',
    ];

    /* ---------- relations ---------- */

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'added_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function assignedDa(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_daid');
    }

    public function assignedAu(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_auid');
    }

    /* ---------- scopes ---------- */

    // MobileTransaction::pending()->get()
    public function scopePending($q)
    {
        return $q->where('trans_status', 0);
    }

    // MobileTransaction::ofType('SALES')->get()
    public function scopeOfType($q, string $type)
    {
        return $q->where('trans_type', $type);
    }
}