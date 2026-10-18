<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFee extends Model
{
    protected $table = 'm_service_fee';

    protected $fillable = [
        'service_fee',
        'remarks',
        'service_cat_id',
        'com_dep_id',
        'is_active',
        'ser_list_id',
    ];

    protected $casts = [
        'service_fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function serviceList(): BelongsTo
    {
        return $this->belongsTo(ServiceList::class, 'ser_list_id');
    }
}
