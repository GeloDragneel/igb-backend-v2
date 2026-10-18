<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ATC extends Model
{
    protected $table = 'm_atc';

    protected $fillable = [
        'name',
        'description',
        'rate',
        'category',
    ];

    protected $casts = [
        'rate'     => 'float',
        'category' => 'integer',
    ];

    // "WC158 - Goods" for dropdowns
    public function getLabelAttribute(): string
    {
        return trim($this->name . ($this->description ? ' - ' . $this->description : ''));
    }

    /* ---------- relations ---------- */

    // Sales invoices using this ATC as VT/PT (2307 VT)
    public function salesInvoicesVt(): HasMany
    {
        return $this->hasMany(SalesInvoiceMaster::class, 'atc_vt_id');
    }

    // Sales invoices using this ATC as IT (2307 IT)
    public function salesInvoicesIt(): HasMany
    {
        return $this->hasMany(SalesInvoiceMaster::class, 'atc_it_id');
    }

    /* ---------- scopes ---------- */

    // ATC::category(1)->get()
    public function scopeCategory($query, int $category)
    {
        return $query->where('category', $category);
    }
}