<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring → VAT Management Dashboard
 *   t_vat_management_logs   : every adjustment saved from the Manage popup
 *   t_vat_management_status : one status per client + declared month (Pending by default · Resolved · Declined)
 *
 * routes/api.php (inside the auth:sanctum group):
 *   use App\Http\Controllers\Api\VatManagementController;
 *   Route::get('/vat-management/state', [VatManagementController::class, 'state']);
 *   Route::get('/vat-management/logs', [VatManagementController::class, 'logs']);
 *   Route::post('/vat-management/logs', [VatManagementController::class, 'addLog']);
 *   Route::post('/vat-management/status', [VatManagementController::class, 'setStatus']);
 */
class VatManagementController extends Controller
{
    const STATUSES = ['Pending', 'Resolved', 'Declined'];

    /** the employee behind this login: employee_id, else m_user_staff.comid = m_employee.com_id */
    private function employeeId(Request $request): int
    {
        $u = $request->user();
        if (!$u) return 0;
        $id = (int) ($u->employee_id ?? 0);
        if ($id <= 0 && !empty($u->comid)) $id = (int) DB::table('m_employee')->where('com_id', $u->comid)->value('id');
        return max(0, $id);
    }

    /** Auditor (5) or Audit supervisor (10) – m_employee.designation_id */
    private function isAuditor(Request $request): bool
    {
        $emp = $this->employeeId($request);
        return $emp > 0 && in_array((int) DB::table('m_employee')->where('id', $emp)->value('designation_id'), [5, 10], true);
    }

    private function month(Request $request): string
    {
        $m = (string) $request->input('month', '');
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $m), 422, 'Pick a declared month.');
        return $m;
    }

    /* GET /vat-management/state?month=YYYY-MM → status + open log count per client (for the table and the In Review card) */
    public function state(Request $request)
    {
        $month = $this->month($request);
        $status = DB::table('t_vat_management_status')->where('declared_month', $month)->pluck('status', 'client_id');
        $logs = DB::table('t_vat_management_logs')->where('declared_month', $month)
            ->groupBy('client_id')->selectRaw('client_id, COUNT(*) AS n')->pluck('n', 'client_id');

        $out = [];
        foreach ($logs as $cid => $n) $out[$cid] = ['status' => $status[$cid] ?? 'Pending', 'logs' => (int) $n];
        foreach ($status as $cid => $st) $out[$cid] ??= ['status' => $st, 'logs' => 0];
        return response()->json(['clients' => (object) $out]);
    }

    /* GET /vat-management/logs?client_id=&month= → status + every log of that client and month */
    public function logs(Request $request)
    {
        $month = $this->month($request);
        $cid = (int) $request->input('client_id');
        abort_unless($cid > 0, 422, 'Client is missing.');

        $st = DB::table('t_vat_management_status as S')
            ->leftJoin('m_employee as E', 'E.id', '=', 'S.employee_id')
            ->where('S.client_id', $cid)->where('S.declared_month', $month)
            ->first(['S.status', 'S.timestamp', DB::raw("NULLIF(TRIM(CONCAT_WS(', ', E.last_name, E.first_name)), '') AS employee")]);

        $rows = DB::table('t_vat_management_logs as L')
            ->leftJoin('m_employee as E', 'E.id', '=', 'L.employee_id')
            ->where('L.client_id', $cid)->where('L.declared_month', $month)
            ->orderByDesc('L.timestamp')->orderByDesc('L.id')
            ->get(['L.id', 'L.client_id', 'L.employee_id', 'L.declared_month', 'L.trans_type', 'L.actual_net_vat_payable',
                'L.adjusted_net_vat_payable', 'L.amount_to_adjust', 'L.remarks', 'L.timestamp',
                DB::raw("NULLIF(TRIM(CONCAT_WS(', ', E.last_name, E.first_name)), '') AS employee")]);

        return response()->json([
            'status' => $st ? ['status' => $st->status, 'timestamp' => (string) $st->timestamp, 'employee' => $st->employee] : ['status' => 'Pending'],
            'logs' => $rows,
        ]);
    }

    /* POST /vat-management/logs { client_id, month, trans_type, actual, adjusted, amount, remarks } */
    public function addLog(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'month'     => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'trans_type' => ['required', 'in:Sales,Purchase'],
            'actual'    => ['required', 'numeric'],
            'adjusted'  => ['required', 'numeric'],
            'amount'    => ['required', 'numeric'],
            'remarks'   => ['nullable', 'string', 'max:2000'],
        ]);
        $emp = $this->employeeId($request);

        // resolved = closed: no more logs
        $st = DB::table('t_vat_management_status')->where('client_id', $d['client_id'])->where('declared_month', $d['month'])->value('status');
        abort_if($st === 'Resolved', 422, 'Already resolved – no more adjustment logs can be saved.');

        DB::transaction(function () use ($d, $emp) {
            DB::table('t_vat_management_logs')->insert([
                'client_id' => $d['client_id'],
                'employee_id' => $emp,
                'declared_month' => $d['month'],
                'trans_type' => $d['trans_type'],
                'actual_net_vat_payable' => round((float) $d['actual'], 2),
                'adjusted_net_vat_payable' => round((float) $d['adjusted'], 2),
                'amount_to_adjust' => round((float) $d['amount'], 2),
                'remarks' => trim((string) ($d['remarks'] ?? '')),
            ]);
            // a new adjustment puts the client back in review (Pending)
            DB::table('t_vat_management_status')->updateOrInsert(
                ['client_id' => $d['client_id'], 'declared_month' => $d['month']],
                ['status' => 'Pending', 'employee_id' => $emp, 'timestamp' => DB::raw('CURRENT_TIMESTAMP')]
            );
        });
        return response()->json(['ok' => true], 201);
    }

    /* POST /vat-management/status { client_id, month, status: Resolved | Declined | Pending } */
    public function setStatus(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'month'     => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'status'    => ['required', 'in:' . implode(',', self::STATUSES)],
        ]);
        abort_unless($this->isAuditor($request), 403, 'Only an auditor can resolve or decline.');
        DB::table('t_vat_management_status')->updateOrInsert(
            ['client_id' => $d['client_id'], 'declared_month' => $d['month']],
            ['status' => $d['status'], 'employee_id' => $this->employeeId($request), 'timestamp' => DB::raw('CURRENT_TIMESTAMP')]
        );
        return response()->json(['ok' => true, 'status' => $d['status']]);
    }
}