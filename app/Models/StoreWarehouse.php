<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreWarehouse extends Model
{
    protected $table = 'm_store_warehouse';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];
}
