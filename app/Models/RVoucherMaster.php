<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RVoucherMaster extends Model
{
    protected $table = 't_rvoucher_master';

    protected $fillable = [
        'customer_id', 'rvoucher_no', 'document_no', 'invoice_no', 'rv_date', 'rv_status',
        'account_code', 'sub_total', 'total', 'bank', 'added_by', 'attachment', 'declared_month',
        'atc_vt_id', '2307_vt', 'atc_it_id', '2307_it', '2307_vt_declared_month', '2307_it_declared_month',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'added_by'    => 'integer',
        'atc_vt_id'   => 'integer',
        'atc_it_id'   => 'integer',
        'rv_date'     => 'date:Y-m-d',
        'sub_total'   => 'decimal:4',
        'total'       => 'decimal:4',
        '2307_vt'     => 'decimal:4',
        '2307_it'     => 'decimal:4',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(RVoucherDetail::class, 'rvoucher_no', 'rvoucher_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceMaster::class, 'invoice_no', 'invoice_no');
    }
}