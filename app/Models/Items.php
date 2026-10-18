<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Items extends Model
{
    protected $table = 'm_items';

    protected $fillable = [
        'item_code',
        'upc_sku',
        'description',
        'item_class_id',
        'tax_type_id',
        'unit_type_id',
        'added_by',
        'last_unit_cost',
        'inventory_qty',
        'equivalent_conversion',
        'price',
        'minimum_stock',
        'reorder_quantity',
        'allocated_qty',
        'gl_sales_account',
        'gl_inventory_account',
        'gl_cost_of_sales_account',
        'input_vat_account',
        'output_vat_account',
        'is_active',
    ];

    protected $casts = [
        'item_class_id'         => 'integer',
        'tax_type_id'           => 'integer',
        'unit_type_id'          => 'integer',
        'added_by'              => 'integer',
        'last_unit_cost'        => 'float',
        'inventory_qty'         => 'float',
        'equivalent_conversion' => 'float',
        'price'                 => 'float',
        'minimun_stock'         => 'float',
        'reorder_quantity'      => 'float',
        'allocated_qty'         => 'float',
        'is_active'             => 'boolean',
    ];

    public function itemClass(): BelongsTo
    {
        return $this->belongsTo(ItemClass::class, 'item_class_id');
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'tax_type_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_type_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'added_by');
    }

    public function salesLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceDetail::class, 'item_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', 1);
    }
}