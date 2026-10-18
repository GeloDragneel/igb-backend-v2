<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ExportationDetail extends Model
{
    protected $table = 't_exportation_detail';
    protected $guarded = ['id'];

    protected $casts = [
        'export_date' => 'date', 'payment_date' => 'date', 'ex_rate' => 'float',
        'quantity' => 'float', 'fob_amount' => 'float', 'peso_amount' => 'float',
    ];

    public function master(): BelongsTo { return $this->belongsTo(ExportationMaster::class, 'exportation_master_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Items::class, 'item_id'); }
}
