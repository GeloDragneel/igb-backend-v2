<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $table = 't_transaction';

    protected $fillable = [
        'trans_number',
        'eo_version',
        'trans_type_id',
        'service_period',
        'ca_id',
        'ass_to_id',
        'prepared_by_id',
        'date_start',
        'has_prepared',
        'has_printed',
        'is_lock',
        'is_paid',
        'remarks',
    ];

    protected $casts = [
        'eo_version' => 'integer',
        'date_start' => 'date',
        'has_prepared' => 'boolean',
        'has_printed' => 'boolean',
        'is_lock' => 'boolean',
        'is_paid' => 'boolean',
    ];

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(
            TransactionType::class,
            'trans_type_id'
        );
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(
            ClientAccount::class,
            'ca_id'
        );
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class,
            'ass_to_id'
        );
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(
            Employee::class,
            'prepared_by_id'
        );
    }
}
