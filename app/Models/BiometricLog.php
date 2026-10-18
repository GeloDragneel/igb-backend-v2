<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class BiometricLog extends Model
{
    protected $table = 't_biometric_log';

    protected $fillable = [
        'en_no',
        'employee_id',
        'punched_at',
        'mode',
        'device_name',
        'batch',
        'uploaded_by',
    ];

    protected $casts = [
        'punched_at' => 'datetime',
    ];

    /** Philippine time "Y-m-d H:i:s" as stored */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}