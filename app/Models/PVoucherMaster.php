<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PVoucherMaster extends Model
{
    protected $table = 't_pvoucher_master';

    protected $fillable = [
        'pvoucher_no', 'pv_date', 'vendor_id', 'pay_to', 'particulars',
        'total_amount', 'total_f_amount', 'sub_total', 'base_sub_total',
        'bank', 'pv_status', 'payment_type', 'ref_data', 'ap_invoice_no',
        'document_no', 'last_update', 'added_by',
    ];

    protected $casts = [
        'pv_date' => 'date',
        'last_update' => 'datetime',
        'total_amount' => 'decimal:4',
        'total_f_amount' => 'decimal:4',
        'sub_total' => 'decimal:4',
        'base_sub_total' => 'decimal:4',
        'pv_status' => 'integer',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(PVoucherDetail::class, 'pvoucher_no', 'pvoucher_no');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_id');
    }
}
