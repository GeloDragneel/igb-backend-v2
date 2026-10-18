<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationMonitoring extends Model
{
    protected $table = 't_reconciliation_monitoring';

    protected $fillable = ['client_id', 'declared_month', 'is_lock', 'is_printed', 'color_code_id'];

    protected $casts = [
        'client_id' => 'integer',
        'is_lock' => 'boolean',
        'is_printed' => 'boolean',
        'color_code_id' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}