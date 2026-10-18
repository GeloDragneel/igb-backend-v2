<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclaredMonthSettings extends Model
{
    protected $table = 'm_delared_month_settings';

    protected $fillable = [
        'ca_id',
        'declared_month',
    ];

    protected $casts = [
        'ca_id' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'ca_id');
    }

    // DeclaredMonthSetting::forAccount(12)->pluck('declared_month')
    public function scopeForAccount($q, int $caid)
    {
        return $q->where('ca_id', $caid);
    }

    // Is this month locked for the account?
    public static function isLocked(int $caid, string $ym): bool
    {
        return static::where('ca_id', $caid)->where('declared_month', $ym)->exists();
    }
}