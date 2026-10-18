<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccountStatus extends Model
{
    protected $table = 'm_client_account_status';

    protected $fillable = [
        'description',
    ];

    public function clientAccounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class, 'status_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'status_id');
    }
}