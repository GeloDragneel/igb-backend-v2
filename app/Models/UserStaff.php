<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
class UserStaff extends Model
{
    use HasApiTokens;
    
    protected $table = 'm_user_staff';

    protected $fillable = [
        'comid',
        'username',
        'password',
        'salutation',
        'user_type_id',
        'user_access_id',
        'user_level_id',
        'location_id',
        'is_reset_password',
        'is_active',
        'entered_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'comid'             => 'integer',
        'user_type_id'      => 'integer',
        'user_access_id'    => 'integer',
        'user_level_id'     => 'integer',
        'location_id'       => 'integer',
        'entered_by'        => 'integer',
        'password'          => 'hashed',
        'is_reset_password' => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function userAccess(): BelongsTo
    {
        return $this->belongsTo(UserAccess::class, 'user_access_id');
    }

    public function userLevel(): BelongsTo
    {
        return $this->belongsTo(UserLevel::class, 'user_level_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}