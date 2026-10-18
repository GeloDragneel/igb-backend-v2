<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;
class UserClientStaff extends Model
{
    use HasApiTokens;
    protected $table = 'm_user_client_staff';
    protected $fillable = [
        'caid',
        'user_client_id',
        'fullname',
        'username',
        'password',
        'access_rights',
        'added_by',
        'updated_by',
        'is_active',
        'is_pos',
        'is_scan_suite',
        'is_web',
        'employee_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'caid'           => 'integer',
        'user_client_id' => 'integer',
        'added_by'       => 'integer',
        'updated_by'     => 'integer',
        'employee_id'    => 'integer',
        'password'       => 'hashed',
        'is_active'      => 'boolean',
        'is_pos'         => 'boolean',
        'is_scan_suite'  => 'boolean',
        'is_web'         => 'boolean',
    ];

    // "1-2-3-4" -> [1, 2, 3, 4]
    public function getAccessListAttribute(): array
    {
        return array_values(array_filter(array_map('intval', explode('-', (string) $this->access_rights))));
    }

    public function hasAccess(int $right): bool
    {
        return in_array($right, $this->access_list, true);
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'caid');
    }

    public function userClient(): BelongsTo
    {
        return $this->belongsTo(UserClient::class, 'user_client_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}