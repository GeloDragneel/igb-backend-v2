<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;
class UserClient extends Model
{
    use HasApiTokens;
    protected $table = 'm_user_client';
    protected $fillable = [
        'caid',
        'username',
        'password',
        'is_active',
        'is_pos',
        'is_web',
        'is_mobile',
    ];

    // never send the password to the frontend
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'caid'       => 'integer',
        'password'   => 'hashed',
        'is_active'  => 'boolean',
        'is_pos'     => 'boolean',
        'is_web'     => 'boolean',
        'is_mobile'  => 'boolean',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'caid');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(UserClientStaff::class, 'user_client_id');
    }
}