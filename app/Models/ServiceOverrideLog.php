<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOverrideLog extends Model
{
    protected $table = 't_service_override_log';
    protected $fillable = ['service_id', 'action', 'remarks', 'override_by', 'approved_by'];
}
