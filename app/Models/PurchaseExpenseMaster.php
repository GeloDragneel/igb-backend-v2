<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// old tbl2_apinvoice_master
class PurchaseExpenseMaster extends Model
{
    protected $table = 't_purchase_expense_master';

    protected $fillable = [
        'vendor_id',
        'added_by',
        'transaction_date',
        'declared_month',
        'account_code',
        'document_no',
        'invoice_no',
        'currency',
        'ex_rate',
        'sub_total',
        'total',
        'grand_total',
        'discount_amount',
        'discount_type_id',
        'atc_vt_id',
        'atc_it_id',
        '2307_vt',
        '2307_it',
        '2307_vt_declared_month',
        '2307_it_declared_month',
        'remarks',
        'attachment',
        'document_type_id',
        'invoice_status_id',
        'is_pos',
        'is_scanned',
        'assigned_daid',
        'assigned_auid',
        'audit_status_id',
        'audit_remarks',
        'seq_no',
        'trans_type',
    ];

    protected $casts = [
        'vendor_id'         => 'integer',
        'added_by'          => 'integer',
        'transaction_date'  => 'date:Y-m-d',
        'ex_rate'           => 'decimal:4',
        'sub_total'         => 'decimal:4',
        'total'             => 'decimal:4',
        'grand_total'       => 'decimal:4',
        'discount_amount'   => 'decimal:4',
        'discount_type_id'  => 'integer',
        'atc_vt_id'         => 'integer',
        'atc_it_id'         => 'integer',
        '2307_vt'           => 'decimal:4',
        '2307_it'           => 'decimal:4',
        'document_type_id'  => 'integer',
        'invoice_status_id' => 'integer',
        'is_pos'            => 'boolean',
        'is_scanned'        => 'boolean',
        'assigned_daid'     => 'integer',
        'assigned_auid'     => 'integer',
        'audit_status_id'   => 'integer',
        'seq_no'            => 'integer',
    ];

    /* ---------- relations ---------- */

    public function details(): HasMany
    {
        return $this->hasMany(PurchaseExpenseDetail::class, 'invoice_no', 'invoice_no');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'vendor_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'added_by');
    }

    public function atcVt(): BelongsTo
    {
        return $this->belongsTo(ATC::class, 'atc_vt_id');
    }

    public function atcIt(): BelongsTo
    {
        return $this->belongsTo(ATC::class, 'atc_it_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
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

    // PurchaseExpenseMaster::purchases()->get()
    public function scopePurchases($q)
    {
        return $q->whereRaw('LOWER(trans_type) = ?', ['purchases']);
    }

    // PurchaseExpenseMaster::expenses()->get()
    public function scopeExpenses($q)
    {
        return $q->whereRaw('LOWER(trans_type) = ?', ['expenses']);
    }

    // not VOID
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