<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 't_payment';

    protected $fillable = [
        'ca_id',
        'payment_col_type_id',
        'receipt_type_id',
        'payment_type_id',
        'cheque_type_id',
        'aba_id',
        'assigned_bank',
        'assigned_by',
        'prepared_by_id',
        'deposited_by_id',
        'service_id',
        'deposited_date',
        'prepared_date',
        'assigned_bank_date',
        'cheque_type_date',
        'payment_date',
        'dep_slip_number',
        'ar_number',
        'ap_number',
        'or_number',
        'service_period',
        'trans_number',
        'qty',
        'amount',
        'co_amount',
        'total_gross_amount_cal',
        'total_amount',
        'details1',
        'details2',
        'remarks',
        'is_prepared',
        'is_deposited',
        'payment_status',
    ];

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class, 'ca_id');
    }

    public function paymentColType()
    {
        return $this->belongsTo(PaymentColType::class, 'payment_col_type_id');
    }

    public function receiptType()
    {
        return $this->belongsTo(PaymentReceiptType::class, 'receipt_type_id');
    }

    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class, 'payment_type_id');
    }

    public function chequeType()
    {
        return $this->belongsTo(PaymentChequeType::class, 'cheque_type_id');
    }

    public function bankName()
    {
        return $this->belongsTo(AccountingBankName::class, 'aba_id');
    }

    public function assignedBank()
    {
        return $this->belongsTo(AccountingBankAccount::class, 'assigned_bank');
    }

    public function assignedBy()
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }

    public function preparedBy()
    {
        return $this->belongsTo(Employee::class, 'prepared_by_id');
    }

    public function depositedBy()
    {
        return $this->belongsTo(Employee::class, 'deposited_by_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
