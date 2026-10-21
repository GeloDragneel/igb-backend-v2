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
    const IT_OFFICER = 1;
    const CEO = 2;
    const DATA_ANALYST = 3;
    const SUPERVISOR = 4;
    const AUDIT_SUPERVISOR = 10;
    /** these see every client account */
    const SEES_ALL = [self::IT_OFFICER, self::CEO, self::SUPERVISOR, self::AUDIT_SUPERVISOR];

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

    /* ---------------- Staff ----------------
     * IT OFFICER / CEO / SUPERVISOR / AUDIT SUPERVISOR → every client account.
     * Everyone else → the client accounts assigned to them (DA or Auditor).
     * Nothing assigned → their company's own branch (m_employee.company_caid).
     */
    private function forStaff(UserStaff $user)
    {
        $emp = DB::table('m_employee')->where('com_id', $user->comid)->first();
        if (!$emp) return collect();

        $type = (int) ($emp->designation_id ?? 0) ?: (int) ($user->user_type_id ?? 0);
        $seesAll = in_array($type, self::SEES_ALL, true)
            || in_array((int) ($user->user_type_id ?? 0), self::SEES_ALL, true);

        return $this->forEmployee((int) $emp->id, $seesAll, (int) ($emp->company_caid ?? 0));
    }

    /* ---------------- ClientStaff ----------------
     * The client accounts assigned to the employee (DA / Auditor) + every account
     * where the employee has a login in m_user_client_staff.
     * Nothing at all → the company's own branch.
     */
    private function forClientStaff(UserClientStaff $user)
    {
        $empId = (int) $user->employee_id;
        $companyCaid = $empId ? (int) DB::table('m_employee')->where('id', $empId)->value('company_caid') : 0;

        return $this->forEmployee($empId, false, $companyCaid, (string) $user->username, (int) $user->caid);
    }

    /**
     * one row per client account, has_login = the employee has a m_user_client_staff row there
     * (or, for a client staff, a row with the same username)
     */
    private function forEmployee(int $empId, bool $seesAll, int $companyCaid, string $username = '', int $ownCaid = 0)
    {
        $logins = DB::table('m_user_client_staff')
            ->where(function ($w) use ($empId, $username) {
                $w->whereRaw('1 = 0');
                if ($empId > 0) $w->orWhere('employee_id', $empId);
                if ($username !== '') $w->orWhere('username', $username);
            })
            ->groupBy('caid')
            ->select('caid', DB::raw('MIN(id) as id'), DB::raw('MIN(username) as username'));

        $build = function (bool $onlyCompany) use ($logins, $empId, $seesAll, $companyCaid, $ownCaid) {
            $q = $this->base()
                ->leftJoinSub($logins, 'D', 'D.caid', '=', 'A.id')
                ->leftJoin('m_employee as C', 'C.id', '=', 'A.assigned_daid');
            if ($onlyCompany) return $q->where('A.id', $companyCaid);
            if ($seesAll) return $q;
            return $q->where(function ($w) use ($empId, $ownCaid) {
                $w->whereNotNull('D.id');
                if ($ownCaid > 0) $w->orWhere('A.id', $ownCaid);
                if ($empId > 0) $w->orWhere('A.assigned_daid', $empId)->orWhere('A.assigned_auid', $empId);
            });
        };

        $rows = $this->finish($build(false), fn ($r) => $empId);
        if ($rows->isEmpty() && $companyCaid > 0) {
            $rows = $this->finish($build(true), fn ($r) => $empId);
        }
        return $rows;
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
            // one row per client account: an employee with several m_user_client_staff rows
            // for the same account (duplicates) must not list that account 2-3 times
            ->sortByDesc(fn ($r) => (int) $r->login_id > 0 ? 1 : 0)
            ->unique('caid')
            ->sortBy(fn ($r) => $r->trade_name . "\x00" . $r->branch_code)
            ->map(function ($r) use ($employeeIdFor) {
                $r->employee_id = $employeeIdFor($r);
                $r->has_login   = (int) $r->login_id > 0;
                return $r;
            })
            ->values();
    }
}