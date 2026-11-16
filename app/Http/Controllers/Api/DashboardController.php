<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home dashboard: GET /api/dashboard?from=2026-01&to=2026-12
 * Every section is wrapped in try/catch so one missing table never breaks the whole page.
 *
 * Branches: MAIN account → every branch of the client (same client_num) consolidated
 *           a branch      → that branch only
 *   (sales, purchases & expenses, VAT, unpaid, trend, top customers)
 *
 * Amounts = the "Net VAT" totals of Books & Ledger → Reports (same lines, same filters):
 *   sales      = Sales Report      (t_sales_invoice_detail · posted · not VOID · net_of_vat × ex_rate)
 *   purchases  = Purchase Report   (t_purchase_expense_detail purchases · posted · not VOID · amount ≠ 0
 *                                   + t_grn_detail Receive Inventory · not VOID · total ≠ 0)
 *   expenses   = Expense Report    (t_purchase_expense_detail expenses · posted · not VOID · vendor exists)
 *   all by declared month
 *
 * detail.sales / detail.buy = the Sales / Purchases & expenses popup on Home:
 *   months = per declared month of the period (count + net) · rows = the invoices of the period (net), newest first
 */
class DashboardController extends SalesController
{
    private function safe(callable $fn, $fallback = null)
    {
        try { return $fn(); } catch (\Throwable $e) { report($e); return $fallback; }
    }

    private function has(string $table, ?string $col = null): bool
    {
        return Schema::hasTable($table) && (!$col || Schema::hasColumn($table, $col));
    }

    /** MAIN → logins of every branch of the client · branch → own login (same rule as the reports' "All branches") */
    private function scope(Request $request): array
    {
        $by = $this->addedByFor($request);
        $caid = $this->caidFor($request);
        $account = $caid ? DB::table('m_client_account')->where('id', $caid)->first() : null;
        $isMain = $account && strtoupper(trim((string) ($account->branch_type ?? ''))) === 'MAIN';

        if (!$isMain) return ['ids' => [$by], 'main' => false, 'branches' => 1];

        $caids = DB::table('m_client_account')->where('client_num', $account->client_num)->pluck('id');
        $ids = DB::table('m_user_client')->whereIn('caid', $caids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (!in_array((int) $by, $ids, true)) $ids[] = (int) $by;

        return ['ids' => array_values(array_filter($ids)), 'main' => true, 'branches' => $caids->count()];
    }

    /* ---------- the lines behind the Sales / Purchase / Expense Reports (same filters, Net VAT) ---------- */

    const SALES_NET = 'D.net_of_vat * COALESCE(NULLIF(D.ex_rate, 0), 1)';
    const SALES_VAT = 'D.input_vat * COALESCE(NULLIF(D.ex_rate, 0), 1)';

    /** Sales Report: t_sales_invoice_detail, posted, not VOID */
    private function salesLines(array $ids)
    {
        return DB::table('t_sales_invoice_detail as D')->whereIn('D.added_by', $ids)
            ->where('D.invoice_status_id', '<>', 5)->where('D.trans_type', 'posted');
    }

    /** Purchase Report (AP part) / Expense Report: t_purchase_expense_detail, posted, not VOID */
    private function apLines(array $ids, string $kind)
    {
        $q = DB::table('t_purchase_expense_detail as D')->whereIn('D.added_by', $ids)
            ->whereRaw('LOWER(TRIM(D.trans_type)) = ?', [$kind])
            ->where('D.record_status', 'posted')->where('D.invoice_status_id', '<>', 5);
        if ($kind === 'purchases') $q->where('D.amount', '<>', 0);
        else $q->join('m_vendor_customer as VX', 'VX.id', '=', 'D.vendor_id');   // Expense Report: vendor must exist
        return $q;
    }

    /** Purchase Report (Receive Inventory part): t_grn_detail, not VOID, total ≠ 0 */
    private function grnLines(array $ids)
    {
        return DB::table('t_grn_detail as D')->whereIn('D.receive_by', $ids)
            ->where('D.total', '<>', 0)->where('D.grn_status_id', '<>', 5);
    }

    public function summary(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $from = $d['from']; $to = $d['to'];

        $scope = $this->safe(fn () => $this->scope($request), ['ids' => [$by], 'main' => false, 'branches' => 1]);
        $ids = $scope['ids'];
        $hasGrn = $this->has('t_grn_detail');

        /* ---------- sales = Sales Report Net VAT ---------- */
        $sales = $this->safe(function () use ($ids, $from, $to) {
            $r = $this->salesLines($ids)->whereBetween('D.declared_month', [$from, $to])
                ->selectRaw('SUM(' . self::SALES_NET . ') AS net, SUM(' . self::SALES_VAT . ') AS vat, COUNT(DISTINCT D.invoice_no) AS n')->first();
            return ['total' => round((float) ($r->net ?? 0), 2), 'count' => (int) ($r->n ?? 0), 'vat' => round((float) ($r->vat ?? 0), 2)];
        }, ['total' => 0, 'count' => 0, 'vat' => 0]);

        /* ---------- purchases = Purchase Report Net VAT (AP purchases + Receive Inventory) · expenses = Expense Report Net VAT ---------- */
        $buy = $this->safe(function () use ($ids, $from, $to, $hasGrn) {
            $sum = fn ($q, string $key) => $q->whereBetween('D.declared_month', [$from, $to])
                ->selectRaw("SUM(D.net_of_vat) AS net, SUM(D.input_vat) AS vat, COUNT(DISTINCT D.{$key}) AS n")->first();
            $p = $sum($this->apLines($ids, 'purchases'), 'invoice_no');
            $e = $sum($this->apLines($ids, 'expenses'), 'invoice_no');
            $g = $hasGrn ? $sum($this->grnLines($ids), 'grn_no') : null;
            return [
                'purchases' => round((float) ($p->net ?? 0) + (float) ($g->net ?? 0), 2),
                'expenses'  => round((float) ($e->net ?? 0), 2),
                'count'     => (int) ($p->n ?? 0) + (int) ($e->n ?? 0) + (int) ($g->n ?? 0),
                'vat'       => round((float) ($p->vat ?? 0) + (float) ($e->vat ?? 0) + (float) ($g->vat ?? 0), 2),
            ];
        }, ['purchases' => 0, 'expenses' => 0, 'count' => 0, 'vat' => 0]);

        /* ---------- unpaid sales invoices (all time, what the customer owes = grand total) ---------- */
        $unpaid = $this->safe(function () use ($ids) {
            $st = DB::table('m_invoice_status')->where(fn ($w) => $w->whereRaw("UPPER(name) LIKE 'UNPAID%'")->orWhereRaw("UPPER(name) LIKE 'PARTIAL%'"))->pluck('id');
            $q = DB::table('t_sales_invoice_master')->whereIn('added_by', $ids)->whereIn('invoice_status_id', $st)
                ->where(fn ($w) => $w->whereNull('trans_type')->whereIn('trans_type', ['posted', 'billing'], 'or'));
            return ['total' => round((float) (clone $q)->sum('grand_total'), 2), 'count' => (clone $q)->count()];
        }, ['total' => 0, 'count' => 0]);

        /* ---------- things that need attention (this login only: the links open this branch's lists) ---------- */
        $attention = [
            'tickets' => $this->safe(fn () => DB::table('t_request_ticket')->where('request_by', $by)->where('is_status', 0)->count(), 0),
            'scanned' => $this->safe(fn () => DB::table('t_mobile_transaction')->where('added_by', $by)->where('trans_status', 0)
                ->selectRaw('LOWER(trans_type) AS t, COUNT(*) AS n')->groupBy('t')->pluck('n', 't'), []),
            'for_collection' => $this->safe(fn () => DB::table('t_sales_invoice_master')->where('added_by', $by)->where('trans_type', 'billing')->count(), 0),
            'for_payment' => $this->safe(fn () => $this->has('t_purchase_expense_master', 'record_status')
                ? DB::table('t_purchase_expense_master')->where('added_by', $by)->where('record_status', 'billing')->count() : 0, 0),
            'unaudited' => $this->safe(function () use ($by, $from, $to) {
                $pending = DB::table('m_audit_status')->whereRaw("UPPER(name) LIKE 'PENDING%'")->value('id');
                return DB::table('t_sales_invoice_master')->where('added_by', $by)->whereBetween('declared_month', [$from, $to])
                    ->where(fn ($w) => $w->whereNull('audit_status_id')->orWhere('audit_status_id', $pending ?: 1))->count();
            }, 0),
            'out_of_stock' => $this->safe(fn () => $this->has('m_items', 'inventory_qty')
                ? DB::table('m_items')->where('added_by', $by)
                    ->when(Schema::hasColumn('m_items', 'item_class_id'), fn ($q) => $q->where('item_class_id', 1))
                    ->where('inventory_qty', '<=', 0)->count() : 0, 0),
            'locked_months' => $this->safe(fn () => DB::table('m_delared_month_settings')->where('ca_id', $this->caidFor($request))->pluck('declared_month'), []),
        ];

        /* ---------- last 12 months: sales vs purchases + expenses (net) ---------- */
        $months = [];
        $end = Carbon::createFromFormat('Y-m-d', $to . '-01');
        for ($i = 11; $i >= 0; $i--) $months[] = $end->copy()->subMonths($i)->format('Y-m');

        $trend = $this->safe(function () use ($ids, $months, $hasGrn) {
            $by = fn ($q, string $net) => $q->whereIn('D.declared_month', $months)
                ->groupBy('D.declared_month')->selectRaw("D.declared_month AS m, SUM({$net}) AS t")->pluck('t', 'm');
            $s = $by($this->salesLines($ids), self::SALES_NET);
            $parts = [$by($this->apLines($ids, 'purchases'), 'D.net_of_vat'), $by($this->apLines($ids, 'expenses'), 'D.net_of_vat')];
            if ($hasGrn) $parts[] = $by($this->grnLines($ids), 'D.net_of_vat');
            return array_map(fn ($m) => [
                'month' => $m, 'label' => Carbon::createFromFormat('Y-m-d', $m . '-01')->format('M'),
                'sales' => round((float) ($s[$m] ?? 0), 2),
                'costs' => round(array_sum(array_map(fn ($x) => (float) ($x[$m] ?? 0), $parts)), 2),
            ], $months);
        }, []);

        /* ---------- recent sales invoices (this login: they open in this branch) ---------- */
        $recent = $this->safe(function () use ($by) {
            $rows = DB::table('t_sales_invoice_master as M')
                ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.customer_id')
                ->leftJoin('m_invoice_status as S', 'S.id', '=', 'M.invoice_status_id')
                ->where('M.added_by', $by)->where(fn ($w) => $w->whereNull('M.trans_type')->orWhere('M.trans_type', 'posted'))
                ->orderByDesc('M.id')->limit(6)
                ->get(['M.invoice_no', 'M.transaction_date', 'M.grand_total', DB::raw("COALESCE(C.name, 'WALK IN') AS customer"), DB::raw("COALESCE(S.name, '') AS status")]);
            return $rows->map(fn ($r) => (array) $r + ['transaction_date' => substr((string) $r->transaction_date, 0, 10)]);
        }, []);

        /* ---------- top customers this period (net) ---------- */
        $topCustomers = $this->safe(function () use ($ids, $from, $to) {
            return $this->salesLines($ids)->whereBetween('D.declared_month', [$from, $to])
                ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'D.customer_id')
                ->groupBy('D.customer_id', 'C.name')
                ->selectRaw("COALESCE(C.name, 'WALK IN') AS name, SUM(" . self::SALES_NET . ") AS total, COUNT(DISTINCT D.invoice_no) AS invoices")
                ->orderByDesc('total')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->name, 'total' => round((float) $r->total, 2), 'invoices' => (int) $r->invoices]);
        }, []);

        /* ---------- popup of the Sales / Purchases & expenses tiles ---------- */
        $detail = [
            'sales' => $this->safe(fn () => $this->flowDetail('sales', $ids, $from, $to, (int) $by, $hasGrn, $scope['main']), ['months' => [], 'rows' => []]),
            'buy'   => $this->safe(fn () => $this->flowDetail('buy', $ids, $from, $to, (int) $by, $hasGrn, $scope['main']), ['months' => [], 'rows' => []]),
        ];

        return response()->json([
            'scope' => ['main' => $scope['main'], 'branches' => $scope['branches']],
            'detail' => $detail,
            'sales' => $sales,
            'buy' => $buy,
            'vat_payable' => round($sales['vat'] - $buy['vat'], 2),
            'unpaid' => $unpaid,
            'attention' => $attention,
            'trend' => $trend,
            'recent' => $recent,
            'top_customers' => $topCustomers,
        ]);
    }

    /** popup: per month + the documents of the period (Net VAT, same lines as the reports) */
    private function flowDetail(string $kind, array $ids, string $from, string $to, int $by, bool $hasGrn, bool $main = false): array
    {
        $months = [];
        $c = Carbon::createFromFormat('Y-m-d', $from . '-01');
        $end = Carbon::createFromFormat('Y-m-d', $to . '-01');
        for (; $c <= $end; $c->addMonth()) {
            $months[$c->format('Y-m')] = ['month' => $c->format('Y-m'), 'label' => $c->format('M Y'), 'count' => 0, 'books' => 0.0, 'purchases' => 0.0, 'expenses' => 0.0];
        }

        // per branch: Main = every branch of the client · a branch = itself (branches with nothing are dropped below)
        $myCaids = DB::table('m_user_client')->whereIn('id', $ids)->pluck('caid')->filter()->unique()->values();
        $accounts = $main
            ? DB::table('m_client_account as B')
                ->whereIn('B.client_num', DB::table('m_client_account')->whereIn('id', $myCaids)->pluck('client_num')->filter()->unique())
            : DB::table('m_client_account as B')->whereIn('B.id', $myCaids);
        $branches = [];
        foreach ($accounts->orderBy('B.branch_code')->get(['B.id', 'B.branch_code', 'B.trade_name', 'B.branch_type']) as $b) {
            $branches[(int) $b->id] ??= [
                'caid' => (int) $b->id, 'branch_code' => (string) $b->branch_code, 'trade_name' => (string) $b->trade_name,
                'main' => strtoupper(trim((string) $b->branch_type)) === 'MAIN',
                'count' => 0, 'books' => 0.0, 'purchases' => 0.0, 'expenses' => 0.0,
            ];
        }

        // sources: [query, net, document key, part]
        $sources = $kind === 'sales'
            ? [[$this->salesLines($ids), self::SALES_NET, 'invoice_no', 'sales']]
            : array_filter([
                [$this->apLines($ids, 'purchases'), 'D.net_of_vat', 'invoice_no', 'purchases'],
                $hasGrn ? [$this->grnLines($ids), 'D.net_of_vat', 'grn_no', 'grn'] : null,
                [$this->apLines($ids, 'expenses'), 'D.net_of_vat', 'invoice_no', 'expenses'],
            ]);

        $docs = collect();
        foreach ($sources as [$q, $net, $key, $part]) {
            // per month
            foreach ((clone $q)->whereBetween('D.declared_month', [$from, $to])->groupBy('D.declared_month')
                         ->selectRaw("D.declared_month AS m, COUNT(DISTINCT D.{$key}) AS n, SUM({$net}) AS v")->get() as $r) {
                if (!isset($months[$r->m])) continue;
                $months[$r->m]['count'] += (int) $r->n;
                $months[$r->m]['books'] += (float) $r->v;
                if ($part !== 'sales') $months[$r->m][$part === 'expenses' ? 'expenses' : 'purchases'] += (float) $r->v;
            }

            $isGrn = $part === 'grn';
            $userCol = $isGrn ? 'D.receive_by' : 'D.added_by';

            // per branch
            foreach ((clone $q)->whereBetween('D.declared_month', [$from, $to])
                         ->join('m_user_client as UB', 'UB.id', '=', $userCol)->groupBy('UB.caid')
                         ->selectRaw("UB.caid AS caid, COUNT(DISTINCT D.{$key}) AS n, SUM({$net}) AS v")->get() as $r) {
                if (!isset($branches[(int) $r->caid])) continue;
                $branches[(int) $r->caid]['count'] += (int) $r->n;
                $branches[(int) $r->caid]['books'] += (float) $r->v;
                if ($part !== 'sales') $branches[(int) $r->caid][$part === 'expenses' ? 'expenses' : 'purchases'] += (float) $r->v;
            }

            // documents (one row per invoice / GRN)
            $dateCol = $isGrn ? 'D.grn_date' : 'D.transaction_date';
            $partyCol = $part === 'sales' ? 'D.customer_id' : 'D.vendor_id';
            $docCol = $isGrn ? 'D.po_number' : 'D.document_no';
            $docs = $docs->merge((clone $q)->whereBetween('D.declared_month', [$from, $to])
                ->leftJoin('m_vendor_customer as C', 'C.id', '=', $partyCol)
                ->leftJoin('m_user_client as U', 'U.id', '=', $userCol)
                ->leftJoin('m_client_account as B', 'B.id', '=', 'U.caid')
                ->when(!$isGrn, fn ($x) => $x->leftJoin('m_invoice_status as S', 'S.id', '=', 'D.invoice_status_id'))
                ->groupBy("D.{$key}")
                ->orderByDesc(DB::raw("MAX({$dateCol})"))
                ->limit(200)
                ->get([
                    DB::raw("D.{$key} AS no"),
                    DB::raw("MAX({$docCol}) AS document_no"),
                    DB::raw("MAX({$dateCol}) AS date"),
                    DB::raw("MAX({$userCol}) AS added_by"),
                    DB::raw("MAX(COALESCE(C.name, '" . ($part === 'sales' ? 'WALK IN' : '') . "')) AS party"),
                    DB::raw($isGrn ? "'RECEIVED' AS status" : "MAX(COALESCE(S.name, '')) AS status"),
                    DB::raw("MAX(TRIM(CONCAT_WS(' - ', NULLIF(TRIM(B.branch_code), ''), B.trade_name))) AS branch"),
                    DB::raw("SUM({$net}) AS amount"),
                ])
                ->map(function ($r) use ($part, $by) {
                    $base = ['sales' => '/task/sales/', 'purchases' => '/task/purchases/', 'expenses' => '/task/expenses/', 'grn' => '/task/inventory/receive/'][$part];
                    return [
                        'invoice_no'  => (string) $r->no,
                        'document_no' => (string) ($r->document_no ?: $r->no),
                        'date'        => substr((string) $r->date, 0, 10),
                        'party'       => $r->party,
                        'branch'      => $r->branch,
                        'amount'      => round((float) $r->amount, 2),
                        'status'      => $r->status,
                        // other branches' documents open only from their own login
                        'link'        => (int) $r->added_by === $by ? $base . rawurlencode((string) $r->no) : null,
                    ];
                }));
        }

        $months = array_map(fn ($m) => ['books' => round($m['books'], 2), 'purchases' => round($m['purchases'], 2), 'expenses' => round($m['expenses'], 2)] + $m, array_values($months));
        $branches = array_map(fn ($b) => ['books' => round($b['books'], 2), 'purchases' => round($b['purchases'], 2), 'expenses' => round($b['expenses'], 2)] + $b, array_values($branches));
        // only branches with entries in the period
        $branches = array_values(array_filter($branches, fn ($b) => $b['count'] > 0 || abs($b['books']) >= 0.005));
        // Main first, then by branch code
        usort($branches, fn ($a, $b) => ($b['main'] <=> $a['main']) ?: strcmp($a['branch_code'], $b['branch_code']));

        return ['months' => $months, 'branches' => $branches, 'rows' => $docs->sortByDesc('date')->take(200)->values()->all()];
    }
}