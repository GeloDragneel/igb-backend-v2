<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring → Audit Monitoring → aging (same look as the "Your pending work" popup)
 *
 *   GET /monitoring/audit/aging?auditor_id=0   { scope, clients, pending, audited, reverted }
 *
 * Who sees what
 *   Auditor                                     → only the clients assigned to them (m_client_account.assigned_auid = me)
 *   IT / CEO / Audit Supervisor / Supervisor   → every client (or one auditor's clients with ?auditor_id=)
 *
 * Age (days → 1-30 · 31-60 · 61-90 · 90+)
 *   pending  = invoices not audited yet (audit_status_id 0 / 1 / null), not VOID → from the date it was entered (created_at)
 *   audited  = invoices audited now (audit_status_id 2)   → from the date it was AUDITED  (t_audit_history, status 2)
 *   reverted = invoices reverted now (audit_status_id 3)  → from the date it was REVERTED (t_audit_history, status 3)
 */
class AuditAgingController extends MonitoringController
{
    // m_user_type ids that see every client: 1 IT DEVELOPMENT HEAD · 2 CEO · 4 SUPERVISOR · 10 AUDIT SUPERVISOR · 15 IT OFFICER
    const SEE_ALL  = [1, 2, 4, 10, 15];
    const INVOICES = ['t_sales_invoice_master', 't_purchase_expense_master'];
    const BUCKETS  = ['d30', 'd60', 'd90', 'd90p'];
    // m_audit_status: 1 PENDING · 2 AUDITED · 3 REVERTED (0 / null = not audited yet = Pending)
    const LABELS   = [0 => 'Pending', 1 => 'Pending', 2 => 'Audited', 3 => 'Reverted'];

    private function empId(Request $request): int
    {
        $me = $this->me($request);
        return (int) (is_object($me) ? ($me->id ?? 0) : $me);
    }

    /** 'all' | 'auditor' | null */
    private function role(int $emp): ?string
    {
        $e = DB::table('m_employee')->where('id', $emp)->first(['designation_id']);
        if (!$e) return null;
        if (in_array((int) $e->designation_id, self::SEE_ALL, true)) return 'all';
        if ((int) $e->designation_id === self::AUDITOR) return 'auditor';
        return null;
    }

    /** branch accounts assigned to an auditor */
    private function assigned(int $auditor): array
    {
        if (!in_array('assigned_auid', $this->cols('m_client_account'), true)) return [];
        return DB::table('m_client_account')->where('assigned_auid', $auditor)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function bucketSql(string $date): string
    {
        $d = "DATEDIFF(CURDATE(), DATE({$date}))";
        return "CASE WHEN {$d} <= 30 THEN 'd30' WHEN {$d} <= 60 THEN 'd60' WHEN {$d} <= 90 THEN 'd90' ELSE 'd90p' END";
    }

    private function inScope(?array $caids): string
    {
        return $caids === null ? '' : ' AND U.caid IN (' . implode(',', $caids ?: [0]) . ')';
    }

    /** per invoice table: FROM … + WHERE + the date the age counts from (pending = entered · audited / reverted = last audit date) */
    private function source(string $t, string $kind, ?array $caids, ?array $range): array
    {
        $notVoid = 'COALESCE(A.invoice_status_id, 0) <> ' . self::VOID . $this->inScope($caids);
        if ($kind === 'pending') {
            $from = "FROM {$t} A INNER JOIN m_user_client U ON U.id = A.added_by";
            $date = 'A.created_at';
            $where = "COALESCE(A.audit_status_id, 0) IN (0, 1) AND {$notVoid}";
        } else {
            $st = $kind === 'audited' ? 2 : 3;
            $from = "FROM {$t} A INNER JOIN m_user_client U ON U.id = A.added_by
                     INNER JOIN (SELECT reference_no, MAX(created_at) AS at FROM t_audit_history
                                 WHERE audit_status_id = {$st} GROUP BY reference_no) H ON H.reference_no = A.invoice_no";
            $date = 'H.at';
            $where = "A.audit_status_id = {$st} AND {$notVoid}";
        }
        if ($range) $where .= " AND {$date} >= '{$range[0]}' AND {$date} < '{$range[1]}'";
        return [$from, $where, $date];
    }

    private function tables(): array
    {
        return array_values(array_filter(self::INVOICES, fn ($t) => in_array('audit_status_id', $this->cols($t), true)));
    }

    /** $kind: pending | audited | reverted → rows of (caid, b, n) */
    private function counts(?array $caids, string $kind, ?array $range): array
    {
        $parts = [];
        foreach ($this->tables() as $t) {
            [$from, $where, $date] = $this->source($t, $kind, $caids, $range);
            $b = $this->bucketSql($date);
            $ty = $this->typeSql($t);
            $parts[] = "SELECT U.caid, {$b} AS b, {$ty} AS ty, COUNT(*) AS n {$from} WHERE {$where} GROUP BY U.caid, b, ty";
        }
        if (!$parts) return [];
        return DB::select('SELECT caid, b, ty, SUM(n) AS n FROM (' . implode(' UNION ALL ', $parts) . ') X GROUP BY caid, b, ty');
    }

    /** sales | purchases | expenses of an invoice table */
    private function typeSql(string $t): string
    {
        return $t === 't_sales_invoice_master' ? "'sales'" : 'LOWER(TRIM(A.trans_type))';
    }

    /** auditor → own clients · supervisor → all, or one auditor (?auditor_id=) · [role, caids, pick] */
    private function scopeFor(Request $request): array
    {
        $emp  = $this->empId($request);
        $role = $emp ? $this->role($emp) : null;
        abort_unless($role, 403, 'Only auditors and supervisors can see the audit aging.');
        $pick  = (int) $request->query('auditor_id', 0);
        $caids = $role === 'auditor' ? $this->assigned($emp) : ($pick ? $this->assigned($pick) : null);
        return [$role, $caids, $pick];
    }

    private function yearOf(Request $request): ?int
    {
        $y = (int) $request->query('year', 0);
        return $y >= 2000 && $y <= 2100 ? $y : null;
    }

    /** ?year=&month= → [from, to) · month only counts with a year · nothing → all dates */
    private function rangeOf(Request $request): ?array
    {
        $y = $this->yearOf($request);
        if (!$y) return null;
        $m = (int) $request->query('month', 0);
        if ($m < 1 || $m > 12) return ["{$y}-01-01", ($y + 1) . '-01-01'];
        $from = sprintf('%04d-%02d-01', $y, $m);
        return [$from, date('Y-m-d', strtotime("{$from} +1 month"))];
    }

    /** the tab counts of the year: $kind → [1..12 => n] */
    private function monthCounts(?array $caids, string $kind, int $year): array
    {
        $parts = [];
        foreach ($this->tables() as $t) {
            [$from, $where, $date] = $this->source($t, $kind, $caids, ["{$year}-01-01", ($year + 1) . '-01-01']);
            $parts[] = "SELECT MONTH({$date}) AS m, COUNT(*) AS n {$from} WHERE {$where} GROUP BY m";
        }
        $out = array_fill(1, 12, 0);
        if (!$parts) return $out;
        foreach (DB::select('SELECT m, SUM(n) AS n FROM (' . implode(' UNION ALL ', $parts) . ') X GROUP BY m') as $r) $out[(int) $r->m] = (int) $r->n;
        return $out;
    }

    /** rows of (caid, b, n) → { totals, clients[] } */
    private function aged(array $rows, array $names): array
    {
        $tot = array_fill_keys(self::BUCKETS, 0) + ['total' => 0];
        $per = [];
        foreach ($rows as $r) {
            $c = (int) $r->caid;
            $per[$c] ??= ['caid' => $c] + array_fill_keys(self::BUCKETS, 0) + ['sales' => 0, 'purchases' => 0, 'expenses' => 0, 'total' => 0];
            $per[$c][$r->b] += (int) $r->n;
            if (isset($per[$c][$r->ty]) && in_array($r->ty, ['sales', 'purchases', 'expenses'], true)) $per[$c][$r->ty] += (int) $r->n;
            $per[$c]['total'] += (int) $r->n;
            $tot[$r->b] += (int) $r->n;
            $tot['total'] += (int) $r->n;
        }
        $clients = array_map(fn ($c) => $c + ($names[$c['caid']] ?? ['branch_code' => '', 'trade_name' => '']), array_values($per));
        usort($clients, fn ($a, $b) => $b['total'] <=> $a['total']);
        return ['totals' => $tot, 'clients' => $clients];
    }

    /* ---------------- GET /monitoring/audit/aging?year=2026&month=11&auditor_id=0   (month empty = whole year) ---------------- */
    public function aging(Request $request)
    {
        [$role, $caids, $pick] = $this->scopeFor($request);
        $year = $this->yearOf($request);
        $range = $this->rangeOf($request);

        $sets = [];
        foreach (['pending', 'audited', 'reverted'] as $k) $sets[$k] = $this->counts($caids, $k, $range);

        $ids = [];
        foreach ($sets as $rows) foreach ($rows as $r) $ids[(int) $r->caid] = true;
        $names = DB::table('m_client_account')->whereIn('id', array_keys($ids) ?: [0])
            ->get(['id', 'branch_code', 'trade_name'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => ['branch_code' => (string) $a->branch_code, 'trade_name' => (string) $a->trade_name]])
            ->all();

        $out = ['scope' => $role === 'auditor' ? 'mine' : ($pick ? 'auditor' : 'all'), 'clients' => $caids === null ? null : count($caids), 'year' => $year];
        foreach ($sets as $k => $rows) $out[$k] = $this->aged($rows, $names);
        $out['month'] = (int) $request->query('month', 0) ?: null;
        return response()->json($out);
    }

    /* ---------------- GET /monitoring/audit/aging/list?kind=pending&bucket=d30&caid=12&year=2026&month=11 ----------------
       the invoices behind a number (sales / purchases / expenses), oldest first, max 2000 */
    public function agingList(Request $request)
    {
        [, $caids] = $this->scopeFor($request);
        $range  = $this->rangeOf($request);
        $kind   = in_array($request->query('kind'), ['pending', 'audited', 'reverted'], true) ? $request->query('kind') : 'pending';
        $bucket = in_array($request->query('bucket'), self::BUCKETS, true) ? $request->query('bucket') : null;
        $caid   = (int) $request->query('caid', 0);
        if ($caid) {
            abort_if($caids !== null && !in_array($caid, $caids, true), 403, 'This client is not assigned to you.');
            $caids = [$caid];
        }

        $parts = [];
        foreach ($this->tables() as $t) {
            [$from, $where, $date] = $this->source($t, $kind, $caids, $range);
            $days = "DATEDIFF(CURDATE(), DATE({$date}))";
            if ($bucket) $where .= " AND {$this->bucketSql($date)} = '{$bucket}'";
            $type = $this->typeSql($t);
            $parts[] = "SELECT U.caid, A.invoice_no, A.document_no, A.declared_month, {$type} AS type,
                               COALESCE(A.audit_status_id, 0) AS st, A.grand_total AS amount,
                               DATE({$date}) AS since, {$days} AS days
                        {$from} WHERE {$where}";
        }
        if (!$parts) return response()->json(['rows' => [], 'more' => false]);

        $rows = DB::select('SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') X ORDER BY days DESC, invoice_no LIMIT 2001');
        $more = count($rows) > 2000;
        $rows = array_slice($rows, 0, 2000);

        $names = DB::table('m_client_account')->whereIn('id', array_unique(array_map(fn ($r) => (int) $r->caid, $rows)) ?: [0])
            ->get(['id', 'branch_code', 'trade_name'])->keyBy('id');

        // audited / reverted: the latest audit history of that status → remarks, date, who did it
        $remarks = [];
        if ($kind !== 'pending' && $rows) {
            $st = $kind === 'audited' ? 2 : 3;
            $name = $this->employeeName('E');
            foreach (array_chunk(array_values(array_unique(array_map(fn ($r) => (string) $r->invoice_no, $rows))), 1000) as $chunk) {
                foreach (DB::table('t_audit_history as H')->leftJoin('m_employee as E', 'E.id', '=', 'H.auditor_id')
                             ->whereIn('H.reference_no', $chunk)->where('H.audit_status_id', $st)
                             ->orderByDesc('H.id')->get(['H.reference_no', 'H.remarks', 'H.created_at', DB::raw("{$name} AS by_name")]) as $h) {
                    $remarks[$h->reference_no] ??= [
                        'text' => trim((string) $h->remarks),
                        'at'   => $h->created_at ? date('M d Y', strtotime($h->created_at)) : '',
                        'by'   => trim((string) $h->by_name),
                    ];
                }
            }
        }

        return response()->json(['more' => $more, 'rows' => array_map(function ($r) use ($names, $remarks) {
            $a = $names[(int) $r->caid] ?? null;
            return [
                'caid'           => (int) $r->caid,
                'trade_name'     => $a ? trim("{$a->branch_code} : {$a->trade_name}", ' :') : '',
                'invoice_no'     => $r->invoice_no,
                'document_no'    => $r->document_no,
                'declared_month' => $r->declared_month,
                'type'           => $r->type === 'sales' ? 'sales' : ($r->type === 'expenses' ? 'expenses' : 'purchases'),
                'status'         => self::LABELS[(int) $r->st] ?? 'Pending',
                'amount'         => (float) $r->amount,
                'since'          => $r->since,
                'days'           => (int) $r->days,
                'remarks'        => $remarks[$r->invoice_no]['text'] ?? '',
                'remarks_at'     => $remarks[$r->invoice_no]['at'] ?? '',   // when it was audited / reverted with those remarks
                'remarks_by'     => $remarks[$r->invoice_no]['by'] ?? '',   // who audited / reverted it
            ];
        }, $rows)]);
    }
}