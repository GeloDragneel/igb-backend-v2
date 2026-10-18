<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditHistory extends Model
{
    protected $table = 't_audit_history';
    protected $fillable = ['reference_no', 'auditor_id', 'audit_status_id', 'remarks'];
}