<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ImportationDetail extends Model
{
    protected $table = 't_importation_detail';
    protected $guarded = ['id'];

    protected $casts = [
        'release_date' => 'date', 'vat_payment_date' => 'date', 'ex_rate' => 'float',
        'total_landed_cost' => 'float', 'charges_from_custom' => 'float', 'dutiable_value' => 'float',
        'amount_of_vat' => 'float', 'taxable_imports' => 'float', 'exempt_imports' => 'float',
    ];

    public function master(): BelongsTo { return $this->belongsTo(ImportationMaster::class, 'importation_master_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Items::class, 'item_id'); }
}
