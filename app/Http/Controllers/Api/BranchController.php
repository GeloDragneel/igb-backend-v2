<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\Employee;
use App\Models\UserClient;
use App\Models\UserClientStaff;
use App\Models\UserStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class BranchController extends Controller
{
    // Designations (m_user_type ids)
    const DATA_ANALYST = 3;
    const AUDIT_SUPERVISOR = 10;
    const SUPERVISOR = 4;
    const CEO = 2;

    /** GET /api/branches – client accounts the logged-in user can switch between */
    public function index(Request $request)
    {
        return response()->json($this->rowsFor($request->user()));
    }

    /** Client accounts this user may switch to (used by /branches and /switch-branch) */
    public function rowsFor($user)
    {
        if ($user instanceof UserStaff)       return $this->forStaff($user);
        if ($user instanceof UserClientStaff) return $this->forClientStaff($user);
        if ($user instanceof UserClient)      return $this->forClient($user);
        return collect();
    }

    /* ---------------- Staff (same rules as the old query) ---------------- */
    private function forStaff(UserStaff $user)
    {
        $emp = Employee::where('com_id', $user->comid)->first();
        if (!$emp) return [];

        $empId       = (int) $emp->id;
        $designation = (int) $emp->designation_id;
        $seesAll     = in_array($designation, [self::AUDIT_SUPERVISOR, self::SUPERVISOR, self::CEO], true);

        $q = $this->base();

        if ($designation === self::DATA_ANALYST) {
            $q->where('A.assigned_daid', $empId)
              ->leftJoin('m_employee as C', 'C.id', '=', 'A.assigned_auid')
              ->leftJoin('m_user_client_staff as D', function ($j) {
                  $j->on('D.employee_id', '=', 'A.assigned_daid')->on('D.caid', '=', 'A.id');
              });
        } elseif ($seesAll) {
            $q->leftJoin('m_employee as C', 'C.id', '=', 'A.assigned_daid')
              ->leftJoin('m_user_client_staff as D', function ($j) use ($empId) {
                  $j->on('D.caid', '=', 'A.id')->where('D.employee_id', '=', $empId);
              });
        } else {
            $q->where('A.assigned_auid', $empId)
              ->leftJoin('m_employee as C', 'C.id', '=', 'A.assigned_daid')
              ->leftJoin('m_user_client_staff as D', function ($j) {
                  $j->on('D.employee_id', '=', 'A.assigned_auid')->on('D.caid', '=', 'A.id');
              });
        }

        return $this->finish($q, function ($r) use ($designation, $seesAll, $empId) {
            return $designation === self::DATA_ANALYST
                ? (int) $r->assigned_daid
                : ($seesAll ? $empId : (int) $r->assigned_auid);
        });
    }

    /* ---------------- ClientStaff ----------------
     * Every account with this username, PLUS – when the login is an employee
     * (employee_id > 0) – every client account assigned to that employee
     * as DA (m_client_account.assigned_daid) or Auditor (assigned_auid).
     */
    private function forClientStaff(UserClientStaff $user)
    {
        $empId = (int) $user->employee_id;

        $q = $this->base()
            ->leftJoin('m_user_client_staff as D', function ($j) use ($user) {
                $j->on('D.caid', '=', 'A.id')
                  ->where('D.username', '=', $user->username)
                  ->where('D.is_active', '=', 1);
            })
            ->leftJoin('m_employee as C', 'C.id', '=', 'A.assigned_daid')
            ->where(function ($w) use ($empId) {
                $w->whereNotNull('D.id');
                if ($empId > 0) {
                    $w->orWhere('A.assigned_daid', $empId)
                      ->orWhere('A.assigned_auid', $empId);
                }
            });

        return $this->finish($q, fn ($r) => $empId);
    }

    /* ---------------- Client: every branch with the same client number ---------------- */
    private function forClient(UserClient $user)
    {
        $clientNum = optional(ClientAccount::find($user->caid))->client_num;
        if (!$clientNum) return [];

        $q = $this->base()
            ->where('A.client_num', $clientNum)
            ->leftJoin('m_employee as C', 'C.id', '=', 'A.assigned_daid')
            ->leftJoin('m_user_client_staff as D', function ($j) {
                $j->on('D.caid', '=', 'A.id')->whereRaw('1 = 0'); // no staff login column for clients
            });

        return $this->finish($q, fn ($r) => 0);
    }

    /* ---------------- shared ---------------- */

    private function base()
    {
        return DB::table('m_client_account as A')
            ->leftJoin('m_client_account_status as B', 'B.id', '=', 'A.status_id');
    }

    private function finish($q, callable $employeeIdFor)
    {
        $statusCol = collect(['description', 'name', 'status', 'client_acc_status', 'ClientAccStatus'])
            ->first(fn ($c) => Schema::hasColumn('m_client_account_status', $c));
            return $q->select([
                'A.id as caid',
                'A.client_num',
                DB::raw('COALESCE((SELECT MIN(E.id) FROM m_user_client E WHERE E.caid = A.id), 0) as master_user_id'),
                'D.username',
                'A.branch_code',
                'A.branch_type',
                'A.trade_name',
                'A.tax_registration',
                $statusCol ? "B.$statusCol as status" : DB::raw('NULL as status'),
                DB::raw("CONCAT(C.last_name, ' ', LEFT(C.first_name, 1), '.') as da_name"),
                'A.assigned_daid',
                'A.assigned_auid',
                DB::raw('COALESCE(D.id, 0) as login_id'),
            ])
            ->distinct()
            ->orderBy('A.trade_name')
            ->orderBy('A.branch_code')
            ->get()
            ->map(function ($r) use ($employeeIdFor) {
                $r->employee_id = $employeeIdFor($r);
                $r->has_login   = (int) $r->login_id > 0;
                return $r;
            })
            ->values();
    }
}