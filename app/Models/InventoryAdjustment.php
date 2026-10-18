<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    protected $table = 't_inventory_adjustment';
    protected $guarded = ['id'];
    protected $casts = ['transaction_date' => 'date', 'qty' => 'float'];

    public function item(): BelongsTo { return $this->belongsTo(Items::class, 'item_id'); }
    public function reason(): BelongsTo { return $this->belongsTo(InventoryAdjustmentReason::class, 'reason_id'); }
}
