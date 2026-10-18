<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoiceMaster extends Model
{
    protected $table = 't_sales_invoice_master';
    protected $fillable = [
        'customer_id',
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
        'transaction_date'  => 'date:Y-m-d',
        'ex_rate'           => 'decimal:4',
        'sub_total'         => 'decimal:4',
        'total'             => 'decimal:4',
        'grand_total'       => 'decimal:4',
        'discount_amount'   => 'decimal:4',
        '2307_vt'           => 'decimal:4',
        '2307_it'           => 'decimal:4',
        'customer_id'       => 'integer',
        'added_by'          => 'integer',
        'atc_vt_id'         => 'integer',
        'atc_it_id'         => 'integer',
        'document_type_id'  => 'integer',
        'invoice_status_id' => 'integer',
        'assigned_daid'     => 'integer',
        'assigned_auid'     => 'integer',
        'audit_status_id'   => 'integer',
        'is_pos'            => 'boolean',
        'is_scanned'        => 'boolean',
    ];

    /* ---------- relations ---------- */

    // Lines are linked by invoice_no
    public function details(): HasMany
    {
        return $this->hasMany(SalesInvoiceDetail::class, 'invoice_no', 'invoice_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'customer_id');
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

    // SalesInvoiceMaster::declared('2026-09')->get()
    public function scopeDeclared($q, string $ym)
    {
        return $q->where('declared_month', $ym);
    }
}