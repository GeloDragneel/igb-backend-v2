<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceDetail extends Model
{
    protected $table = 't_sales_invoice_detail';
    protected $fillable = [
        'invoice_no',
        'item_id',
        'unit_type_id',
        'quantity',
        'unit_price',
        'amount',
        'discount_amount',
        'input_vat',
        'net_of_vat',
        'vat_exempt',
        'non_vat',
        'zero_rated',
        'currency',
        'ex_rate',
        'warehouse_id',
        'cost_each',
        'total_cost',
        'allocation_id',
        'account_code',
        '2307_vt_declared_month',
        '2307_it_declared_month',
        'is_pos',
        'added_by',
        'declared_month',
        'invoice_status_id',
        'transaction_date',
        'document_no',
        'customer_id',
        'assigned_daid',
        'assigned_auid',
        'audit_status_id',
        'trans_type',
    ];

    protected $casts = [
        'transaction_date'  => 'date:Y-m-d',
        'quantity'          => 'decimal:2',
        'unit_price'        => 'decimal:4',
        'amount'            => 'decimal:4',
        'discount_amount'   => 'decimal:4',
        'input_vat'         => 'decimal:4',
        'net_of_vat'        => 'decimal:4',
        'vat_exempt'        => 'decimal:4',
        'non_vat'           => 'decimal:4',
        'zero_rated'        => 'decimal:4',
        'ex_rate'           => 'decimal:4',
        'cost_each'         => 'decimal:4',
        'total_cost'        => 'decimal:4',
        'item_id'           => 'integer',
        'unit_type_id'      => 'integer',
        'warehouse_id'      => 'integer',
        'allocation_id'     => 'integer',
        'added_by'          => 'integer',
        'invoice_status_id' => 'integer',
        'customer_id'       => 'integer',
        'assigned_daid'     => 'integer',
        'assigned_auid'     => 'integer',
        'audit_status_id'   => 'integer',
        'is_pos'            => 'boolean',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceMaster::class, 'invoice_no', 'invoice_no');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Items::class, 'item_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'customer_id');
    }

    public function invoiceStatus(): BelongsTo
    {
        return $this->belongsTo(InvoiceStatus::class, 'invoice_status_id');
    }

    public function auditStatus(): BelongsTo
    {
        return $this->belongsTo(AuditStatus::class, 'audit_status_id');
    }
}