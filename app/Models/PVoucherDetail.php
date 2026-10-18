<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PVoucherDetail extends Model
{
    protected $table = 't_pvoucher_detail';

    protected $fillable = [
        'pvoucher_no', 'voucher_date', 'acc_code', 'account_no', 'item_id',
        'ap_invoice_no', 'ap_id', 'po_id', 'qty', 'f_amount', 'amount',
        'ref_data', 'payment_type',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'qty' => 'decimal:4',
        'f_amount' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function master(): BelongsTo
    {
        return $this->belongsTo(PVoucherMaster::class, 'pvoucher_no', 'pvoucher_no');
    }
}
