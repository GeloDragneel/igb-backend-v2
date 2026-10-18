<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientValidId extends Model
{
    protected $table = 'm_client_valid_id';                     // (table name)

    protected $fillable = ['client_num', 'client_num_type', 'institution', 'id_num'];

    // TIN rows only
    public function scopeBir($q)
    {
        return $q->where('institution', 'BIR');
    }
}