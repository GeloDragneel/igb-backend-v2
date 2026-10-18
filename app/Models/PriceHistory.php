<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    protected $table = 't_price_history';

    protected $fillable = [
        'item_id',
        'price',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'price'   => 'decimal:4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Items::class, 'item_id');
    }
}