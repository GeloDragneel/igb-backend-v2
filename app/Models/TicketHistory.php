<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketHistory extends Model
{
    protected $table = 't_ticket_histories';

    protected $fillable = [
        'ticket_no',
        'reference_no',
        'table_data',
    ];

    protected $casts = [
        'table_data' => 'array',
    ];
}