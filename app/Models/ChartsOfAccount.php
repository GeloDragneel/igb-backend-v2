<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChartsOfAccount extends Model
{
    protected $table = 'm_charts_of_account';

    protected $fillable = [
        'root_name',
        'account_code',
        'account_name',
        'account_type',
        'description',
        'is_for_paid',
        'is_sales',
        'is_purchases',
        'is_expenses',
        'added_by',
    ];

    protected $casts = [
        'is_for_paid'  => 'boolean',
        'is_sales'     => 'boolean',
        'is_purchases' => 'boolean',
        'is_expenses'  => 'boolean',
        'added_by'     => 'integer',
    ];

    // "42100 - Cash Sales" for dropdowns
    public function getLabelAttribute(): string
    {
        return trim($this->account_code . ' - ' . $this->account_name, ' -');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'added_by');
    }

    /* ---------- scopes ---------- */
    public function scopeSales($q)     { return $q->where('is_sales', 1); }
    public function scopePurchases($q) { return $q->where('is_purchases', 1); }
    public function scopeExpenses($q)  { return $q->where('is_expenses', 1); }
}