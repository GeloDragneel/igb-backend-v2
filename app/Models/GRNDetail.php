<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GRNDetail extends Model
{
    protected $table = 't_grn_detail';

    protected $fillable = [
        'item_id', 'qty', 'price', 'unit_type_id', 'account_code', 'total', 'discount_amount',
        'po_number', 'vendor_id', 'grn_no', 'grn_date', 'receive_qty', 'allocation_id', 'po_id',
        'is_imported', 'invoice_no', 'warehouse_id', 'input_vat', 'net_of_vat', 'vat_exempt',
        'non_vat', 'zero_rated', 'is_temp', '2307_vt_declared_month', '2307_it_declared_month',
        'receive_by', 'grn_status_id', 'declared_month',
    ];

    protected $casts = [
        'item_id'         => 'integer',
        'unit_type_id'    => 'integer',
        'vendor_id'       => 'integer',
        'allocation_id'   => 'integer',
        'po_id'           => 'integer',
        'warehouse_id'    => 'integer',
        'receive_by'      => 'integer',
        'grn_status_id'   => 'integer',
        'is_imported'     => 'boolean',
        'is_temp'         => 'boolean',
        'grn_date'        => 'date:Y-m-d',
        'qty'             => 'decimal:2',
        'price'           => 'decimal:4',
        'total'           => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'receive_qty'     => 'decimal:4',
        'input_vat'       => 'decimal:4',
        'net_of_vat'      => 'decimal:4',
        'vat_exempt'      => 'decimal:4',
        'non_vat'         => 'decimal:4',
        'zero_rated'      => 'decimal:4',
    ];

    public function master(): BelongsTo
    {
        return $this->belongsTo(GRNMaster::class, 'grn_no', 'grn_no');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Items::class, 'item_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_id');
    }
}