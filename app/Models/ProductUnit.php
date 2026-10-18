<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    protected $table = 'm_product_unit';

    protected $fillable = [
        'product_id',
        'unit_type_id',
        'user_id',
        'description',
        'price',
        'cost',
    ];

    protected $casts = [
        'product_id'   => 'integer',
        'unit_type_id' => 'integer',
        'user_id'      => 'integer',
        'price'        => 'decimal:4',
        'cost'         => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Items::class, 'product_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_type_id');
    }
}