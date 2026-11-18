<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Popup after login (like the birthday popup): the employee's open work, aged
 *
 *   GET  /work-reminder           { show, role, scans, for_audit, reverted }
 *   POST /work-reminder/confirm   hide it for the rest of today (any device)
 *
 * Data analyst → clients with m_client_account.assigned_daid = me : pending scans + reverted
 * Auditor      → clients with m_client_account.assigned_auid = me : pending scans + for audit + reverted
 * Audit Supervisor / IT / CEO (EXEMPT, by designation name) → every client, all three
 *
 * pending scans = t_mobile_transaction.trans_status 0
 * for audit     = invoices with audit_status_id 0 / 1 / null (not yet audited), not VOID
 * reverted      = invoices with audit_status_id 3, not VOID
 * age           = days since created_at → 1-30 · 31-60 · 61-90 · 90+
 */
class WorkReminderController extends MonitoringController
{
    // designation names (m_user_type) that see every client – change to match your list
    const EXEMPT = ['AUDIT SUPERVISOR', 'IT', 'IT OFFICER', 'CEO', 'CHIEF EXECUTIVE OFFICER'];
    const INVOICES = ['t_sales_invoice_master', 't_purchase_expense_master'];
    const BUCKETS = ['d30', 'd60', 'd90', 'd90p'];

    private function empId(Request $request): int
    {
        $me = $this->me($request);
        return (int) (is_object($me) ? ($me->id ?? 0) : $me);
    }

    /** 'da' | 'auditor' | 'all' | null */
    private function role(int $emp): ?string
    {
        $e = DB::table('m_employee')->where('id', $emp)->first(['designation_id']);
        if (!$e) return null;
        $name = '';
        if (Schema::hasTable('m_user_type') && ($col = $this->pick('m_user_type', ['user_type', 'name', 'type_name', 'description']))) {
            $name = strtoupper(trim((string) DB::table('m_user_type')->where('id', $e->designation_id)->value($col)));
        }
        if (in_array($name, self::EXEMPT, true)) return 'all';
        if ((int) $e->designation_id === self::AUDITOR) return 'auditor';
        if ((int) $e->designation_id === self::DATA_ANALYST) return 'da';
        return null;
    }

    private function ackKey(int $emp): string
    {
        return "work-reminder-ack:{$emp}:" . now()->format('Y-m-d');
    }

    /** branch accounts in scope: null = every client */
    private function scope(string $role, int $emp): ?array
    {
        if ($role === 'all') return null;
        $col = $role === 'auditor' ? 'assigned_auid' : 'assigned_daid';
        if (!in_array($col, $this->cols('m_client_account'), true)) return [];
        return DB::table('m_client_account')->where($col, $emp)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function bucketSql(string $date): string
    {
        $d = "DATEDIFF(CURDATE(), DATE({$date}))";
        return "CASE WHEN {$d} <= 30 THEN 'd30' WHEN {$d} <= 60 THEN 'd60' WHEN {$d} <= 90 THEN 'd90' ELSE 'd90p' END";
    }

    /** rows of (caid, b, n) → { totals, clients[] } */
    private function aged(array $rows, array $names): array
    {
        $tot = array_fill_keys(self::BUCKETS, 0) + ['total' => 0];
        $per = [];
        foreach ($rows as $r) {
            $c = (int) $r->caid;
            $per[$c] ??= ['caid' => $c] + array_fill_keys(self::BUCKETS, 0) + ['total' => 0];
            $per[$c][$r->b] += (int) $r->n;
            $per[$c]['total'] += (int) $r->n;
            $tot[$r->b] += (int) $r->n;
            $tot['total'] += (int) $r->n;
        }
        $clients = array_map(fn ($c) => $c + ($names[$c['caid']] ?? ['branch_code' => '', 'trade_name' => '']), array_values($per));
        usort($clients, fn ($a, $b) => $b['total'] <=> $a['total']);
        return ['totals' => $tot, 'clients' => $clients];
    }

    private function inScope(?array $caids, string $col = 'U.caid'): string
    {
        return $caids === null ? '' : " AND {$col} IN (" . implode(',', $caids ?: [0]) . ')';
    }

    private function scans(?array $caids): array
    {
        if (!Schema::hasTable('t_mobile_transaction')) return [];
        $b = $this->bucketSql('A.created_at');
        return DB::select("
            SELECT U.caid, {$b} AS b, COUNT(*) AS n
            FROM t_mobile_transaction A
            INNER JOIN m_user_client U ON U.id = A.added_by
            WHERE COALESCE(A.trans_status, 0) = 0" . $this->inScope($caids) . "
            GROUP BY U.caid, b");
    }

    /** $status: 'audit' (0 / 1 / null) or 'reverted' (3) */
    private function invoices(?array $caids, string $status): array
    {
        $b = $this->bucketSql('A.created_at');
        $where = $status === 'reverted' ? 'A.audit_status_id = 3' : 'COALESCE(A.audit_status_id, 0) IN (0, 1)';
        $parts = [];
        foreach (self::INVOICES as $t) {
            if (!in_array('audit_status_id', $this->cols($t), true)) continue;
            $parts[] = "SELECT U.caid, {$b} AS b, COUNT(*) AS n
                        FROM {$t} A
                        INNER JOIN m_user_client U ON U.id = A.added_by
                        WHERE {$where} AND COALESCE(A.invoice_status_id, 0) <> " . self::VOID . $this->inScope($caids) . "
                        GROUP BY U.caid, b";
        }
        if (!$parts) return [];
        return DB::select('SELECT caid, b, SUM(n) AS n FROM (' . implode(' UNION ALL ', $parts) . ') X GROUP BY caid, b');
    }

    /* ---------------- GET /work-reminder ---------------- */
    public function index(Request $request)
    {
        $emp = $this->empId($request);
        $role = $emp ? $this->role($emp) : null;
        if (!$role) return response()->json(['show' => false]);
        if (Cache::has($this->ackKey($emp))) return response()->json(['show' => false, 'confirmed' => true]);

        $caids = $this->scope($role, $emp);
        $sets = [
            'scans' => $this->scans($caids),
            'reverted' => $this->invoices($caids, 'reverted'),
        ];
        if ($role !== 'da') $sets['for_audit'] = $this->invoices($caids, 'audit');

        // client names for the accounts that have something
        $ids = [];
        foreach ($sets as $rows) foreach ($rows as $r) $ids[(int) $r->caid] = true;
        $names = DB::table('m_client_account')->whereIn('id', array_keys($ids) ?: [0])
            ->get(['id', 'branch_code', 'trade_name'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => ['branch_code' => (string) $a->branch_code, 'trade_name' => (string) $a->trade_name]])
            ->all();

        $out = ['show' => false, 'role' => $role, 'clients' => $caids === null ? null : count($caids)];
        foreach ($sets as $k => $rows) {
            $out[$k] = $this->aged($rows, $names);
            if ($out[$k]['totals']['total'] > 0) $out['show'] = true;
        }
        return response()->json($out);
    }

    /* ---------------- POST /work-reminder/confirm ---------------- */
    public function confirm(Request $request)
    {
        $emp = $this->empId($request);
        if ($emp) Cache::put($this->ackKey($emp), true, now()->endOfDay());
        return response()->json(['message' => 'Okay, see you tomorrow.']);
    }
}