<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RVoucherDetail extends Model
{
    protected $table = 't_rvoucher_detail';

    protected $fillable = [
        'account_code', 'customer_id', 'rvoucher_no', 'invoice_no', 'rv_date', 'sub_total',
        'inv_master_id', 'rv_master_id', 'declared_month', 'atc_vt_id', '2307_vt', 'atc_it_id',
        '2307_it', '2307_vt_declared_month', '2307_it_declared_month', 'added_by',
    ];

    protected $casts = [
        'customer_id'   => 'integer',
        'inv_master_id' => 'integer',
        'atc_vt_id'     => 'integer',
        'atc_it_id'     => 'integer',
        'added_by'      => 'integer',
        'rv_date'       => 'date:Y-m-d',
        'sub_total'     => 'decimal:4',
        '2307_vt'       => 'decimal:4',
        '2307_it'       => 'decimal:4',
    ];

    public function master(): BelongsTo
    {
        return $this->belongsTo(RVoucherMaster::class, 'rvoucher_no', 'rvoucher_no');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceMaster::class, 'invoice_no', 'invoice_no');
    }
}