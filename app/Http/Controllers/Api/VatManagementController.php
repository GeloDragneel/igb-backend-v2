<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring → VAT Management Dashboard
 *   t_vat_management_logs   : every adjustment saved from the Manage popup
 *   t_vat_management_status : one status per client + declared month (Pending by default · Resolved · Declined)
 *   t_vat_management_log_reads : read / unread – a log is unread for you until you open that client's Manage popup
 *                                (your own logs are never unread)
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

    /** the employee behind this login (same lookup as the other modules):
     *  employee_id / emp_id / employeeid → m_user_staff.comid = m_employee.com_id → the user id itself – the first one found in m_employee */
    private ?int $me = null;
    private function employeeId(Request $request): int
    {
        if ($this->me !== null) return $this->me;
        $u = $request->user();
        if (!$u) return $this->me = 0;
        $ids = [];
        foreach (['employee_id', 'emp_id', 'employeeid'] as $k) {
            $v = $u->getAttribute($k);
            if (is_numeric($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        if (!empty($u->comid)) {
            $byCom = (int) DB::table('m_employee')->where('com_id', $u->comid)->value('id');
            if ($byCom > 0) $ids[] = $byCom;
        }
        $ids[] = (int) $u->id;
        foreach (array_unique($ids) as $id) {
            if ($id > 0 && DB::table('m_employee')->where('id', $id)->exists()) return $this->me = $id;
        }
        return $this->me = 0;
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
        $stRows = DB::table('t_vat_management_status')->where('declared_month', $month)
            ->get(['client_id', 'status', 'final_log_id', 'final_trans_type', 'final_actual_net_vat_payable', 'final_adjusted_net_vat_payable', 'final_amount_to_adjust'])
            ->keyBy('client_id');
        $status = $stRows->map(fn ($r) => $r->status);
        $logs = DB::table('t_vat_management_logs')->where('declared_month', $month)
            ->groupBy('client_id')->selectRaw('client_id, COUNT(*) AS n')->pluck('n', 'client_id');
        $unread = $this->unreadQuery($request)->where('L.declared_month', $month)
            ->groupBy('L.client_id')->selectRaw('L.client_id, COUNT(*) AS n')->pluck('n', 'client_id');

        $out = [];
        foreach ($logs as $cid => $n) $out[$cid] = ['status' => $status[$cid] ?? 'Pending', 'logs' => (int) $n, 'unread' => (int) ($unread[$cid] ?? 0)];
        foreach ($status as $cid => $st) $out[$cid] ??= ['status' => $st, 'logs' => 0, 'unread' => 0];
        // Resolved → the finalized amounts (shown in the table instead of the zero-out)
        foreach ($stRows as $cid => $r) {
            if ($r->status === 'Resolved' && $r->final_log_id) $out[$cid]['final'] = $this->finalOf($r);
        }
        return response()->json(['clients' => (object) $out]);
    }

    private function finalOf($r): array
    {
        return [
            'log_id' => (int) $r->final_log_id,
            'trans_type' => $r->final_trans_type,
            'actual' => (float) $r->final_actual_net_vat_payable,
            'adjusted' => (float) $r->final_adjusted_net_vat_payable,
            'amount' => (float) $r->final_amount_to_adjust,
        ];
    }

    /** logs written by someone else that this employee hasn't opened yet */
    private function unreadQuery(Request $request)
    {
        $emp = $this->employeeId($request);
        return DB::table('t_vat_management_logs as L')
            ->when($emp <= 0, fn ($q) => $q->whereRaw('1 = 0'))
            ->where('L.employee_id', '<>', $emp)
            ->whereNotExists(fn ($q) => $q->from('t_vat_management_log_reads as R')
                ->whereColumn('R.log_id', 'L.id')->where('R.employee_id', $emp));
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
            ->first(['S.status', 'S.timestamp', 'S.final_log_id', 'S.final_trans_type', 'S.final_actual_net_vat_payable',
                'S.final_adjusted_net_vat_payable', 'S.final_amount_to_adjust', DB::raw("NULLIF(TRIM(CONCAT_WS(', ', E.last_name, E.first_name)), '') AS employee")]);

        $rows = DB::table('t_vat_management_logs as L')
            ->leftJoin('m_employee as E', 'E.id', '=', 'L.employee_id')
            ->where('L.client_id', $cid)->where('L.declared_month', $month)
            ->orderByDesc('L.timestamp')->orderByDesc('L.id')
            ->get(['L.id', 'L.client_id', 'L.employee_id', 'L.declared_month', 'L.trans_type', 'L.actual_net_vat_payable',
                'L.adjusted_net_vat_payable', 'L.amount_to_adjust', 'L.remarks', 'L.timestamp',
                DB::raw("NULLIF(TRIM(CONCAT_WS(', ', E.last_name, E.first_name)), '') AS employee")]);

        // read / unread: flag what was new for me, then mark it read (opening the popup = reading it)
        $emp = $this->employeeId($request);
        $new = $this->unreadQuery($request)->where('L.client_id', $cid)->where('L.declared_month', $month)->pluck('L.id')->all();
        foreach ($rows as $r) $r->is_read = !in_array($r->id, $new);
        if ($emp > 0 && $new) {
            DB::table('t_vat_management_log_reads')->insertOrIgnore(array_map(fn ($id) => ['log_id' => $id, 'employee_id' => $emp], $new));
        }

        return response()->json([
            'status' => $st ? ['status' => $st->status, 'timestamp' => (string) $st->timestamp, 'employee' => $st->employee,
                'final' => $st->status === 'Resolved' && $st->final_log_id ? $this->finalOf($st) : null] : ['status' => 'Pending'],
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
            'log_id'    => ['nullable', 'integer', 'min:1'],     // Resolve: the log whose amounts become final
        ]);
        abort_unless($this->isAuditor($request), 403, 'Only an auditor can resolve or decline.');

        $final = ['final_log_id' => null, 'final_trans_type' => null, 'final_actual_net_vat_payable' => null,
            'final_adjusted_net_vat_payable' => null, 'final_amount_to_adjust' => null];
        if ($d['status'] === 'Resolved') {
            $log = DB::table('t_vat_management_logs')->where('id', (int) ($d['log_id'] ?? 0))
                ->where('client_id', $d['client_id'])->where('declared_month', $d['month'])->first();
            abort_unless($log, 422, 'Pick the adjustment to finalize.');
            $final = ['final_log_id' => $log->id, 'final_trans_type' => $log->trans_type,
                'final_actual_net_vat_payable' => $log->actual_net_vat_payable,
                'final_adjusted_net_vat_payable' => $log->adjusted_net_vat_payable,
                'final_amount_to_adjust' => $log->amount_to_adjust];
        }
        DB::table('t_vat_management_status')->updateOrInsert(
            ['client_id' => $d['client_id'], 'declared_month' => $d['month']],
            ['status' => $d['status'], 'employee_id' => $this->employeeId($request), 'timestamp' => DB::raw('CURRENT_TIMESTAMP')] + $final
        );
        return response()->json(['ok' => true, 'status' => $d['status']]);
    }
}