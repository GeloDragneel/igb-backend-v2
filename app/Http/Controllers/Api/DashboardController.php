<?php

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home dashboard: GET /api/dashboard?from=2026-01&to=2026-12
 * Every section is wrapped in try/catch so one missing table never breaks the whole page.
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

    public function summary(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $from = $d['from']; $to = $d['to'];
        $voidId = (int) (DB::table('m_invoice_status')->whereRaw("UPPER(name) LIKE 'VOID%'")->value('id') ?: 5);

        /* ---------- sales (posted, not void) ---------- */
        $sales = $this->safe(function () use ($by, $from, $to, $voidId) {
            $q = DB::table('t_sales_invoice_master')->where('added_by', $by)
                ->whereBetween('declared_month', [$from, $to])->where('invoice_status_id', '<>', $voidId)
                ->where(fn ($w) => $w->whereNull('trans_type')->orWhere('trans_type', 'posted'));
            $nos = (clone $q)->pluck('invoice_no');
            return [
                'total' => round((float) (clone $q)->sum('grand_total'), 2),
                'count' => (clone $q)->count(),
                'vat'   => round((float) DB::table('t_sales_invoice_detail')->whereIn('invoice_no', $nos)->sum('input_vat'), 2),
            ];
        }, ['total' => 0, 'count' => 0, 'vat' => 0]);

        /* ---------- purchases + expenses (posted, not void) ---------- */
        $buy = $this->safe(function () use ($by, $from, $to, $voidId) {
            $q = DB::table('t_purchase_expense_master')->where('added_by', $by)
                ->whereBetween('declared_month', [$from, $to])->where('invoice_status_id', '<>', $voidId);
            if (Schema::hasColumn('t_purchase_expense_master', 'record_status')) {
                $q->where(fn ($w) => $w->whereNull('record_status')->orWhere('record_status', 'posted'));
            }
            $nos = (clone $q)->pluck('invoice_no');
            return [
                'purchases' => round((float) (clone $q)->whereRaw("LOWER(trans_type) = 'purchases'")->sum('grand_total'), 2),
                'expenses'  => round((float) (clone $q)->whereRaw("LOWER(trans_type) = 'expenses'")->sum('grand_total'), 2),
                'count'     => (clone $q)->count(),
                'vat'       => round((float) DB::table('t_purchase_expense_detail')->whereIn('invoice_no', $nos)->sum('input_vat'), 2),
            ];
        }, ['purchases' => 0, 'expenses' => 0, 'count' => 0, 'vat' => 0]);

        /* ---------- unpaid sales invoices (all time) ---------- */
        $unpaid = $this->safe(function () use ($by) {
            $ids = DB::table('m_invoice_status')->where(fn ($w) => $w->whereRaw("UPPER(name) LIKE 'UNPAID%'")->orWhereRaw("UPPER(name) LIKE 'PARTIAL%'"))->pluck('id');
            $q = DB::table('t_sales_invoice_master')->where('added_by', $by)->whereIn('invoice_status_id', $ids)
                ->where(fn ($w) => $w->whereNull('trans_type')->whereIn('trans_type', ['posted', 'billing'], 'or'));
            return ['total' => round((float) (clone $q)->sum('grand_total'), 2), 'count' => (clone $q)->count()];
        }, ['total' => 0, 'count' => 0]);

        /* ---------- things that need attention ---------- */
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

        /* ---------- last 6 months: sales vs purchases + expenses ---------- */
        $months = [];
        $end = Carbon::createFromFormat('Y-m-d', $to . '-01');
        for ($i = 11; $i >= 0; $i--) $months[] = $end->copy()->subMonths($i)->format('Y-m');
        
        $trend = $this->safe(function () use ($by, $months, $voidId) {
            $s = DB::table('t_sales_invoice_master')->where('added_by', $by)->whereIn('declared_month', $months)
                ->where('invoice_status_id', '<>', $voidId)->where(fn ($w) => $w->whereNull('trans_type')->orWhere('trans_type', 'posted'))
                ->selectRaw('declared_month AS m, SUM(grand_total) AS t')->groupBy('m')->pluck('t', 'm');
            $p = DB::table('t_purchase_expense_master')->where('added_by', $by)->whereIn('declared_month', $months)
                ->where('invoice_status_id', '<>', $voidId)
                ->selectRaw('declared_month AS m, SUM(grand_total) AS t')->groupBy('m')->pluck('t', 'm');
            return array_map(fn ($m) => [
                'month' => $m, 'label' => Carbon::createFromFormat('Y-m-d', $m . '-01')->format('M'),
                'sales' => round((float) ($s[$m] ?? 0), 2), 'costs' => round((float) ($p[$m] ?? 0), 2),
            ], $months);
        }, []);

        /* ---------- recent sales invoices ---------- */
        $recent = $this->safe(function () use ($by) {
            $rows = DB::table('t_sales_invoice_master as M')
                ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.customer_id')
                ->leftJoin('m_invoice_status as S', 'S.id', '=', 'M.invoice_status_id')
                ->where('M.added_by', $by)->where(fn ($w) => $w->whereNull('M.trans_type')->orWhere('M.trans_type', 'posted'))
                ->orderByDesc('M.id')->limit(6)
                ->get(['M.invoice_no', 'M.transaction_date', 'M.grand_total', DB::raw("COALESCE(C.name, 'WALK IN') AS customer"), DB::raw("COALESCE(S.name, '') AS status")]);
            return $rows->map(fn ($r) => (array) $r + ['transaction_date' => substr((string) $r->transaction_date, 0, 10)]);
        }, []);

        /* ---------- top customers this period ---------- */
        $topCustomers = $this->safe(function () use ($by, $from, $to, $voidId) {
            return DB::table('t_sales_invoice_master as M')
                ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.customer_id')
                ->where('M.added_by', $by)->whereBetween('M.declared_month', [$from, $to])
                ->where('M.invoice_status_id', '<>', $voidId)
                ->where(fn ($w) => $w->whereNull('M.trans_type')->orWhere('M.trans_type', 'posted'))
                ->groupBy('M.customer_id', 'C.name')
                ->selectRaw("COALESCE(C.name, 'WALK IN') AS name, SUM(M.grand_total) AS total, COUNT(*) AS invoices")
                ->orderByDesc('total')->limit(5)->get()
                ->map(fn ($r) => ['name' => $r->name, 'total' => round((float) $r->total, 2), 'invoices' => (int) $r->invoices]);
        }, []);

        return response()->json([
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
}