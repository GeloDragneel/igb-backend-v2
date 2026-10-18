<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $table = 'm_document_type';

    protected $fillable = ['name'];

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoiceMaster::class, 'document_type_id');
    }
}