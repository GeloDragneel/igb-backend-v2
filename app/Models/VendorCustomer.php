<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorCustomer extends Model
{
    protected $table = 'm_vendor_customer';

    protected $fillable = [
        'name',
        'tin',
        'tax_type',
        'branch_type',
        'branch_code',
        'address',
        'city_country',
        'entry_by',
        'link_by_caid',
        'user_type',
        'is_default',
    ];

    protected $casts = [
        'tax_type'      => 'integer',
        'branch_type'   => 'integer',
        'entry_by_caid' => 'integer',
        'link_by_caid'  => 'integer',
        'is_default'    => 'boolean',
    ];

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'entry_by_caid');
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'link_by_caid');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoiceMaster::class, 'customer_id');
    }

    // Customers of one client account
    public function scopeForClient($q, int $caid)
    {
        return $q->where('link_by_caid', $caid);
    }
}