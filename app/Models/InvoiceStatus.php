<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceStatus extends Model
{
    protected $table = 'm_invoice_status';

    protected $fillable = ['name'];

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoiceMaster::class, 'invoice_status_id');
    }
}