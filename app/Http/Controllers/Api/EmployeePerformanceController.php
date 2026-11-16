<?php

namespace App\Http\Controllers\Api;

use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Monitoring → Employee Performance (replaces the Reverted / Efficiency grid)
 *
 *   GET /monitoring/performance/lookups                      active employees (photo, designation, company) + designations
 *   GET /monitoring/performance?from=&to=&designation_id=    every DA / auditor (and anybody with work) for the period
 *   GET /monitoring/performance/{id}?from=&to=&caid=         one employee: profile, assigned clients, charts, reverted list
 *                                                            caid = one branch account (Client dropdown) → only its invoices
 *
 * from / to = Y-m-d (Day: from = to → hourly chart · Week / Month: daily chart)
 *
 * Data analyst  (m_*_master.assigned_daid): encoded = invoices created in the period,
 *               reverted = audit_status_id 3 · efficiency = (encoded − reverted) ÷ encoded
 * Auditor       (m_*_master.assigned_auid): for audit = invoices created in the period,
 *               done = audited (2) + reverted (3) · completion = done ÷ for audit
 *               actions = t_audit_history rows by the auditor in the period
 * Assigned clients: m_client_account.assigned_daid / assigned_auid
 * Employees: active + Regular only (m_employment_status.name = 'Regular')
 *
 * Per assigned client (and the totals of the tiles):
 *   scans  = t_mobile_transaction of the client's logins, created in the period, by trans_status
 *            0 Pending · 1 Confirmed · 2 Invalid · 3 Partially Invalid · 4 For collection / payment · 5 Cancel
 *   manual = sales / purchases / expenses typed in (is_scanned 0 / null, not VOID), created in the period
 *
 * Work time (efficiency): every entry of the employee in the period
 *   data analyst = invoices with assigned_daid · auditor = t_audit_history rows
 *   per day: first entry → last entry, minus the 2-hour break (only when the day is longer than 2 hours)
 *   entries per hour = entries ÷ worked hours · minutes per entry = worked minutes ÷ entries
 */
class EmployeePerformanceController extends MonitoringController
{
    const INVOICE_TABLES = ['t_sales_invoice_master', 't_purchase_expense_master'];
    const ST_PENDING = 0, ST_CONFIRMED = 1, ST_AUDITED = 2, ST_REVERTED = 3;
    const BREAK_MINUTES = 120;                                  // lunch / break, not counted as work
    const SCAN_KEYS = [0 => 'pending', 1 => 'confirmed', 2 => 'invalid', 3 => 'partial', 4 => 'billing', 5 => 'cancel'];

    /* ---------------- helpers ---------------- */

    private function period(Request $request): array
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = new DateTime($d['from']);
        $to = new DateTime($d['to']);
        if ($from->diff($to)->days > 366) abort(422, 'The period can be one year at most.');

        $hourly = $d['from'] === $d['to'];
        $buckets = [];
        if ($hourly) {
            for ($h = 0; $h < 24; $h++) $buckets[] = sprintf('%02d', $h);
        } else {
            for ($c = clone $from; $c <= $to; $c->modify('+1 day')) $buckets[] = $c->format('Y-m-d');
        }
        return [
            'from' => $d['from'], 'to' => $d['to'], 'hourly' => $hourly, 'buckets' => $buckets,
            'range' => ["{$d['from']} 00:00:00", "{$d['to']} 23:59:59"],
        ];
    }

    private function bucketSql(bool $hourly, string $a = 'A'): string
    {
        return $hourly ? "LPAD(HOUR({$a}.created_at), 2, '0')" : "DATE({$a}.created_at)";
    }

    private function photo(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') return null;
        if (preg_match('/^https?:\/\//i', $key)) return $key;
        try {
            return Storage::disk('s3')->temporaryUrl($key, now()->addHours(2));
        } catch (\Throwable) {
            return null;
        }
    }

    private function nameCol(string $table, array $names): ?string
    {
        return Schema::hasTable($table) ? $this->pick($table, $names) : null;
    }

    /** active employees: id → { id, name, designation_id, designation, photo, company } */
    private function employees(?int $only = null): array
    {
        $active = $this->pick('m_employee', ['is_active', 'active']);
        $photo = $this->pick('m_employee', ['photo_path']);
        $dn = $this->nameCol('m_user_type', ['user_type', 'name', 'type_name', 'description']);

        $q = DB::table('m_employee as E')
            ->leftJoin('m_client_account as CO', 'CO.id', '=', 'E.company_caid')
            ->when($dn, fn ($q) => $q->leftJoin('m_user_type as T', 'T.id', '=', 'E.designation_id'))
            ->when($only, fn ($q) => $q->where('E.id', $only), function ($q) use ($active) {
                $q->when($active, fn ($q) => $q->where("E.{$active}", 1));
                // Regular only (same as POS): m_employment_status.name = 'Regular'
                $col = $this->pick('m_employee', ['employment_stat_id', 'employment_status_id']);
                $name = $this->nameCol('m_employment_status', ['name', 'employment_status', 'status_name', 'description']);
                if ($col && $name) {
                    $ids = DB::table('m_employment_status')->whereRaw("UPPER(TRIM({$name})) = 'REGULAR'")->pluck('id')->all();
                    $q->whereIn("E.{$col}", $ids ?: [0]);
                }
            })
            ->orderBy('E.last_name')->orderBy('E.first_name')
            ->select([
                'E.id', 'E.designation_id', 'E.company_caid',
                DB::raw($this->employeeName() . ' AS name'),
                DB::raw($dn ? "T.{$dn} AS designation" : "'' AS designation"),
                DB::raw($photo ? "E.{$photo} AS photo" : 'NULL AS photo'),
                DB::raw("CO.trade_name AS company"),
            ]);

        $out = [];
        foreach ($q->get() as $e) {
            $out[(int) $e->id] = [
                'id' => (int) $e->id,
                'name' => trim((string) $e->name),
                'designation_id' => (int) $e->designation_id,
                'designation' => trim((string) $e->designation),
                'photo' => $this->photo($e->photo),
                'company' => (string) ($e->company ?? ''),
            ];
        }
        return $out;
    }

    /**
     * invoices per employee (assigned_daid or assigned_auid): emp → { st: { status: n }, series: { bucket: { status: n } } }
     */
    private function invoiceStats(string $col, array $p, ?int $emp = null, ?array $users = null): array
    {
        if ($users === []) return [];
        $u = $users ? ' AND A.added_by IN (' . implode(',', array_map('intval', $users)) . ')' : '';
        $b = $this->bucketSql($p['hourly']);
        $parts = [];
        $bind = [];
        foreach (self::INVOICE_TABLES as $t) {
            if (!in_array($col, $this->cols($t), true)) continue;
            $parts[] = "SELECT A.{$col} AS emp, COALESCE(A.audit_status_id, 0) AS st, {$b} AS b, COUNT(*) AS n
                        FROM {$t} A
                        WHERE A.created_at BETWEEN ? AND ? AND A.{$col} > 0" . ($emp ? " AND A.{$col} = ?" : '') . "{$u}
                        GROUP BY A.{$col}, COALESCE(A.audit_status_id, 0), {$b}";
            array_push($bind, ...$p['range'], ...($emp ? [$emp] : []));
        }
        if (!$parts) return [];

        $out = [];
        foreach (DB::select(implode(' UNION ALL ', $parts), $bind) as $r) {
            $e = (int) $r->emp;
            $st = (int) $r->st;
            $n = (int) $r->n;
            $out[$e]['st'][$st] = ($out[$e]['st'][$st] ?? 0) + $n;
            $out[$e]['series'][$r->b][$st] = ($out[$e]['series'][$r->b][$st] ?? 0) + $n;
        }
        return $out;
    }

    /** t_audit_history by auditor: emp → { st: {status: n}, series: {bucket: n} } */
    private function auditActions(array $p, ?int $emp = null, ?array $users = null): array
    {
        if (!Schema::hasTable('t_audit_history') || $users === []) return [];
        $b = $this->bucketSql($p['hourly']);
        $u = '';
        if ($users) {                                       // one client: audits of that client's invoices only
            $in = implode(',', array_map('intval', $users));
            $u = " AND A.reference_no IN (SELECT invoice_no FROM t_sales_invoice_master WHERE added_by IN ({$in})
                                          UNION SELECT invoice_no FROM t_purchase_expense_master WHERE added_by IN ({$in}))";
        }
        $rows = DB::select("
            SELECT A.auditor_id AS emp, COALESCE(A.audit_status_id, 0) AS st, {$b} AS b, COUNT(*) AS n
            FROM t_audit_history A
            WHERE A.created_at BETWEEN ? AND ? AND A.auditor_id > 0" . ($emp ? ' AND A.auditor_id = ?' : '') . "{$u}
            GROUP BY A.auditor_id, COALESCE(A.audit_status_id, 0), {$b}", [...$p['range'], ...($emp ? [$emp] : [])]);
        $out = [];
        foreach ($rows as $r) {
            $e = (int) $r->emp;
            $out[$e]['st'][(int) $r->st] = ($out[$e]['st'][(int) $r->st] ?? 0) + (int) $r->n;
            $out[$e]['series'][$r->b] = ($out[$e]['series'][$r->b] ?? 0) + (int) $r->n;
        }
        return $out;
    }

    /** assigned branch accounts per employee: emp → count */
    private function clientCounts(string $col): array
    {
        if (!in_array($col, $this->cols('m_client_account'), true)) return [];
        return DB::table('m_client_account')->where($col, '>', 0)->groupBy($col)
            ->selectRaw("{$col} AS emp, COUNT(*) AS n")->pluck('n', 'emp')->map(fn ($v) => (int) $v)->all();
    }

    private function daSummary(array $s, int $clients): array
    {
        $st = $s['st'] ?? [];
        $total = array_sum($st);
        $rev = $st[self::ST_REVERTED] ?? 0;
        return [
            'clients' => $clients, 'total' => $total,
            'pending' => $st[self::ST_PENDING] ?? 0, 'confirmed' => $st[self::ST_CONFIRMED] ?? 0,
            'audited' => $st[self::ST_AUDITED] ?? 0, 'reverted' => $rev,
            'efficiency' => $total ? round(($total - $rev) / $total * 100, 1) : null,
        ];
    }

    private function auSummary(array $s, array $actions, int $clients): array
    {
        $st = $s['st'] ?? [];
        $total = array_sum($st);
        $done = ($st[self::ST_AUDITED] ?? 0) + ($st[self::ST_REVERTED] ?? 0);
        return [
            'clients' => $clients, 'total' => $total,
            'audited' => $st[self::ST_AUDITED] ?? 0, 'reverted' => $st[self::ST_REVERTED] ?? 0,
            'pending' => $total - $done,
            'actions' => array_sum($actions['st'] ?? []),
            'completion' => $total ? round($done / $total * 100, 1) : null,
        ];
    }

    /* ---------------- GET /monitoring/performance/lookups ---------------- */
    public function lookups()
    {
        $emps = array_values($this->employees());
        $designations = collect($emps)->filter(fn ($e) => $e['designation_id'] > 0)
            ->map(fn ($e) => ['id' => $e['designation_id'], 'name' => $e['designation'] ?: "Designation {$e['designation_id']}"])
            ->unique('id')->sortBy('name')->values();

        return response()->json([
            'employees' => $emps,
            'designations' => $designations,
            'data_analyst' => self::DATA_ANALYST,
            'auditor' => self::AUDITOR,
        ]);
    }

    /* ---------------- GET /monitoring/performance ---------------- */
    public function index(Request $request)
    {
        $p = $this->period($request);
        $desig = (int) $request->query('designation_id', 0);

        $emps = $this->employees();
        $da = $this->invoiceStats('assigned_daid', $p);
        $au = $this->invoiceStats('assigned_auid', $p);
        $act = $this->auditActions($p);
        $daClients = $this->clientCounts('assigned_daid');
        $auClients = $this->clientCounts('assigned_auid');

        $rows = [];
        foreach ($emps as $id => $e) {
            if ($desig && $e['designation_id'] !== $desig) continue;
            $d = $this->daSummary($da[$id] ?? [], $daClients[$id] ?? 0);
            $a = $this->auSummary($au[$id] ?? [], $act[$id] ?? [], $auClients[$id] ?? 0);
            $isDa = $e['designation_id'] === self::DATA_ANALYST;
            $isAu = $e['designation_id'] === self::AUDITOR;
            // DA / auditor always listed · anybody else only when there is work or clients
            if (!$isDa && !$isAu && !$d['total'] && !$d['clients'] && !$a['total'] && !$a['actions'] && !$a['clients']) continue;
            $rows[] = $e + ['role' => $isAu ? 'auditor' : ($isDa ? 'da' : 'other'), 'da' => $d, 'au' => $a];
        }
        $ids = array_flip(array_column($rows, 'id'));

        // chart: per bucket, only the employees in the list
        $series = [];
        foreach ($p['buckets'] as $bk) $series[$bk] = ['b' => $bk, 'encoded' => 0, 'reverted' => 0, 'audits' => 0];
        foreach ($da as $id => $s) {
            if (!isset($ids[$id])) continue;
            foreach ($s['series'] ?? [] as $bk => $st) {
                if (!isset($series[$bk])) continue;
                $series[$bk]['encoded'] += array_sum($st);
                $series[$bk]['reverted'] += $st[self::ST_REVERTED] ?? 0;
            }
        }
        foreach ($act as $id => $s) {
            if (!isset($ids[$id])) continue;
            foreach ($s['series'] ?? [] as $bk => $n) if (isset($series[$bk])) $series[$bk]['audits'] += $n;
        }

        $sum = fn (string $role, string $k) => array_sum(array_map(fn ($r) => (int) ($r[$role][$k] ?? 0), $rows));
        $encoded = $sum('da', 'total');
        $reverted = $sum('da', 'reverted');
        $forAudit = $sum('au', 'total');
        $done = $sum('au', 'audited') + $sum('au', 'reverted');

        return response()->json([
            'from' => $p['from'], 'to' => $p['to'], 'hourly' => $p['hourly'],
            'totals' => [
                'employees' => count($rows),
                'encoded' => $encoded, 'reverted' => $reverted,
                'efficiency' => $encoded ? round(($encoded - $reverted) / $encoded * 100, 1) : null,
                'for_audit' => $forAudit, 'audit_done' => $done,
                'completion' => $forAudit ? round($done / $forAudit * 100, 1) : null,
                'actions' => $sum('au', 'actions'),
            ],
            'series' => array_values($series),
            'rows' => $rows,
        ]);
    }

    /* ---------------- GET /monitoring/performance/{id} ---------------- */
    public function show(Request $request, int $id)
    {
        $p = $this->period($request);
        $e = $this->employees($id)[$id] ?? null;
        if (!$e) return response()->json(['message' => 'Employee not found.'], 404);

        // Client dropdown: one branch account → its logins (m_user_client.caid)
        $caid = (int) $request->query('caid', 0);
        $users = $caid > 0 ? DB::table('m_user_client')->where('caid', $caid)->pluck('id')->map(fn ($v) => (int) $v)->all() : null;

        $da = $this->invoiceStats('assigned_daid', $p, $id, $users)[$id] ?? [];
        $au = $this->invoiceStats('assigned_auid', $p, $id, $users)[$id] ?? [];
        $act = $this->auditActions($p, $id, $users)[$id] ?? [];

        // always every assigned client (the dropdown and the list), numbers for the period
        $daClients = $this->withWork($this->assignedClients('assigned_daid', $id, $p), $p);
        $auClients = $this->withWork($this->assignedClients('assigned_auid', $id, $p), $p);

        $series = [];
        foreach ($p['buckets'] as $bk) {
            $d = $da['series'][$bk] ?? [];
            $a = $au['series'][$bk] ?? [];
            $series[] = [
                'b' => $bk,
                'encoded' => array_sum($d), 'reverted' => $d[self::ST_REVERTED] ?? 0,
                'au_total' => array_sum($a), 'audited' => $a[self::ST_AUDITED] ?? 0, 'au_reverted' => $a[self::ST_REVERTED] ?? 0,
                'actions' => $act['series'][$bk] ?? 0,
            ];
        }

        return response()->json([
            'from' => $p['from'], 'to' => $p['to'], 'hourly' => $p['hourly'], 'caid' => $caid ?: null,
            'employee' => $e + $this->profileExtra($id),
            'role' => $e['designation_id'] === self::AUDITOR ? 'auditor' : ($e['designation_id'] === self::DATA_ANALYST ? 'da' : 'other'),
            'da' => $this->daSummary($da, count($daClients)),
            'au' => $this->auSummary($au, $act, count($auClients)),
            'series' => $series,
            'da_clients' => $daClients,
            'au_clients' => $auClients,
            'reverted_da' => $this->revertedList('assigned_daid', $id, $p, $users),
            'reverted_au' => $this->revertedList('assigned_auid', $id, $p, $users),
            // tiles: all clients, or the one in the Client dropdown
            'da_totals' => $this->workTotals($daClients, $caid),
            'au_totals' => $this->workTotals($auClients, $caid),
            'work_da' => $this->workTime($this->daEntries($id, $p, $users)),
            'work_au' => $this->workTime($this->auEntries($id, $p, $users)),
            'break_minutes' => self::BREAK_MINUTES,
        ]);
    }

    /* ---------------- scans + manual entries per client ---------------- */

    /** add { scan: {total, pending, …, cancel}, manual: {sales, purchases, expenses} } to every client row */
    private function withWork(array $clients, array $p): array
    {
        if (!$clients) return [];
        $ids = array_column($clients, 'id');
        $in = implode(',', array_map('intval', $ids));
        $scan = [];
        $manual = [];

        if (Schema::hasTable('t_mobile_transaction')) {
            foreach (DB::select("
                SELECT U.caid, COALESCE(A.trans_status, 0) AS st, COUNT(*) AS n
                FROM t_mobile_transaction A
                INNER JOIN m_user_client U ON U.id = A.added_by
                WHERE U.caid IN ({$in}) AND A.created_at BETWEEN ? AND ?
                GROUP BY U.caid, COALESCE(A.trans_status, 0)", $p['range']) as $r) {
                $scan[(int) $r->caid][(int) $r->st] = (int) $r->n;
            }
        }

        // typed in by hand: not from a scan (is_scanned 0 / null), not VOID
        foreach (['t_sales_invoice_master' => "'sales'", 't_purchase_expense_master' => 'LOWER(TRIM(A.trans_type))'] as $t => $type) {
            $manualOnly = in_array('is_scanned', $this->cols($t), true) ? ' AND COALESCE(A.is_scanned, 0) = 0' : '';
            foreach (DB::select("
                SELECT U.caid, {$type} AS type, COUNT(*) AS n
                FROM {$t} A
                INNER JOIN m_user_client U ON U.id = A.added_by
                WHERE U.caid IN ({$in}) AND A.created_at BETWEEN ? AND ?
                  AND COALESCE(A.invoice_status_id, 0) <> " . self::VOID . "{$manualOnly}
                GROUP BY U.caid, {$type}", $p['range']) as $r) {
                $manual[(int) $r->caid][$r->type] = ($manual[(int) $r->caid][$r->type] ?? 0) + (int) $r->n;
            }
        }

        foreach ($clients as &$c) {
            $st = $scan[$c['id']] ?? [];
            $c['scan'] = ['total' => array_sum($st)];
            foreach (self::SCAN_KEYS as $k => $key) $c['scan'][$key] = $st[$k] ?? 0;
            $m = $manual[$c['id']] ?? [];
            $c['manual'] = ['sales' => $m['sales'] ?? 0, 'purchases' => $m['purchases'] ?? 0, 'expenses' => $m['expenses'] ?? 0];
        }
        return $clients;
    }

    private function workTotals(array $clients, int $caid): array
    {
        $out = ['scan' => ['total' => 0] + array_fill_keys(self::SCAN_KEYS, 0), 'manual' => ['sales' => 0, 'purchases' => 0, 'expenses' => 0]];
        foreach ($clients as $c) {
            if ($caid && $c['id'] !== $caid) continue;
            foreach ($out['scan'] as $k => $_) $out['scan'][$k] += $c['scan'][$k] ?? 0;
            foreach ($out['manual'] as $k => $_) $out['manual'][$k] += $c['manual'][$k] ?? 0;
        }
        return $out;
    }

    /* ---------------- work time (start → end − break) ---------------- */

    /** data analyst entries: every invoice with assigned_daid → [created_at, …] */
    private function daEntries(int $emp, array $p, ?array $users): array
    {
        if ($users === []) return [];
        $u = $users ? ' AND added_by IN (' . implode(',', array_map('intval', $users)) . ')' : '';
        $parts = [];
        $bind = [];
        foreach (self::INVOICE_TABLES as $t) {
            if (!in_array('assigned_daid', $this->cols($t), true)) continue;
            $parts[] = "SELECT created_at FROM {$t} WHERE assigned_daid = ? AND created_at BETWEEN ? AND ?{$u}";
            array_push($bind, ...[$emp, ...$p['range']]);
        }
        return $parts ? array_map(fn ($r) => (string) $r->created_at, DB::select(implode(' UNION ALL ', $parts), $bind)) : [];
    }

    /** auditor entries: every audit he saved → [created_at, …] */
    private function auEntries(int $emp, array $p, ?array $users): array
    {
        if (!Schema::hasTable('t_audit_history') || $users === []) return [];
        $u = '';
        if ($users) {
            $in = implode(',', array_map('intval', $users));
            $u = " AND reference_no IN (SELECT invoice_no FROM t_sales_invoice_master WHERE added_by IN ({$in})
                                        UNION SELECT invoice_no FROM t_purchase_expense_master WHERE added_by IN ({$in}))";
        }
        return array_map(fn ($r) => (string) $r->created_at, DB::select(
            "SELECT created_at FROM t_audit_history WHERE auditor_id = ? AND created_at BETWEEN ? AND ?{$u}", [$emp, ...$p['range']]));
    }

    /**
     * per day: start, end, span, break, worked, entries, per hour, minutes per entry
     * + totals + entries per hour of the day (with % of all entries)
     */
    private function workTime(array $times): array
    {
        $days = [];
        $hours = array_fill(0, 24, 0);
        foreach ($times as $t) {
            $ts = strtotime($t);
            if (!$ts) continue;
            $d = date('Y-m-d', $ts);
            $days[$d]['min'] = min($days[$d]['min'] ?? $ts, $ts);
            $days[$d]['max'] = max($days[$d]['max'] ?? $ts, $ts);
            $days[$d]['n'] = ($days[$d]['n'] ?? 0) + 1;
            $hours[(int) date('G', $ts)]++;
        }
        ksort($days);

        $rows = [];
        $worked = 0;
        $span = 0;
        $breaks = 0;
        foreach ($days as $d => $x) {
            $s = intdiv($x['max'] - $x['min'], 60);                    // minutes from the first to the last entry
            $b = $s > self::BREAK_MINUTES ? self::BREAK_MINUTES : 0;   // the 2-hour break only on a day longer than that
            $w = $s - $b;
            $rows[] = [
                'date' => $d, 'start' => date('H:i', $x['min']), 'end' => date('H:i', $x['max']),
                'span' => $s, 'break' => $b, 'worked' => $w, 'entries' => $x['n'],
                'per_hour' => $w > 0 ? round($x['n'] / ($w / 60), 1) : null,
                'min_per_entry' => $x['n'] > 0 && $w > 0 ? round($w / $x['n'], 1) : null,
            ];
            $worked += $w;
            $span += $s;
            $breaks += $b;
        }

        $total = count($times);
        $hourly = [];
        foreach ($hours as $h => $n) {
            $hourly[] = ['h' => sprintf('%02d', $h), 'entries' => $n, 'pct' => $total ? round($n / $total * 100, 1) : 0];
        }

        return [
            'days' => $rows,
            'hourly' => $hourly,
            'totals' => [
                'days' => count($rows), 'entries' => $total, 'span' => $span, 'break' => $breaks, 'worked' => $worked,
                'per_hour' => $worked > 0 ? round($total / ($worked / 60), 1) : null,
                'per_minute' => $worked > 0 ? round($total / $worked, 2) : null,
                'min_per_entry' => $total > 0 && $worked > 0 ? round($worked / $total, 1) : null,
                'avg_start' => $rows ? $this->avgClock(array_column($rows, 'start')) : null,
                'avg_end' => $rows ? $this->avgClock(array_column($rows, 'end')) : null,
            ],
        ];
    }

    private function avgClock(array $hhmm): string
    {
        $m = array_map(fn ($t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2), $hhmm);
        $a = (int) round(array_sum($m) / count($m));
        return sprintf('%02d:%02d', intdiv($a, 60), $a % 60);
    }

    /** email / mobile / employment status / date hired when those columns exist */
    private function profileExtra(int $id): array
    {
        $cols = [
            'email' => $this->pick('m_employee', ['email', 'email_address']),
            'mobile' => $this->pick('m_employee', ['mobile_no', 'contact_no', 'mobile', 'cellphone_no']),
            'date_hired' => $this->pick('m_employee', ['date_hired', 'hired_date', 'date_employed']),
        ];
        $esCol = $this->pick('m_employee', ['employment_stat_id', 'employment_status_id']);
        $esName = $esCol ? $this->nameCol('m_employment_status', ['name', 'employment_status', 'status_name', 'description']) : null;

        $sel = [];
        foreach ($cols as $k => $c) $sel[] = DB::raw($c ? "E.{$c} AS {$k}" : "NULL AS {$k}");
        $sel[] = DB::raw($esName ? "S.{$esName} AS employment_status" : 'NULL AS employment_status');

        $r = DB::table('m_employee as E')
            ->when($esName, fn ($q) => $q->leftJoin('m_employment_status as S', 'S.id', '=', "E.{$esCol}"))
            ->where('E.id', $id)->first($sel);

        return [
            'email' => (string) ($r->email ?? ''),
            'mobile' => (string) ($r->mobile ?? ''),
            'date_hired' => $r->date_hired ? substr((string) $r->date_hired, 0, 10) : '',
            'employment_status' => (string) ($r->employment_status ?? ''),
        ];
    }

    /** branch accounts assigned to the employee + their invoices in the period */
    private function assignedClients(string $col, int $emp, array $p): array
    {
        if (!in_array($col, $this->cols('m_client_account'), true)) return [];

        $sl = $this->nameCol('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']);
        $accounts = DB::table('m_client_account as B')
            ->leftJoin('m_client as C', 'C.client_num', '=', 'B.client_num')
            ->when($sl, fn ($q) => $q->leftJoin('m_client_account_status as S', 'S.id', '=', 'C.status_id'))
            ->where("B.{$col}", $emp)
            ->orderBy('B.trade_name')
            ->get([
                'B.id', 'B.branch_code', 'B.trade_name', 'B.client_num',
                DB::raw("TRIM(CONCAT_WS(' ', C.last_name, C.first_name)) AS client_name"),
                DB::raw($sl ? "S.{$sl} AS status" : "'' AS status"),
            ]);
        if ($accounts->isEmpty()) return [];

        // invoices of those accounts handled by the employee in the period: caid → status → n
        $counts = [];
        foreach (self::INVOICE_TABLES as $t) {
            if (!in_array($col, $this->cols($t), true)) continue;
            $rows = DB::table("{$t} as A")->join('m_user_client as U', 'U.id', '=', 'A.added_by')
                ->where("A.{$col}", $emp)->whereBetween('A.created_at', $p['range'])
                ->groupBy('U.caid', DB::raw('COALESCE(A.audit_status_id, 0)'))
                ->get([DB::raw('U.caid AS caid'), DB::raw('COALESCE(A.audit_status_id, 0) AS st'), DB::raw('COUNT(*) AS n')]);
            foreach ($rows as $r) $counts[(int) $r->caid][(int) $r->st] = ($counts[(int) $r->caid][(int) $r->st] ?? 0) + (int) $r->n;
        }

        return $accounts->map(function ($a) use ($counts) {
            $st = $counts[(int) $a->id] ?? [];
            $total = array_sum($st);
            $rev = $st[self::ST_REVERTED] ?? 0;
            return [
                'id' => (int) $a->id, 'branch_code' => (string) $a->branch_code, 'trade_name' => (string) $a->trade_name,
                'client_num' => (string) $a->client_num, 'client_name' => (string) $a->client_name, 'status' => (string) $a->status,
                'total' => $total, 'audited' => $st[self::ST_AUDITED] ?? 0, 'reverted' => $rev,
                'pending' => ($st[self::ST_PENDING] ?? 0) + ($st[self::ST_CONFIRMED] ?? 0),
                'efficiency' => $total ? round(($total - $rev) / $total * 100, 1) : null,
            ];
        })->values()->all();
    }

    /** reverted invoices in the period with the audit remarks (old doGetRevertedMonitoring_Detail, by range) */
    private function revertedList(string $col, int $emp, array $p, ?array $users = null): array
    {
        if ($users === []) return [];
        $u = $users ? ' AND A.added_by IN (' . implode(',', array_map('intval', $users)) . ')' : '';
        $parts = [];
        $bind = [];
        foreach (['t_sales_invoice_master' => "'Sales'", 't_purchase_expense_master' => 'A.trans_type'] as $t => $type) {
            if (!in_array($col, $this->cols($t), true)) continue;
            $parts[] = "
                SELECT A.invoice_no AS ref_no, MAX({$type}) AS type, A.created_at, A.declared_month, A.document_no,
                       MAX(E.branch_code) AS branch_code, MAX(E.trade_name) AS trade_name,
                       GROUP_CONCAT(F.remarks ORDER BY F.id SEPARATOR '~') AS remarks
                FROM {$t} A
                LEFT JOIN m_user_client D ON D.id = A.added_by
                LEFT JOIN m_client_account E ON E.id = D.caid
                LEFT JOIN t_audit_history F ON F.reference_no = A.invoice_no AND F.remarks IS NOT NULL AND F.remarks != ''
                WHERE A.audit_status_id = " . self::ST_REVERTED . " AND A.created_at BETWEEN ? AND ? AND A.{$col} = ?{$u}
                GROUP BY A.id, A.invoice_no, A.created_at, A.declared_month, A.document_no";
            array_push($bind, ...[...$p['range'], $emp]);
        }
        if (!$parts) return [];

        return collect(DB::select(implode(' UNION ALL ', $parts) . ' ORDER BY created_at DESC', $bind))->map(fn ($r) => [
            'date'           => substr((string) $r->created_at, 0, 10),
            'branch_code'    => $r->branch_code,
            'trade_name'     => $r->trade_name,
            'declared_month' => $r->declared_month,
            'ref_no'         => $r->ref_no,
            'document_no'    => $r->document_no,
            'type'           => ucfirst(strtolower((string) $r->type)),
            'remarks'        => array_values(array_filter(array_map('trim', explode('~', (string) $r->remarks)))),
        ])->all();
    }
}