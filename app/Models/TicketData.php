<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketData extends Model
{
    protected $table = 't_ticket_data';

    public $timestamps = false;   // table has no created_at / updated_at

    protected $fillable = [
        'ticket_no',
        'data_master',
        'data_detail',
    ];

    // JSON in the database, arrays in PHP
    protected $casts = [
        'data_master' => 'array',
        'data_detail' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(RequestTicket::class, 'ticket_no', 'ticket_no');
    }
}