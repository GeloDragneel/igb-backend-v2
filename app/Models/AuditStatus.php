<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditStatus extends Model
{
    protected $table = 'm_audit_status';

    protected $fillable = ['name'];

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoiceMaster::class, 'audit_status_id');
    }
}