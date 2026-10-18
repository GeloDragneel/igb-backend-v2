<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestTicket extends Model
{
    protected $table = 't_request_ticket';

    protected $fillable = [
        'ticket_no',
        'reference_no',
        'remarks',
        'module',
        'action',
        'declared_month',
        'request_by',
        'approved_by',
        'is_status',
    ];

    protected $casts = [
        'request_by'  => 'integer',
        'approved_by' => 'integer',
        'is_status'   => 'integer',
    ];
}