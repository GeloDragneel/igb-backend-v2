<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// old tbl2_porder_master
class PurchaseOrderMaster extends Model
{
    protected $table = 't_porder_master';

    protected $fillable = [
        'po_number',
        'document_no',
        'po_date',
        'vendor_id',
        'total',
        'receive_qty',
        'po_status_id',
        'added_by',
        'document_type_id',
        'remarks',
        'declared_month',
        'trans_type',
        'currency',
        'ex_rate',
        'sub_total',
        'atc_vt_id',
        '2307_vt',
        'atc_it_id',
        '2307_it',
        'grand_total',
        'discount_type_id',
        'discount_amount',
        'attachment',
        'account_code',
        'is_temp',
        '2307_vt_declared_month',
        '2307_it_declared_month',
    ];

    protected $casts = [
        'po_date'          => 'date:Y-m-d',
        'vendor_id'        => 'integer',
        'total'            => 'decimal:4',
        'receive_qty'      => 'decimal:4',
        'po_status_id'     => 'integer',
        'added_by'         => 'integer',
        'document_type_id' => 'integer',
        'ex_rate'          => 'decimal:4',
        'sub_total'        => 'decimal:4',
        'atc_vt_id'        => 'integer',
        '2307_vt'          => 'decimal:4',
        'atc_it_id'        => 'integer',
        '2307_it'          => 'decimal:4',
        'grand_total'      => 'decimal:4',
        'discount_type_id' => 'integer',
        'discount_amount'  => 'decimal:4',
        'is_temp'          => 'boolean',
    ];

    /* ---------- relations ---------- */

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseOrderDetail::class, 'po_number', 'po_number');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'added_by');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function atcVt(): BelongsTo
    {
        return $this->belongsTo(ATC::class, 'atc_vt_id');
    }

    public function atcIt(): BelongsTo
    {
        return $this->belongsTo(ATC::class, 'atc_it_id');
    }
    public function poStatus(): BelongsTo
    {
        return $this->belongsTo(POStatus::class, 'po_status_id');
    }
}