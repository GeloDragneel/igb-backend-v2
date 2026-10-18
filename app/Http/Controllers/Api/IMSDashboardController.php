<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientAccount;
use App\Models\Payment;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IMS Dashboard — one month at a time (the same month as Billing); what you see depends on your user type (see ROLE DASHBOARDS below)
 *   GET /dashboard                         who is looking (is_ceo, employee_id, month)
 *   GET /dashboard/billing?month=YYYY-MM   Billing: billed, collected, deposited, outstanding, arrears (PF + EO) + charts (CEO only)
 *   GET /dashboard/billing/detail?month=&kpi=   the popup behind each Billing tile (CEO only)
 *   GET /dashboard/clients?month=          Client: scans by the client himself (t_mobile_transaction.employee_id 0 / null) by trans_type (CEO only)
 *   GET /dashboard/employees?month=        Employees: DA (assigned_daid) scans + sales / purchases / expenses · auditor audits (t_audit_history)
 *                                          (CEO: everybody · others: only himself)
 *
 * Uses the Monitoring helpers (forwarding balance, net amount, collection types …).
 */
class IMSDashboardController extends MonitoringController
{
    const CEO_USER_TYPE = 2;
    // m_user_type: IT OFFICER 1, CEO 2, SUPERVISOR 4, CFO 8, AUDIT SUPERVISOR 10 → Billing + Client + every employee + Calendar
    const FULL_ACCESS_USER_TYPES = [1, self::CEO_USER_TYPE, 4, 8, 10];
    const BILLING_USER_TYPES = [6, 7];    // LIAISON OFFICER · BILLING & COLLECTION OFFICER → Billing
    const DA_USER_TYPE = 3;               // DATA ANALYST
    const AUDITOR_USER_TYPE = 5;          // AUDITOR
    const SCAN_LABELS = [0 => 'Pending', 1 => 'Confirmed', 2 => 'Invalid', 3 => 'Partially Invalid', 4 => 'For Collection / Payment'];
    const TRANS_TYPES = ['sales', 'purchases', 'expenses'];
    const AUDITED = 2;   // audit_status_id on the invoice masters

    private function isCeo(Request $request): bool
    {
        return $this->myRole($request) === 'management';
    }

    /** the logged-in staff's employee: m_user_staff.employee_id, else m_employee by com_id = m_user_staff.comid (same as login) */
    private function myEmployeeId(Request $request): ?int
    {
        static $cache = [];
        $u = $request->user();
        if (!$u) return null;
        $key = get_class($u) . ':' . $u->id;
        if (array_key_exists($key, $cache)) return $cache[$key];

        $id = (int) ($u->employee_id ?? 0);
        if ($id <= 0 && !empty($u->comid)) {
            $id = (int) DB::table('m_employee')->where('com_id', $u->comid)->value('id');
        }
        return $cache[$key] = $id > 0 ? $id : null;
    }

    /** YYYY-MM → [first second, last second] */
    private function monthRange(string $month): array
    {
        $start = new DateTime($month . '-01');
        $end = (clone $start)->modify('last day of this month');
        return [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];
    }

    /** every day of the month → $value */
    private function monthDays(string $month, $value = 0): array
    {
        $days = [];
        for ($c = new DateTime($month . '-01'), $last = (clone $c)->modify('last day of this month'); $c <= $last; $c->modify('+1 day')) {
            $days[$c->format('Y-m-d')] = $value;
        }
        return $days;
    }

    /** [day => [k => v]] → [[day, k => v], …] for the charts */
    private function dailyList(array $days): array
    {
        return array_map(fn ($day, $v) => ['day' => $day] + $v, array_keys($days), $days);
    }

    /* GET /dashboard   who is looking + which tabs */
    public function dashboard(Request $request)
    {
        $role = $this->myRole($request);
        return response()->json([
            'is_ceo'       => $role === 'management',
            'role'         => $role,
            'tabs'         => match ($role) {
                'management' => ['billing', 'clients', 'employees', 'calendar'],
                'billing'    => ['billing'],
                'da'         => ['da'],
                'auditor'    => ['auditor'],
                default      => [],
            },
            'user_type_id' => $this->myUserType($request),
            'employee_id'  => $this->myEmployeeId($request),
            'month'        => date('Y-m'),
        ]);
    }

    public function dashboardBilling(Request $request)
    {
        abort_unless($this->canBilling($request), 403, 'For management and billing only.');
        $d = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $range = $this->monthRange($d['month']);

        // collection + deposit: payments dated this month, grouped by type and deposited
        $type = 'CASE';
        foreach (self::COLLECTION_TYPES as $t => $g) {
            $ids = implode(',', $this->collectionListIds($g['ser_types']));
            $type .= " WHEN S.ser_list_id IN ({$ids}) OR (S.id IS NULL AND P.payment_col_type_id = {$g['col_type']}) THEN '{$t}'";
        }
        $type .= " ELSE 'Others' END";
        $depCol = $this->pick('t_payment', ['is_deposited', 'deposited']);
        $dep = $depCol ? "CASE WHEN P.{$depCol} = 1 THEN 1 ELSE 0 END" : '0';

        $zero = ['PF' => 0.0, 'Return' => 0.0, 'EO' => 0.0, 'Others' => 0.0];
        $collection = ['total' => 0.0, 'count' => 0, 'by_type' => $zero];
        $deposit = ['deposited' => 0.0, 'deposited_count' => 0, 'undeposited' => 0.0, 'undeposited_count' => 0];
        $daily = [];   // day → type → amount
        for ($c = new DateTime($d['month'] . '-01'), $last = (clone $c)->modify('last day of this month'); $c <= $last; $c->modify('+1 day')) {
            $daily[$c->format('Y-m-d')] = $zero;
        }
        $rows = DB::table('t_payment as P')->leftJoin('t_service as S', 'S.id', '=', 'P.service_id')
            ->whereBetween('P.payment_date', $range)
            ->selectRaw("DATE(P.payment_date) AS day, {$type} AS type, {$dep} AS dep, COUNT(*) AS cnt, COALESCE(SUM(P.amount), 0) AS amt")
            ->groupByRaw('day, type, dep')->get();
        foreach ($rows as $r) {
            $amt = (float) $r->amt;
            $collection['total'] += $amt;
            $collection['count'] += (int) $r->cnt;
            $collection['by_type'][$r->type] += $amt;
            if (isset($daily[$r->day])) $daily[$r->day][$r->type] += $amt;
            $k = (int) $r->dep === 1 ? 'deposited' : 'undeposited';
            $deposit[$k] += $amt;
            $deposit["{$k}_count"] += (int) $r->cnt;
        }

        // last 6 months of collection (for the trend chart)
        $trend = [];
        $first = (new DateTime($d['month'] . '-01'))->modify('-5 months');
        for ($c = clone $first, $i = 0; $i < 6; $i++, $c->modify('+1 month')) $trend[$c->format('Y-m')] = $zero;
        foreach (DB::table('t_payment as P')->leftJoin('t_service as S', 'S.id', '=', 'P.service_id')
                     ->whereBetween('P.payment_date', [$first->format('Y-m-d 00:00:00'), $range[1]])
                     ->selectRaw("DATE_FORMAT(P.payment_date, '%Y-%m') AS ym, {$type} AS type, COALESCE(SUM(P.amount), 0) AS amt")
                     ->groupByRaw('ym, type')->get() as $r) {
            if (isset($trend[$r->ym])) $trend[$r->ym][$r->type] += (float) $r->amt;
        }
        // billed per month (PF + Return, monthly periods) for the same 6 months
        $billedTrend = array_fill_keys(array_keys($trend), 0.0);
        $periodYm = [];
        foreach (array_keys($trend) as $ym) $periodYm[strtoupper((new DateTime($ym . '-01'))->format('F Y'))] = $ym;
        $listIds = [...$this->rfListIds(self::RF_TYPES['PF']), ...$this->rfListIds(self::RF_TYPES['Return'])];
        foreach ($this->rfServices($listIds, array_keys($periodYm), ['id', 'service_period']) as $s) {
            $ym = $periodYm[strtoupper(trim((string) $s->service_period))] ?? null;
            if ($ym) $billedTrend[$ym] += $this->rfNet($s);
        }
        foreach ($trend as $ym => $v) $trend[$ym]['billed'] = $billedTrend[$ym];

        $series = fn (array $by, string $k) => array_map(fn ($key, $v) => [$k => $key] + array_map(fn ($x) => round($x, 2), $v), array_keys($by), $by);

        // PF / Return / EO billed for the month (EO: the year) + what each account still owes
        $monthName = strtoupper((new DateTime($d['month'] . '-01'))->format('F Y'));
        $eoYear = 'EO-' . substr($d['month'], 0, 4);
        $billing = [];
        $owed = [];   // ca_id → type → { forwarding, current }
        foreach (self::RF_TYPES as $t => $serType) {
            $eo = $t === 'EO';
            $period = $eo ? $eoYear : $monthName;
            $services = $this->rfServices($this->rfListIds($serType), [$period], ['id', 'ca_id', 'is_paid']);
            $paid = $this->rfPaid($services->pluck('id'));
            $b = ['period' => $period, 'count' => $services->count(), 'paid' => 0, 'unpaid' => 0, 'billed' => 0.0, 'collected' => 0.0, 'balance' => 0.0];
            foreach ($services as $s) {
                $net = $this->rfNet($s);
                $got = (float) ($paid[$s->id]->paid ?? 0);
                $bal = (int) $s->is_paid === 1 ? 0 : max(0, $net - $got);
                $b['billed'] += $net;
                $b['collected'] += $got;
                $b['balance'] += $bal;
                if ($bal > 0.004) {
                    $b['unpaid']++;
                    $owed[$s->ca_id][$t]['current'] = ($owed[$s->ca_id][$t]['current'] ?? 0) + $bal;
                } else {
                    $b['paid']++;
                }
            }
            $fwd = $this->rfForwarding($serType, $period, $eo);
            foreach ($fwd as $ca => $amt) $owed[$ca][$t]['forwarding'] = $amt;
            $b['forwarding'] = array_sum($fwd);
            foreach (['billed', 'collected', 'balance', 'forwarding'] as $k) $b[$k] = round($b[$k], 2);
            $billing[$t] = $b;
        }

        $acc = $this->rfAccounts(array_keys($owed));
        $branch = $this->branchNames(array_keys($owed));
        $unpaid = [];
        foreach ($owed as $ca => $types) {
            $row = [
                'ca_id' => (int) $ca,
                'client_name' => $acc[$ca]['client_name'] ?? '',
                'trade_name' => $acc[$ca]['trade_name'] ?? '',
                'branch' => $branch[$ca] ?? '',
                'forwarding' => 0.0, 'current' => 0.0,
            ];
            foreach (array_keys(self::RF_TYPES) as $t) {
                $f = $types[$t]['forwarding'] ?? 0;
                $c = $types[$t]['current'] ?? 0;
                $row[$t] = round($f + $c, 2);
                $row['forwarding'] += $f;
                $row['current'] += $c;
            }
            $row['forwarding'] = round($row['forwarding'], 2);
            $row['current'] = round($row['current'], 2);
            $row['total'] = round($row['forwarding'] + $row['current'], 2);
            $unpaid[] = $row;
        }
        usort($unpaid, fn ($a, $b) => $b['total'] <=> $a['total']);

        $collection['total'] = round($collection['total'], 2);
        $collection['by_type'] = array_map(fn ($v) => round($v, 2), $collection['by_type']);
        $deposit['deposited'] = round($deposit['deposited'], 2);
        $deposit['undeposited'] = round($deposit['undeposited'], 2);

        return response()->json([
            'month' => $d['month'],
            'summary' => $this->billingSummary($d['month']),
            'collection' => $collection, 'deposit' => $deposit, 'billing' => $billing,
            'daily' => $series($daily, 'day'), 'trend' => $series($trend, 'month'),
            'unpaid' => [
                'clients' => count($unpaid),
                'with_forwarding' => count(array_filter($unpaid, fn ($r) => $r['forwarding'] > 0)),
                'total' => round(array_sum(array_column($unpaid, 'total')), 2),
                'rows' => $unpaid,
            ],
        ]);
    }



    /** ca_id → "Branch code - Trade name" */
    private function branchNames($caIds)
    {
        return ClientAccount::query()->whereIn('id', collect($caIds)->unique()->values()->all() ?: [0])->get(['id', 'branch_code', 'trade_name'])
            ->mapWithKeys(fn ($a) => [$a->id => implode(' - ', array_filter([trim((string) $a->branch_code), trim((string) $a->trade_name)]))]);
    }

    /* ---------------- Billing KPIs: PF + EO only ---------------- */

    /**
     * PF + EO bills of the month (EO: the year), the payments on them, and the arrears
     * (earlier PF / EO bills whose SOA has no t_payment at all — matched by trans_number).
     */
    private function billingKpi(string $month): array
    {
        static $cache = [];
        if (isset($cache[$month])) return $cache[$month];

        $monthName = strtoupper((new DateTime($month . '-01'))->format('F Y'));
        $eoYear = 'EO-' . substr($month, 0, 4);
        $pf = array_map('intval', $this->rfListIds(self::RF_TYPES['PF']));
        $eo = array_map('intval', $this->rfListIds(self::RF_TYPES['EO']));
        $cols = ['id', 'ca_id', 'trans_number', 'service_period', 'ser_list_id', 'is_paid'];

        // bills of the month
        $bills = $this->rfServices($pf, [$monthName], $cols)->concat($this->rfServices($eo, [$eoYear], $cols))
            ->filter(fn ($s) => (float) $s->total_service_fee > 0)->values();

        // payments on those bills (+ who collected / deposited them)
        $col = fn (array $names) => $this->pick('t_payment', $names);
        $c = [
            'or' => $col(['or_number']), 'dep' => $col(['deposited', 'is_deposited']),
            'by' => $col(['user_id', 'collected_by', 'received_by']), 'depBy' => $col(['deposited_by_id', 'deposited_by']),
            'depDate' => $col(['date_deposited', 'deposited_date', 'deposit_date']), 'slip' => $col(['dep_slip_number']),
        ];
        $payments = collect();
        foreach ($bills->pluck('id')->chunk(1000) as $chunk) {
            $payments = $payments->concat(Payment::query()->whereIn('service_id', $chunk->all())
                ->get(array_values(array_filter(['id', 'service_id', 'amount', 'payment_date', 'trans_number', ...array_values($c)])))
                ->map(function ($p) use ($c) {
                    $deposited = $c['dep'] && (int) $p->{$c['dep']} === 1;
                    return (object) [
                        'id' => $p->id, 'service_id' => $p->service_id, 'amount' => (float) $p->amount,
                        'payment_date' => $p->payment_date ? substr((string) $p->payment_date, 0, 10) : '',
                        'trans_number' => $p->trans_number,
                        'or_number' => $c['or'] ? (string) $p->{$c['or']} : '',
                        'collected_by' => $c['by'] ? (int) $p->{$c['by']} : 0,
                        'deposited' => $deposited,
                        'deposited_by' => $deposited && $c['depBy'] ? (int) $p->{$c['depBy']} : 0,
                        'date_deposited' => $deposited && $c['depDate'] && $p->{$c['depDate']} ? substr((string) $p->{$c['depDate']}, 0, 10) : '',
                        'dep_slip_number' => $c['slip'] ? (string) $p->{$c['slip']} : '',
                    ];
                }));
        }

        // arrears: earlier bills, no payment on the SOA
        $sCols = array_map(fn ($c) => "S.{$c}", array_values(array_unique([...$cols, ...self::RF_NET_COLS])));
        $pfBefore = $this->rfEarlier($monthName, false);
        $eoBefore = $this->rfEarlier($eoYear, true);
        $arrears = DB::table('t_service as S')->select($sCols)
            ->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereIn('S.ser_list_id', $pf ?: [0])->whereIn('S.service_period', $pfBefore ?: ['']))
                ->orWhere(fn ($x) => $x->whereIn('S.ser_list_id', $eo ?: [0])->whereIn('S.service_period', $eoBefore ?: [''])))
            ->where(fn ($w) => $w->whereNull('S.invoice_status_id')->orWhere('S.invoice_status_id', '<>', self::VOID))
            ->where('S.total_service_fee', '>', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('t_payment as P')->whereColumn('P.trans_number', 'S.trans_number'))
            ->get();

        $type = fn ($s) => in_array((int) $s->ser_list_id, $eo, true) ? 'EO' : 'PF';
        return $cache[$month] = compact('bills', 'payments', 'arrears', 'type');
    }

    /** the 5 tiles: billed, collected, deposited, outstanding (billed − collected), arrears */
    private function billingSummary(string $month): array
    {
        $k = $this->billingKpi($month);
        $billed = round($k['bills']->sum(fn ($s) => $this->rfNet($s)), 2);
        $collected = round($k['payments']->sum('amount'), 2);
        $deposited = $k['payments']->where('deposited', true);
        $paidBy = $k['payments']->groupBy('service_id')->map->sum('amount');
        return [
            'billed'            => $billed,
            'billed_count'      => $k['bills']->count(),
            'collected'         => $collected,
            'collected_count'   => $k['payments']->count(),
            'deposited'         => round($deposited->sum('amount'), 2),
            'deposited_count'   => $deposited->count(),
            'outstanding'       => round($billed - $collected, 2),
            'outstanding_count' => $k['bills']->filter(fn ($s) => $this->rfNet($s) - ($paidBy[$s->id] ?? 0) > 0.004)->count(),
            'arrears'           => round($k['arrears']->sum(fn ($s) => $this->rfNet($s)), 2),
            'arrears_count'     => $k['arrears']->count(),
            'arrears_clients'   => $k['arrears']->pluck('ca_id')->unique()->count(),
        ];
    }

    /* GET /dashboard/billing/detail?month=YYYY-MM&kpi=billed|collected|deposited|outstanding|arrears   the popup of a tile (CEO only) */
    public function dashboardBillingDetail(Request $request)
    {
        abort_unless($this->canBilling($request), 403, 'For management and billing only.');
        $d = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'kpi'   => ['required', 'in:billed,collected,deposited,outstanding,arrears'],
        ]);
        $k = $this->billingKpi($d['month']);
        $type = $k['type'];
        $paidBy = $k['payments']->groupBy('service_id')->map->sum('amount');
        $services = $d['kpi'] === 'arrears' ? $k['arrears'] : $k['bills'];
        $billOf = $k['bills']->keyBy('id');

        // Client name + "Branch code - Trade name"
        $acc = $this->rfAccounts($services->pluck('ca_id')->unique()->values()->all());
        $trade = $this->branchNames($services->pluck('ca_id'));
        $who = fn ($caId) => ['client_name' => $acc[$caId]['client_name'] ?? '', 'branch' => $trade[$caId] ?? ''];
        $names = DB::table('m_service_list')->whereIn('id', $services->pluck('ser_list_id')->unique()->all() ?: [0])->pluck('name', 'id');

        if (in_array($d['kpi'], ['collected', 'deposited'], true)) {
            $list = $k['payments']->when($d['kpi'] === 'deposited', fn ($c) => $c->where('deposited', true));
            $emp = DB::table('m_employee as E')
                ->whereIn('E.id', $list->pluck('collected_by')->merge($list->pluck('deposited_by'))->filter()->unique()->values()->all() ?: [0])
                ->get(['E.id', DB::raw($this->employeeName() . ' AS name')])->pluck('name', 'id');
            $rows = $list->map(function ($p) use ($billOf, $type, $who, $names, $emp) {
                $s = $billOf[$p->service_id];
                return ['id' => $p->id] + $who($s->ca_id) + [
                    'type' => $type($s), 'service' => (string) ($names[$s->ser_list_id] ?? ''), 'period' => $s->service_period,
                    'trans_number' => $p->trans_number ?: $s->trans_number, 'or_number' => $p->or_number,
                    'amount' => round($p->amount, 2),
                    'collected_date' => $p->payment_date, 'collected_by' => trim((string) ($emp[$p->collected_by] ?? '')),
                    'deposited' => $p->deposited ? 'Yes' : 'No',
                    'deposited_date' => $p->date_deposited, 'deposited_by' => trim((string) ($emp[$p->deposited_by] ?? '')),
                    'dep_slip_number' => $p->dep_slip_number,
                ];
            })->sortByDesc($d['kpi'] === 'deposited' ? 'deposited_date' : 'collected_date')->values();
        } else {
            // date billed + months unpaid (arrears)
            $dateCol = $this->pick('t_transaction', ['date_start', 'transaction_date', 'created_at']);
            $billedOn = $dateCol ? DB::table('t_transaction')->whereIn('trans_number', $services->pluck('trans_number')->unique()->all() ?: [''])
                ->pluck($dateCol, 'trans_number') : collect();
            $monthStart = new DateTime($d['month'] . '-01');
            $rows = $services->map(function ($s) use ($paidBy, $type, $who, $names, $billedOn, $monthStart) {
                $net = $this->rfNet($s);
                $paid = (float) ($paidBy[$s->id] ?? 0);
                $billed = $billedOn[$s->trans_number] ?? null;
                $from = str_starts_with((string) $s->service_period, 'EO-')
                    ? DateTime::createFromFormat('!Y-m-d', substr($s->service_period, 3) . '-01-01')
                    : DateTime::createFromFormat('!F Y', ucwords(strtolower((string) $s->service_period)));
                $age = $from ? max(0, ((int) $monthStart->format('Y') - (int) $from->format('Y')) * 12 + (int) $monthStart->format('n') - (int) $from->format('n')) : null;
                return ['id' => $s->id] + $who($s->ca_id) + [
                    'type' => $type($s), 'service' => (string) ($names[$s->ser_list_id] ?? ''), 'period' => $s->service_period,
                    'trans_number' => $s->trans_number, 'date_billed' => $billed ? substr((string) $billed, 0, 10) : '',
                    'months_unpaid' => $age, 'amount' => $net, 'collected' => round($paid, 2), 'balance' => round($net - $paid, 2),
                    'status' => (int) $s->is_paid === 1 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid'),
                ];
            })->when($d['kpi'] === 'outstanding', fn ($c) => $c->filter(fn ($r) => $r['balance'] > 0.004))
              ->sortBy($d['kpi'] === 'arrears' ? [['months_unpaid', 'desc'], ['client_name', 'asc']] : [['client_name', 'asc'], ['period', 'asc']])
              ->values();
        }

        $byType = $rows->groupBy('type')->map(fn ($g, $t) => [
            'type' => $t, 'count' => $g->count(),
            'amount' => round($g->sum($d['kpi'] === 'outstanding' ? 'balance' : 'amount'), 2),
        ])->sortBy(fn ($t) => $t['type'] === 'PF' ? 0 : 1)->values();   // PF first, then EO

        return response()->json(['kpi' => $d['kpi'], 'month' => $d['month'], 'by_type' => $byType, 'rows' => $rows]);
    }

    /* GET /dashboard/clients?month=YYYY-MM   scans made by the client himself (employee_id 0 / null) by trans_type (CEO only) */
    public function dashboardClients(Request $request)
    {
        abort_unless($this->isCeo($request), 403, 'For the CEO only.');
        $d = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $range = $this->monthRange($d['month']);
        $empCol = $this->pick('t_mobile_transaction', ['employee_id', 'emp_id']);
        $byClient = fn ($q) => $q->when($empCol, fn ($q) => $q->where(fn ($w) => $w->whereNull("A.{$empCol}")->orWhere("A.{$empCol}", 0)));
        $type = 'LOWER(TRIM(A.trans_type))';

        // per account × type × status
        $rows = $byClient(DB::table('t_mobile_transaction as A'))
            ->join('m_user_client as U', 'U.id', '=', 'A.added_by')
            ->join('m_client_account as B', 'B.id', '=', 'U.caid')
            ->leftJoin('m_client as C', 'C.client_num', '=', 'B.client_num')
            ->whereBetween('A.created_at', $range)
            ->groupBy('B.id', DB::raw($type), 'A.trans_status')
            ->get([
                'B.id as ca_id',
                DB::raw("MAX(CONCAT_WS(' ', C.last_name, C.first_name, C.middle_name)) AS client_name"),
                DB::raw("MAX(CONCAT_WS(' ', B.branch_code, B.trade_name)) AS trade_name"),
                DB::raw("MAX(CONCAT_WS(' - ', NULLIF(TRIM(B.branch_code), ''), B.trade_name)) AS branch"),
                DB::raw("{$type} AS type"),
                'A.trans_status as st',
                DB::raw('COUNT(*) AS cnt'),
            ]);

        $blank = array_fill_keys(self::TRANS_TYPES, 0) + ['scans' => 0]
            + array_fill_keys(array_map(fn ($st) => 'scan_' . $st, array_keys(self::SCAN_LABELS)), 0);
        $out = [];
        foreach ($rows as $r) {
            $ca = (int) $r->ca_id;
            $out[$ca] ??= ['ca_id' => $ca, 'client_name' => trim((string) $r->client_name), 'trade_name' => trim((string) $r->trade_name),
                'branch' => trim((string) $r->branch)] + $blank;
            if (in_array($r->type, self::TRANS_TYPES, true)) $out[$ca][$r->type] += (int) $r->cnt;
            if (isset($out[$ca]['scan_' . (int) $r->st])) $out[$ca]['scan_' . (int) $r->st] += (int) $r->cnt;
            $out[$ca]['scans'] += (int) $r->cnt;
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => $b['scans'] <=> $a['scans']);

        // per day × type
        $days = $this->monthDays($d['month'], array_fill_keys(self::TRANS_TYPES, 0));
        foreach ($byClient(DB::table('t_mobile_transaction as A'))->whereBetween('A.created_at', $range)
                     ->selectRaw("DATE(A.created_at) AS day, {$type} AS type, COUNT(*) AS cnt")
                     ->groupByRaw("DATE(A.created_at), {$type}")->get() as $r) {
            if (isset($days[$r->day][$r->type])) $days[$r->day][$r->type] = (int) $r->cnt;
        }

        return response()->json([
            'month' => $d['month'], 'scan_labels' => self::SCAN_LABELS, 'rows' => $out, 'daily' => $this->dailyList($days),
        ]);
    }

    /**
     * GET /dashboard/employees?month=YYYY-MM
     * Data analyst (assigned_daid): scans + sales / purchases / expenses created this month.
     * Auditor: the audits he did this month (t_audit_history.auditor_id) on sales / purchases / expenses — no scans.
     * Fast: one grouped query per table (day × employee × type), everything else is summed in PHP.
     */
    public function dashboardEmployees(Request $request)
    {
        $d = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $range = $this->monthRange($d['month']);
        $ceo = $this->isCeo($request);
        $me = $this->myEmployeeId($request) ?? -1;
        $bind = fn (array $more = []) => $ceo ? [...$range, ...$more] : [...$range, ...$more, $me];

        $days = $this->monthDays($d['month'], ['scans' => 0, 'sales' => 0, 'purchases' => 0, 'expenses' => 0, 'audits' => 0]);
        $by = [];   // emp → key → n
        $add = function (int $emp, string $key, int $n) use (&$by) { $by[$emp][$key] = ($by[$emp][$key] ?? 0) + $n; };
        $missing = [];

        // 1) data analyst: scans + transactions created this month
        $scanDa = $this->pick('t_mobile_transaction', ['assigned_daid', 'employee_id', 'emp_id']);
        $sources = [
            // table, DA column, type, "confirmed", "reverted"
            ['t_mobile_transaction', $scanDa, "'scans'", 'trans_status = 1', '0'],
            ['t_sales_invoice_master', 'assigned_daid', "'sales'", '0', 'audit_status_id = ' . self::REVERTED],
            ['t_purchase_expense_master', 'assigned_daid', 'LOWER(TRIM(trans_type))', '0', 'audit_status_id = ' . self::REVERTED],
        ];
        foreach ($sources as [$table, $col, $type, $done, $rev]) {
            if (!$col || !in_array($col, $this->cols($table), true)) { $missing[] = "{$table}.assigned_daid"; continue; }
            $groupType = str_starts_with($type, "'") ? '' : ", {$type}";
            $rows = DB::select("
                SELECT DATE(created_at) AS day, {$col} AS emp, {$type} AS type, COUNT(*) AS cnt,
                       SUM(CASE WHEN {$done} THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN {$rev} THEN 1 ELSE 0 END) AS rev
                FROM {$table}
                WHERE created_at BETWEEN ? AND ? AND {$col} > 0" . ($ceo ? '' : " AND {$col} = ?") . "
                GROUP BY DATE(created_at), {$col}{$groupType}", $bind());
            foreach ($rows as $r) {
                if (!isset($days[$r->day][$r->type])) continue;
                $e = (int) $r->emp;
                $add($e, "da_{$r->type}", (int) $r->cnt);
                $r->type === 'scans' ? $add($e, 'da_confirmed', (int) $r->done) : $add($e, 'da_reverted', (int) $r->rev);
                $days[$r->day][$r->type] += (int) $r->cnt;
            }
        }

        // 2) auditor: audit history this month (status + type of the audited invoice)
        $statusKey = [1 => 'au_confirmed', 2 => 'au_audited', self::REVERTED => 'au_reverted'];
        foreach (['t_sales_invoice_master' => "'sales'", 't_purchase_expense_master' => 'LOWER(TRIM(D.trans_type))'] as $table => $type) {
            $groupType = str_starts_with($type, "'") ? '' : ", {$type}";
            $rows = DB::select("
                SELECT DATE(A.created_at) AS day, A.auditor_id AS emp, A.audit_status_id AS st, {$type} AS type, COUNT(*) AS cnt
                FROM t_audit_history A
                INNER JOIN {$table} D ON D.invoice_no = A.reference_no
                WHERE A.created_at BETWEEN ? AND ? AND A.auditor_id > 0" . ($ceo ? '' : ' AND A.auditor_id = ?') . "
                GROUP BY DATE(A.created_at), A.auditor_id, A.audit_status_id{$groupType}", $bind());
            foreach ($rows as $r) {
                if (!in_array($r->type, self::TRANS_TYPES, true)) continue;
                $e = (int) $r->emp;
                $n = (int) $r->cnt;
                $add($e, "au_{$r->type}", $n);
                if (isset($statusKey[(int) $r->st])) $add($e, $statusKey[(int) $r->st], $n);
                if (isset($days[$r->day])) $days[$r->day]['audits'] += $n;
            }
        }

        // who to show: CEO → active data analysts + auditors + everybody with numbers · others → only himself
        $ids = array_keys($by);
        if ($ceo) {
            $active = $this->pick('m_employee', ['is_active', 'active']);
            $ids = [...$ids, ...DB::table('m_employee')->whereIn('designation_id', [self::DATA_ANALYST, self::AUDITOR])
                ->when($active, fn ($q) => $q->where($active, 1))->pluck('id')->map(fn ($v) => (int) $v)->all()];
        } else {
            $ids = [$me];
        }
        $people = DB::table('m_employee as E')->whereIn('E.id', array_values(array_unique($ids)) ?: [0])
            ->get(['E.id', 'E.designation_id', DB::raw($this->employeeName() . ' AS name')]);

        $roles = [self::DATA_ANALYST => 'Data analyst', self::AUDITOR => 'Auditor'];
        $keys = ['da_scans', 'da_confirmed', 'da_sales', 'da_purchases', 'da_expenses', 'da_reverted',
            'au_sales', 'au_purchases', 'au_expenses', 'au_confirmed', 'au_audited', 'au_reverted'];
        $employees = $people->map(function ($p) use ($by, $roles, $keys) {
            $n = $by[(int) $p->id] ?? [];
            $row = ['emp_id' => (int) $p->id, 'name' => trim($p->name), 'role' => $roles[(int) $p->designation_id] ?? ''];
            foreach ($keys as $k) $row[$k] = $n[$k] ?? 0;
            $row['da_total'] = $row['da_sales'] + $row['da_purchases'] + $row['da_expenses'];
            $row['au_total'] = $row['au_sales'] + $row['au_purchases'] + $row['au_expenses'];
            return $row;
        })->sortBy('name')->values();

        return response()->json([
            'month' => $d['month'], 'employees' => $employees, 'daily' => $this->dailyList($days),
            'missing' => array_values(array_unique($missing)),
        ]);
    }

    /* =====================================================================
       ROLE DASHBOARDS (m_user_staff.user_type_id → m_user_type)
         management (IT OFFICER 1 · CEO 2 · SUPERVISOR 4 · CFO 8 · AUDIT SUPERVISOR 10): Billing · Client · Employees · Calendar
         DATA ANALYST 3      : GET /ims_dashboard/da?month=        assigned clients (m_client_account.assigned_daid)
         AUDITOR 5           : GET /ims_dashboard/auditor?month=   assigned clients (m_client_account.assigned_auid)
         LIAISON OFFICER 6 · BILLING & COLLECTION OFFICER 7: Billing
         anything else       : nothing
       ===================================================================== */

    /** m_user_staff.user_type_id, else the employee's designation */
    private function myUserType(Request $request): int
    {
        $u = $request->user();
        $type = (int) ($u->user_type_id ?? 0);
        if (!$type && ($emp = $this->myEmployeeId($request))) {
            $type = (int) DB::table('m_employee')->where('id', $emp)->value('designation_id');
        }
        return $type;
    }

    private function myRole(Request $request): string
    {
        $u = $request->user();
        if (!$u instanceof \App\Models\UserStaff) return 'none';
        $t = $this->myUserType($request);
        return match (true) {
            in_array($t, self::FULL_ACCESS_USER_TYPES, true) => 'management',
            in_array($t, self::BILLING_USER_TYPES, true)     => 'billing',
            $t === self::DA_USER_TYPE                         => 'da',
            $t === self::AUDITOR_USER_TYPE                    => 'auditor',
            default                                           => 'none',
        };
    }

    private function canBilling(Request $request): bool
    {
        return in_array($this->myRole($request), ['management', 'billing'], true);
    }

    /** the client accounts assigned to me (assigned_daid / assigned_auid = my m_employee.id) */
    private function myAccounts(Request $request, string $col)
    {
        $me = $this->myEmployeeId($request);
        if (!$me || !in_array($col, $this->cols('m_client_account'), true)) return collect();
        $compl = in_array('compliance_ids', $this->cols('m_client_account'), true) ? 'B.compliance_ids' : "''";
        $status = in_array('client_account_status_id', $this->cols('m_client_account'), true) ? 'B.client_account_status_id' : 'NULL';
        return DB::table('m_client_account as B')
            ->leftJoin('m_client as C', 'C.client_num', '=', 'B.client_num')
            ->where("B.{$col}", $me)
            ->orderBy('B.trade_name')
            ->get([
                'B.id as ca_id',
                DB::raw("TRIM(CONCAT_WS(' ', C.last_name, C.first_name, C.middle_name)) AS client_name"),
                DB::raw("CONCAT_WS(' - ', NULLIF(TRIM(B.branch_code), ''), B.trade_name) AS branch"),
                DB::raw("{$compl} AS compliance_ids"),
                DB::raw("{$status} AS status_id"),
            ]);
    }

    /** m_user_client.caid (the invoice / scan → client account link: added_by → m_user_client) */
    private function userCa(): string
    {
        return $this->pick('m_user_client', ['caid', 'ca_id', 'client_account_id']) ?? 'caid';
    }

    /**
     * invoices of these accounts: created this month per account × type, and per day
     * + still open per account (for audit = audit_status 1 / empty, reverted = 3)
     */
    private function roleInvoices(array $caIds, array $range): array
    {
        $uca = $this->userCa();
        $ids = implode(',', array_map('intval', $caIds ?: [0]));
        $byCa = [];
        $daily = [];
        $open = [];
        foreach (['t_sales_invoice_master' => "'sales'", 't_purchase_expense_master' => 'LOWER(TRIM(A.trans_type))'] as $t => $type) {
            if (!$this->cols($t)) continue;
            $void = in_array('invoice_status_id', $this->cols($t), true) ? ' AND COALESCE(A.invoice_status_id, 0) <> ' . self::VOID : '';
            $posted = in_array('record_status', $this->cols($t), true) ? " AND A.record_status = 'posted'" : '';
            $base = "FROM {$t} A INNER JOIN m_user_client U ON U.id = A.added_by WHERE U.{$uca} IN ({$ids}){$void}{$posted}";
            $gt = str_starts_with($type, "'") ? '' : ", {$type}";   // no constant in GROUP BY

            foreach (DB::select("SELECT U.{$uca} AS ca, {$type} AS type, DATE(A.created_at) AS day, COUNT(*) AS cnt
                                 {$base} AND A.created_at BETWEEN ? AND ? GROUP BY U.{$uca}{$gt}, DATE(A.created_at)", $range) as $r) {
                if (!in_array($r->type, self::TRANS_TYPES, true)) continue;
                $byCa[(int) $r->ca][$r->type] = ($byCa[(int) $r->ca][$r->type] ?? 0) + (int) $r->cnt;
                $daily[$r->day][$r->type] = ($daily[$r->day][$r->type] ?? 0) + (int) $r->cnt;
            }
            if (in_array('audit_status_id', $this->cols($t), true)) {
                foreach (DB::select("SELECT U.{$uca} AS ca, {$type} AS type,
                                            SUM(CASE WHEN COALESCE(A.audit_status_id, 1) = 1 THEN 1 ELSE 0 END) AS pending,
                                            SUM(CASE WHEN A.audit_status_id = " . self::REVERTED . " THEN 1 ELSE 0 END) AS reverted
                                     {$base} GROUP BY U.{$uca}{$gt}") as $r) {
                    if (!in_array($r->type, self::TRANS_TYPES, true)) continue;
                    $open[(int) $r->ca]['pending'] = ($open[(int) $r->ca]['pending'] ?? 0) + (int) $r->pending;
                    $open[(int) $r->ca]['reverted'] = ($open[(int) $r->ca]['reverted'] ?? 0) + (int) $r->reverted;
                    $open[(int) $r->ca]["pending_{$r->type}"] = ($open[(int) $r->ca]["pending_{$r->type}"] ?? 0) + (int) $r->pending;
                }
            }
        }
        return compact('byCa', 'daily', 'open');
    }

    /** scans (t_mobile_transaction) of these accounts this month: per account × status, per day */
    private function roleScans(array $caIds, array $range): array
    {
        $byCa = [];
        $daily = [];
        if (!$this->cols('t_mobile_transaction')) return compact('byCa', 'daily');
        $uca = $this->userCa();
        $rows = DB::table('t_mobile_transaction as A')
            ->join('m_user_client as U', 'U.id', '=', 'A.added_by')
            ->whereIn("U.{$uca}", $caIds ?: [0])
            ->whereBetween('A.created_at', $range)
            ->groupBy("U.{$uca}", 'A.trans_status', DB::raw('DATE(A.created_at)'))
            ->get([DB::raw("U.{$uca} AS ca"), 'A.trans_status as st', DB::raw('DATE(A.created_at) AS day'), DB::raw('COUNT(*) AS cnt')]);
        foreach ($rows as $r) {
            $ca = (int) $r->ca;
            $byCa[$ca]['scans'] = ($byCa[$ca]['scans'] ?? 0) + (int) $r->cnt;
            $byCa[$ca]['scan_' . (int) $r->st] = ($byCa[$ca]['scan_' . (int) $r->st] ?? 0) + (int) $r->cnt;
            $daily[$r->day] = ($daily[$r->day] ?? 0) + (int) $r->cnt;
        }
        return compact('byCa', 'daily');
    }

    /** compliance deadlines of these accounts (m_client_account.compliance_ids → m_compliance) */
    private function roleDeadlines($accounts): array
    {
        $table = collect(['m_compliance', 'm_compliances'])->first(fn ($t) => (bool) $this->cols($t));
        if (!$table) return [];
        $name = $this->pick($table, ['name', 'compliance', 'description']);
        $deadline = $this->pick($table, ['deadline']);
        $terms = $this->pick($table, ['terms']);
        $who = [];
        foreach ($accounts as $a) {
            foreach (array_filter(array_map('intval', explode(',', (string) $a->compliance_ids))) as $id) $who[$id][] = $a->branch ?: $a->client_name;
        }
        if (!$who || !$name) return [];
        $plain = fn ($v) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string) $v)))));
        return DB::table($table)->whereIn('id', array_keys($who))->orderBy($name)->get()
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'compliance' => (string) $c->{$name},
                'deadline' => $deadline ? $plain($c->{$deadline}) : '',
                'terms' => $terms ? (string) $c->{$terms} : '',
                'clients' => count($who[$c->id]),
                'client_list' => implode(', ', array_slice($who[$c->id], 0, 50)),
            ])->values()->all();
    }

    /* GET /ims_dashboard/da?month=YYYY-MM   DATA ANALYST: my assigned clients · scans · sales / purchases / expenses · reverted · deadlines */
    public function dashboardDa(Request $request)
    {
        abort_unless(in_array($this->myRole($request), ['da', 'management'], true), 403, 'For data analysts only.');
        $d = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $range = $this->monthRange($d['month']);
        $accounts = $this->myAccounts($request, 'assigned_daid');
        $caIds = $accounts->pluck('ca_id')->map(fn ($v) => (int) $v)->all();

        $inv = $this->roleInvoices($caIds, $range);
        $scan = $this->roleScans($caIds, $range);
        $days = $this->monthDays($d['month'], ['scans' => 0, 'sales' => 0, 'purchases' => 0, 'expenses' => 0]);
        foreach ($inv['daily'] as $day => $v) if (isset($days[$day])) $days[$day] = array_merge($days[$day], $v);
        foreach ($scan['daily'] as $day => $n) if (isset($days[$day])) $days[$day]['scans'] = $n;

        $rows = $accounts->map(function ($a) use ($inv, $scan) {
            $ca = (int) $a->ca_id;
            $row = ['ca_id' => $ca, 'client_name' => trim((string) $a->client_name), 'branch' => trim((string) $a->branch),
                'scans' => $scan['byCa'][$ca]['scans'] ?? 0, 'scan_0' => $scan['byCa'][$ca]['scan_0'] ?? 0, 'scan_1' => $scan['byCa'][$ca]['scan_1'] ?? 0];
            foreach (self::TRANS_TYPES as $t) $row[$t] = $inv['byCa'][$ca][$t] ?? 0;
            $row['total'] = $row['sales'] + $row['purchases'] + $row['expenses'];
            $row['pending'] = $inv['open'][$ca]['pending'] ?? 0;
            $row['reverted'] = $inv['open'][$ca]['reverted'] ?? 0;
            return $row;
        })->sortByDesc(fn ($r) => $r['reverted'] * 1000000 + $r['scan_0'] * 1000 + $r['total'])->values();

        return response()->json([
            'month' => $d['month'], 'employee_id' => $this->myEmployeeId($request),
            'scan_labels' => self::SCAN_LABELS,
            'clients' => $rows, 'daily' => $this->dailyList($days),
            'deadlines' => $this->roleDeadlines($accounts),
        ]);
    }

    /* GET /ims_dashboard/auditor?month=YYYY-MM   AUDITOR: my assigned clients · for audit · audited / reverted by me · scan monitoring · deadlines */
    public function dashboardAuditor(Request $request)
    {
        abort_unless(in_array($this->myRole($request), ['auditor', 'management'], true), 403, 'For auditors only.');
        $d = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $range = $this->monthRange($d['month']);
        $me = $this->myEmployeeId($request) ?? -1;
        $accounts = $this->myAccounts($request, 'assigned_auid');
        $caIds = $accounts->pluck('ca_id')->map(fn ($v) => (int) $v)->all();

        $inv = $this->roleInvoices($caIds, $range);
        $scan = $this->roleScans($caIds, $range);

        // my audits this month (t_audit_history.auditor_id) → per client account × status, per day
        $uca = $this->userCa();
        $audit = [];
        $days = $this->monthDays($d['month'], ['audited' => 0, 'reverted' => 0, 'confirmed' => 0, 'scans' => 0]);
        $statusKey = [1 => 'confirmed', self::AUDITED => 'audited', self::REVERTED => 'reverted'];
        if ($this->cols('t_audit_history')) {
            foreach (['t_sales_invoice_master', 't_purchase_expense_master'] as $t) {
                if (!$this->cols($t)) continue;
                foreach (DB::select("
                    SELECT U.{$uca} AS ca, A.audit_status_id AS st, DATE(A.created_at) AS day, COUNT(*) AS cnt
                    FROM t_audit_history A
                    INNER JOIN {$t} D ON D.invoice_no = A.reference_no
                    LEFT JOIN m_user_client U ON U.id = D.added_by
                    WHERE A.created_at BETWEEN ? AND ? AND A.auditor_id = ?
                    GROUP BY U.{$uca}, A.audit_status_id, DATE(A.created_at)", [...$range, $me]) as $r) {
                    $k = $statusKey[(int) $r->st] ?? null;
                    if (!$k) continue;
                    $audit[(int) $r->ca][$k] = ($audit[(int) $r->ca][$k] ?? 0) + (int) $r->cnt;
                    if (isset($days[$r->day])) $days[$r->day][$k] += (int) $r->cnt;
                }
            }
        }
        foreach ($scan['daily'] as $day => $n) if (isset($days[$day])) $days[$day]['scans'] = $n;

        $rows = $accounts->map(function ($a) use ($inv, $scan, $audit) {
            $ca = (int) $a->ca_id;
            $row = ['ca_id' => $ca, 'client_name' => trim((string) $a->client_name), 'branch' => trim((string) $a->branch)];
            foreach (self::TRANS_TYPES as $t) $row["pending_{$t}"] = $inv['open'][$ca]["pending_{$t}"] ?? 0;
            $row['pending'] = $inv['open'][$ca]['pending'] ?? 0;
            $row['open_reverted'] = $inv['open'][$ca]['reverted'] ?? 0;
            $row['audited'] = $audit[$ca]['audited'] ?? 0;
            $row['reverted'] = $audit[$ca]['reverted'] ?? 0;
            $row['scans'] = $scan['byCa'][$ca]['scans'] ?? 0;
            foreach (array_keys(self::SCAN_LABELS) as $st) $row["scan_{$st}"] = $scan['byCa'][$ca]["scan_{$st}"] ?? 0;
            return $row;
        })->sortByDesc(fn ($r) => $r['pending'] * 1000 + $r['scans'])->values();

        return response()->json([
            'month' => $d['month'], 'employee_id' => $this->myEmployeeId($request),
            'scan_labels' => self::SCAN_LABELS,
            'clients' => $rows, 'daily' => $this->dailyList($days),
            'deadlines' => $this->roleDeadlines($accounts),
        ]);
    }
}