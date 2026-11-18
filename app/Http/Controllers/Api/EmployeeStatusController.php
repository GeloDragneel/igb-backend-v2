<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// GET /api/admin/user-access/employment-status
//   { "<employee id>": "REGULAR", ... }   m_employee.employment_stat_id → m_employment_status
class EmployeeStatusController extends Controller
{
    public function index()
    {
        $label = collect(['name', 'status', 'employment_status', 'description', 'label'])
            ->first(fn ($c) => Schema::hasColumn('m_employment_status', $c));

        if (!$label || !Schema::hasColumn('m_employee', 'employment_stat_id')) {
            return response()->json((object) []);
        }

        $map = DB::table('m_employee as E')
            ->join('m_employment_status as S', 'S.id', '=', 'E.employment_stat_id')
            ->pluck("S.{$label}", 'E.id');

        return response()->json((object) $map->map(fn ($v) => trim((string) $v))->filter()->all());
    }
}