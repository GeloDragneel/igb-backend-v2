<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceList extends Model
{
    protected $table = 'm_service_list';

    protected $fillable = [
        'name',
        'item_tax_type_id',
        'item_tax_desc_id',
        'ser_type_id',
        'rep_type_id',
        'submission_id',
        'institution_id',
        'trans_type_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function itemTaxType(): BelongsTo
    {
        return $this->belongsTo(ItemTaxType::class, 'item_tax_type_id');
    }

    public function itemTaxDesc(): BelongsTo
    {
        return $this->belongsTo(ItemTaxTypeDesc::class, 'item_tax_desc_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'ser_type_id');
    }

    public function reportingType(): BelongsTo
    {
        return $this->belongsTo(ReportingType::class, 'rep_type_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class, 'trans_type_id');
    }

    public function serviceFees(): HasMany
    {
        return $this->hasMany(ServiceFee::class, 'ser_list_id');
    }
}
