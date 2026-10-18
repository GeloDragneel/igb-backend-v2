<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LendingMember extends Model
{
    protected $table = 'm_lending_member';

    protected $fillable = [
        'member_type',
        'employee_id',
        'name',
        'contact_no',
        'email',
        'address',
        'bank_name',
        'account_no',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function investments(): HasMany
    {
        return $this->hasMany(LendingInvestment::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LendingLoan::class, 'member_id');
    }
}
