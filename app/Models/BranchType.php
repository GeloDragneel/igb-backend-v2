<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchType extends Model
{
    protected $table = 'm_branch_type';

    protected $fillable = [
        'name',
        'branch_code',
    ];
}