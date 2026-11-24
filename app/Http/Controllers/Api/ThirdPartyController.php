<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ThirdParty\AwsBilling;
use App\Services\ThirdParty\GoDaddyBilling;
use App\Services\ThirdParty\ThirdPartySync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administration → Third Party · billing of Amazon (AWS) and GoDaddy
 *   GET  /third-party/summary
 *   GET  /third-party/bills?provider=&kind=&q=&from=&to=&sort=&dir=&page=&per_page=
 *   GET  /third-party/costs?months=12&period=YYYY-MM
 *   GET  /third-party/renewals?kind=&within=&q=&sort=&dir=&page=&per_page=
 *   GET  /third-party/logs
 *   POST /third-party/sync { provider? }
 */
class ThirdPartyController extends Controller
{
    public function summary()
    {
        $thisMonth = now()->format('Y-m');
        $lastMonth = now()->subMonthNoOverflow()->format('Y-m');
        $today = now()->toDateString();
        $cost = fn ($p) => (float) DB::table('t_tp_cost')->where('provider', 'aws')->where('period', $p)->sum('amount');
        $lastSync = fn ($p) => DB::table('t_tp_sync_log')->where('provider', $p)->orderByDesc('id')->first(['ok', 'message', 'started_at', 'finished_at']);
        $renew = DB::table('t_tp_renewal')->where('provider', 'godaddy');

        return response()->json([
            'aws' => [
                'configured' => AwsBilling::configured(),
                'this_month' => round($cost($thisMonth), 2),
                'this_month_estimated' => (bool) DB::table('t_tp_cost')->where('provider', 'aws')->where('period', $thisMonth)->where('estimated', true)->exists(),
                'last_month' => round($cost($lastMonth), 2),
                'last_12' => round((float) DB::table('t_tp_cost')->where('provider', 'aws')
                    ->where('period', '>=', now()->startOfMonth()->subMonthsNoOverflow(11)->format('Y-m'))->sum('amount'), 2),
                'currency' => DB::table('t_tp_cost')->where('provider', 'aws')->value('currency') ?: 'USD',
                'last_invoice' => DB::table('t_tp_bill')->where('provider', 'aws')->orderByDesc('bill_date')->orderByDesc('id')
                    ->first(['ref_no', 'bill_date', 'period', 'amount', 'currency', 'due_date']),
                'last_sync' => $lastSync('aws'),
            ],
            'godaddy' => [
                'configured' => GoDaddyBilling::configured(),
                'spend_12' => round((float) DB::table('t_tp_bill')->where('provider', 'godaddy')
                    ->where('bill_date', '>=', now()->startOfMonth()->subMonthsNoOverflow(11)->toDateString())->sum('amount'), 2),
                'currency' => DB::table('t_tp_bill')->where('provider', 'godaddy')->whereNotNull('currency')->value('currency'),
                'domains' => (clone $renew)->where('kind', 'domain')->count(),
                'subscriptions' => (clone $renew)->where('kind', 'subscription')->count(),
                'due_30' => (clone $renew)->whereBetween('expires_at', [$today, now()->addDays(30)->toDateString()])->count(),
                'due_60' => (clone $renew)->whereBetween('expires_at', [$today, now()->addDays(60)->toDateString()])->count(),
                'expired' => (clone $renew)->where('expires_at', '<', $today)->count(),
                'next' => (clone $renew)->where('expires_at', '>=', $today)->orderBy('expires_at')->first(['kind', 'name', 'expires_at', 'renew_auto']),
                'last_sync' => $lastSync('godaddy'),
            ],
        ]);
    }

    public function bills(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $query = DB::table('t_tp_bill')
            ->when(in_array($request->query('provider'), ThirdPartySync::PROVIDERS, true), fn ($w) => $w->where('provider', $request->query('provider')))
            ->when($request->query('kind'), fn ($w, $v) => $w->where('kind', $v))
            ->when($request->query('from'), fn ($w, $v) => $w->where('bill_date', '>=', $v))
            ->when($request->query('to'), fn ($w, $v) => $w->where('bill_date', '<=', $v))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('ref_no', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")));

        $totals = (clone $query)->groupBy('currency')->selectRaw('currency, ROUND(SUM(amount),2) AS amount, COUNT(*) AS n')->get();
        $this->sort($request, $query, ['bill_date', 'provider', 'ref_no', 'period', 'amount', 'due_date', 'status'], 'bill_date');
        return response()->json($this->page($request, $query->select(['id', 'provider', 'kind', 'ref_no', 'bill_date', 'period', 'description',
            'amount', 'tax', 'currency', 'due_date', 'status', 'synced_at'])) + ['totals' => $totals]);
    }

    public function costs(Request $request)
    {
        $months = min(24, max(1, (int) $request->query('months', 12)));
        $from = now()->startOfMonth()->subMonthsNoOverflow($months - 1)->format('Y-m');
        $byMonth = DB::table('t_tp_cost')->where('provider', 'aws')->where('period', '>=', $from)
            ->groupBy('period')->orderBy('period')
            ->selectRaw('period, ROUND(SUM(amount),2) AS total, MAX(estimated) AS estimated, MAX(currency) AS currency')->get();
        $period = $request->query('period') ?: ($byMonth->last()->period ?? now()->format('Y-m'));
        $services = DB::table('t_tp_cost')->where('provider', 'aws')->where('period', $period)->where('amount', '>', 0.004)
            ->orderByDesc('amount')->get(['service', DB::raw('ROUND(amount,2) AS amount'), 'currency', 'estimated']);
        return response()->json(['months' => $byMonth, 'period' => $period, 'services' => $services]);
    }

    public function renewals(Request $request)
    {
        $today = now()->toDateString();
        $q = trim((string) $request->query('q', ''));
        $within = (int) $request->query('within', 0);
        $query = DB::table('t_tp_renewal')
            ->when($request->query('kind'), fn ($w, $v) => $w->where('kind', $v))
            ->when($request->query('within') === 'expired', fn ($w) => $w->where('expires_at', '<', $today))
            ->when($within > 0, fn ($w) => $w->whereBetween('expires_at', [$today, now()->addDays($within)->toDateString()]))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('product', 'like', "%{$q}%")->orWhere('ref', 'like', "%{$q}%")));
        $this->sort($request, $query, ['expires_at', 'name', 'kind', 'product', 'status', 'renew_auto'], 'expires_at', 'asc');
        return response()->json($this->page($request, $query->select(['id', 'provider', 'kind', 'ref', 'name', 'product', 'status', 'expires_at', 'renew_auto', 'synced_at',
            DB::raw("DATEDIFF(expires_at, '{$today}') AS days_left")])));
    }

    public function logs()
    {
        return response()->json(DB::table('t_tp_sync_log')->orderByDesc('id')->limit(30)
            ->get(['id', 'provider', 'ok', 'message', 'run_by', 'started_at', 'finished_at']));
    }

    public function sync(Request $request, ThirdPartySync $sync)
    {
        $d = $request->validate(['provider' => ['nullable', 'in:aws,godaddy'], 'months' => ['nullable', 'integer', 'min:1', 'max:24']]);
        @set_time_limit(300);
        $res = $sync->run($d['provider'] ?? null, $request->user()?->id, (int) ($d['months'] ?? 12));
        $ok = collect($res)->every(fn ($r) => $r['ok']);
        return response()->json([
            'message' => collect($res)->map(fn ($r, $p) => strtoupper($p) . ': ' . ($r['ok'] ? 'synced' : 'failed'))->implode(' · '),
            'ok' => $ok, 'results' => $res,
        ], $ok ? 200 : 207);
    }

    /* ---------- helpers ---------- */
    private function sort(Request $request, $query, array $allowed, string $default, string $defaultDir = 'desc'): void
    {
        $sort = in_array($request->query('sort'), $allowed, true) ? $request->query('sort') : $default;
        $dir = strtolower((string) $request->query('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir)->orderByDesc('id');
    }

    /** one page only is read from the database: { rows, total, page, per_page } */
    private function page(Request $request, $query): array
    {
        $per = min(100, max(10, (int) $request->query('per_page', 25)));
        $total = (clone $query)->getCountForPagination();
        $page = min(max(1, (int) $request->query('page', 1)), max(1, (int) ceil($total / $per)));
        return ['rows' => $query->forPage($page, $per)->get(), 'total' => $total, 'page' => $page, 'per_page' => $per];
    }
}
