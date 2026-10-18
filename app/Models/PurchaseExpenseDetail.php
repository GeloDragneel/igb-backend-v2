<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// old tbl2_apinvoice_detail
class PurchaseExpenseDetail extends Model
{
    protected $table = 't_purchase_expense_detail';

    protected $fillable = [
        'invoice_no',
        'document_no',
        'declared_month',
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
        'account_code',
        'po_number',
        'po_id',
        '2307_vt_declared_month',
        '2307_it_declared_month',
        'is_pos',
        'added_by',
        'invoice_status_id',
        'transaction_date',
        'vendor_id',
        'assigned_daid',
        'assigned_auid',
        'audit_status_id',
        'trans_type',
    ];

    protected $casts = [
        'item_id'           => 'integer',
        'unit_type_id'      => 'integer',
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
        'po_id'             => 'integer',
        'is_pos'            => 'boolean',
        'added_by'          => 'integer',
        'invoice_status_id' => 'integer',
        'transaction_date'  => 'date:Y-m-d',
        'vendor_id'         => 'integer',
        'assigned_daid'     => 'integer',
        'assigned_auid'     => 'integer',
        'audit_status_id'   => 'integer',
    ];

    /* ---------- relations ---------- */

    public function master(): BelongsTo
    {
        return $this->belongsTo(PurchaseExpenseMaster::class, 'invoice_no', 'invoice_no');
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

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'added_by');
    }

    public function invoiceStatus(): BelongsTo
    {
        return $this->belongsTo(InvoiceStatus::class, 'invoice_status_id');
    }

    public function auditStatus(): BelongsTo
    {
        return $this->belongsTo(AuditStatus::class, 'audit_status_id');
    }

    public function assignedDa(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_daid');
    }

    public function assignedAu(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_auid');
    }

    /* ---------- scopes ---------- */

    public function scopePurchases($q)
    {
        return $q->whereRaw('LOWER(trans_type) = ?', ['purchases']);
    }

    public function scopeExpenses($q)
    {
        return $q->whereRaw('LOWER(trans_type) = ?', ['expenses']);
    }

    public function scopeActive($q)
    {
        return $q->where('invoice_status_id', '<>', 5);
    }
    // posted
    public function scopePosted($q)
    {
        return $q->where('record_status', 'posted');
    }
}