<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricMap extends Model
{
    protected $table = 'm_biometric_map';

    protected $fillable = [
        'en_no',
        'employee_id',
        'device_name',
        'created_by',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}