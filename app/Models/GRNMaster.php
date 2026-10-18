<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GRNMaster extends Model
{
    protected $table = 't_grn_master';

    protected $fillable = [
        'grn_no', 'ref_data', 'vendor_id', 'grn_date', 'total', 'grand_total', 'location_id',
        'receive_by', 'invoice_status_id', 'grn_status_id', 'sub_total', 'atc_vt_id', '2307_vt',
        'atc_it_id', '2307_it', 'discount_type_id', 'discount_amount', 'attachment', 'declared_month',
        'is_temp', '2307_vt_declared_month', '2307_it_declared_month',
    ];

    protected $casts = [
        'vendor_id'         => 'integer',
        'location_id'       => 'integer',
        'receive_by'        => 'integer',
        'invoice_status_id' => 'integer',
        'grn_status_id'     => 'integer',
        'atc_vt_id'         => 'integer',
        'atc_it_id'         => 'integer',
        'discount_type_id'  => 'integer',
        'is_temp'           => 'boolean',
        'grn_date'          => 'date:Y-m-d',
        'total'             => 'decimal:4',
        'grand_total'       => 'decimal:4',
        'sub_total'         => 'decimal:4',
        'discount_amount'   => 'decimal:4',
        '2307_vt'           => 'decimal:4',
        '2307_it'           => 'decimal:4',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(GRNDetail::class, 'grn_no', 'grn_no');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_id');
    }
}