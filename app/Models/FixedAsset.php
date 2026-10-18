<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    protected $table = 't_fixed_assets';
    protected $guarded = ['id'];
    protected $casts = [
        'acquisition_date' => 'date', 'disposed_date' => 'date',
        'cost' => 'float', 'salvage_value' => 'float', 'disposal_amount' => 'float', 'useful_life_months' => 'integer',
    ];

    public function depreciation(): HasMany { return $this->hasMany(FixedAssetDepreciation::class, 'asset_id'); }
}
