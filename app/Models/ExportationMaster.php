<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ExportationMaster extends Model
{
    protected $table = 't_exportation_master';
    protected $guarded = ['id'];

    protected $casts = [
        'export_date' => 'date', 'payment_date' => 'date', 'ex_rate' => 'float',
        'total_fob_amount' => 'float', 'total_peso_amount' => 'float',
    ];

    public function buyer(): BelongsTo { return $this->belongsTo(Buyer::class, 'buyer_id'); }
    public function details(): HasMany { return $this->hasMany(ExportationDetail::class, 'exportation_master_id'); }
}
