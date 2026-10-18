<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconCompliance extends Model
{
    protected $table = 't_recon_compliance';

    protected $fillable = ['compliance_id', 'client_id', 'declared_month', 'emp_id'];

    protected $casts = [
        'compliance_id' => 'integer',
        'client_id' => 'integer',
        'emp_id' => 'integer',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'emp_id');
    }
}