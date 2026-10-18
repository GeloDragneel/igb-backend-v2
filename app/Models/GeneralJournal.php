<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralJournal extends Model
{
    protected $table = 't_general_journal';
    protected $fillable = [
        'jv_no',
        'account_code',
        'subsidiary',
        'debit',
        'credit',
        'remarks',
        'transaction_date',
        'declared_month',
        'added_by',
        'employee_id',
    ];
    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'transaction_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    public function user(){
        return $this->belongsTo(UserClient::class, 'added_by');
    }
    public function employee(){
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}