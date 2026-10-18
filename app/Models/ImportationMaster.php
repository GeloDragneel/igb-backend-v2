<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ImportationMaster extends Model
{
    protected $table = 't_importation_master';
    protected $guarded = ['id'];

    protected $casts = [
        'release_date' => 'date', 'vat_payment_date' => 'date', 'ex_rate' => 'float',
        'total_landed_cost' => 'float', 'total_charge_before' => 'float', 'total_dutiable_value' => 'float',
        'total_vat' => 'float', 'total_taxable_imports' => 'float', 'total_exempt_imports' => 'float',
    ];

    public function seller(): BelongsTo { return $this->belongsTo(Seller::class, 'seller_id'); }
    public function details(): HasMany { return $this->hasMany(ImportationDetail::class, 'importation_master_id'); }
}
