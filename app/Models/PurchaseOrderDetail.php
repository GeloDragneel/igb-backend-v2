<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// old tbl2_porder_detail
class PurchaseOrderDetail extends Model
{
    protected $table = 't_porder_detail';

    protected $fillable = [
        'po_master_id',
        'item_id',
        'qty',
        'price',
        'total',
        'unit_type_id',
        'account_code',
        'currency',
        'ex_rate',
        'receive_date',
        'receive_qty',
        'po_number',
        'po_status_id',
        'invoice_pv',
        'input_vat',
        'net_of_vat',
        'vat_exempt',
        'non_vat',
        'zero_rated',
        'is_temp',
        'discount_amount',
        '2307_vt_declared_month',
        '2307_it_declared_month',
        'po_date',
        'document_no',
        'vendor_id',
        'trans_type',
    ];

    protected $casts = [
        'po_master_id'    => 'integer',
        'item_id'         => 'integer',
        'qty'             => 'decimal:2',
        'price'           => 'decimal:4',
        'total'           => 'decimal:4',
        'unit_type_id'    => 'integer',
        'ex_rate'         => 'decimal:4',
        'receive_date'    => 'date:Y-m-d',
        'receive_qty'     => 'decimal:4',
        'po_status_id'    => 'integer',
        'input_vat'       => 'decimal:4',
        'net_of_vat'      => 'decimal:4',
        'vat_exempt'      => 'decimal:4',
        'non_vat'         => 'decimal:4',
        'zero_rated'      => 'decimal:4',
        'is_temp'         => 'boolean',
        'discount_amount' => 'decimal:4',
        'po_date'         => 'date:Y-m-d',
        'vendor_id'       => 'integer',
    ];

    /* ---------- relations ---------- */

    public function master(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderMaster::class, 'po_number', 'po_number');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Items::class, 'item_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_type_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_id');
    }
    public function poStatus(): BelongsTo
    {
        return $this->belongsTo(POStatus::class, 'po_status_id');
    }
}