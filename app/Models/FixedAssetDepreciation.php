<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciation extends Model
{
    protected $table = 't_fixed_asset_depreciation';
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'float'];

    public function asset(): BelongsTo { return $this->belongsTo(FixedAsset::class, 'asset_id'); }
}
