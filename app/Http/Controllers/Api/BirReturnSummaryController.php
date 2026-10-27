<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports → BIR Return Summary  (the "SUMMARY OF RETURNS" + "AUDIT" Excel)
 *
 *   GET  /reports/bir-return-summary/clients                       client list (Staff)
 *   GET  /reports/bir-return-summary?client_num=&year=             system amounts + what was saved
 *   PUT  /reports/bir-return-summary   {client_num, year, summary, audit}
 *
 * System amounts (all branches of the client, by declared month):
 *   slsp      per quarter  – Sales vatable / exempt (+ zero rated), Gov't sales (invoice with 2307 VT),
 *                            Purchases vatable / exempt (receive inventory + AP Purchases + Expenses), input VAT
 *   q1701     per quarter  – sales, purchases (receive inventory + AP Purchases), itemized deduction (AP Expenses)
 *   eq1601    per quarter  – 2307 IT on AP invoices + receive inventory, by ATC rate
 *   ewt_month per month    – the same 2307 IT per month (0619-E when there's no filed return)
 *   s2307     per month    – 2307 VT on sales (2307_vt_declared_month) · it_2307 – 2307 IT on sales for the year
 *   filed     per form     – t_bir_return_filing amounts (2550Q · 2550M / 0605 · 1701Q / 1702Q · 0619E · 1601EQ · 1601C)
 */
class BirReturnSummaryController extends Controller
{
    private const TABLE = 't_bir_return_summary';
    private const VOID = 5;

    /* ---------------- helpers ---------------- */

    private function staffOnly(Request $request): void
    {
        abort_unless($request->user() instanceof UserStaff, 403, 'BIR Return Summary is for staff logins.');
    }

    private function has(string $table, string $col): bool
    {
        static $cols = [];
        $cols[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        return in_array($col, $cols[$table], true);
    }

    private function caCol(): string
    {
        foreach (['caid', 'ca_id', 'client_account_id'] as $c) if ($this->has('m_user_client', $c)) return $c;
        return 'caid';
    }

    /** m_user_client ids of every branch of the client */
    private function usersOf(string $clientNum): array
    {
        $caids = DB::table('m_client_account')->where('client_num', $clientNum)->pluck('id')->all();
        if (!$caids) return [];
        return DB::table('m_user_client')->whereIn($this->caCol(), $caids)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function q(string $col): string
    {
        // quarter 1-4 from a Y-m column
        return "CEIL(CAST(SUBSTRING({$col}, 6, 2) AS UNSIGNED) / 3)";
    }

    private function m(string $col): string
    {
        return "SUBSTRING({$col}, 6, 2)";
    }

    private function r2($v): float
    {
        return round((float) $v, 2);
    }

    /* ---------------- GET clients ---------------- */

    public function clients(Request $request)
    {
        $this->staffOnly($request);
        $acc = DB::table('m_client_account')->orderBy('trade_name')->get(['id', 'client_num', 'trade_name', 'branch_type']);
        $people = Schema::hasTable('m_client')
            ? DB::table('m_client')->get(['client_num', 'last_name', 'first_name', 'middle_name'])->keyBy('client_num')
            : collect();
        $saved = Schema::hasTable(self::TABLE)
            ? DB::table(self::TABLE)->groupBy('client_num')->selectRaw('client_num, GROUP_CONCAT(year ORDER BY year DESC) AS years')->pluck('years', 'client_num')
            : collect();

        $out = $acc->groupBy('client_num')->map(function ($rows, $num) use ($people, $saved) {
            $main = $rows->first(fn ($a) => strtoupper((string) $a->branch_type) === 'MAIN') ?? $rows->first();
            $p = $people[$num] ?? null;
            $person = $p ? trim(preg_replace('/\s+/', ' ', "{$p->last_name}, {$p->first_name} " . ($p->middle_name ?? '')), ' ,') : '';
            return [
                'client_num' => (string) $num,
                'name'       => $main->trade_name ?: ($person ?: (string) $num),
                'person'     => $person,
                'branches'   => $rows->count(),
                'saved'      => isset($saved[$num]) ? array_map('intval', explode(',', $saved[$num])) : [],
            ];
        })->filter(fn ($c) => $c['client_num'] !== '')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json($out);
    }

    /* ---------------- GET one client + year ---------------- */

    public function show(Request $request)
    {
        $this->staffOnly($request);
        $d = $request->validate([
            'client_num' => ['required', 'string', 'max:60'],
            'year'       => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);
        $num = $d['client_num'];
        $year = (int) $d['year'];
        abort_unless(DB::table('m_client_account')->where('client_num', $num)->exists(), 422, 'Client not found.');

        $users = $this->usersOf($num);
        $saved = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->where('client_num', $num)->where('year', $year)->first() : null;

        return response()->json([
            'client' => $this->clientInfo($num),
            'year'   => $year,
            'system' => $users ? $this->system($users, $year) : $this->emptySystem(),
            'saved'  => $saved ? [
                'summary'    => json_decode((string) $saved->summary, true) ?: new \stdClass(),
                'audit'      => json_decode((string) $saved->audit, true) ?: new \stdClass(),
                'updated_at' => $saved->updated_at,
                'updated_by' => $saved->updated_by_name,
            ] : null,
            'migrated' => Schema::hasTable(self::TABLE),
        ]);
    }

    /* ---------------- PUT save ---------------- */

    public function save(Request $request)
    {
        $this->staffOnly($request);
        abort_unless(Schema::hasTable(self::TABLE), 422, 'Run the t_bir_return_summary migration first (php artisan migrate).');
        $d = $request->validate([
            'client_num' => ['required', 'string', 'max:60'],
            'year'       => ['required', 'integer', 'min:2000', 'max:2100'],
            'summary'    => ['present', 'array'],
            'audit'      => ['present', 'array'],
        ]);
        abort_unless(DB::table('m_client_account')->where('client_num', $d['client_num'])->exists(), 422, 'Client not found.');

        $u = $request->user();
        $name = $u->username ?? '';
        if (!empty($u->comid) && Schema::hasTable('m_employee')) {
            $e = DB::table('m_employee')->where('com_id', $u->comid)->first(['last_name', 'first_name']);
            if ($e) $name = trim("{$e->first_name} {$e->last_name}") ?: $name;
        }

        $now = now();
        DB::table(self::TABLE)->updateOrInsert(
            ['client_num' => $d['client_num'], 'year' => (int) $d['year']],
            [
                'summary'         => json_encode($d['summary'], JSON_UNESCAPED_UNICODE),
                'audit'           => json_encode($d['audit'], JSON_UNESCAPED_UNICODE),
                'updated_by'      => $u->id,
                'updated_by_name' => mb_substr($name, 0, 120),
                'updated_at'      => $now,
            ]
        );
        DB::table(self::TABLE)->where('client_num', $d['client_num'])->where('year', (int) $d['year'])->whereNull('created_at')->update(['created_at' => $now]);

        return response()->json(['ok' => true, 'updated_at' => $now->toDateTimeString(), 'updated_by' => $name]);
    }

    /* ---------------- client header ---------------- */

    private function clientInfo(string $num): array
    {
        $rows = DB::table('m_client_account')->where('client_num', $num)->get();
        $main = $rows->first(fn ($a) => strtoupper((string) ($a->branch_type ?? '')) === 'MAIN') ?? $rows->first();
        $reg = '';
        if (Schema::hasTable('m_client')) {
            $c = DB::table('m_client')->where('client_num', $num)->first();
            if ($c && !empty($c->last_name)) $reg = trim(preg_replace('/\s+/', ' ', "{$c->last_name}, {$c->first_name} " . ($c->middle_name ?? '')), ' ,');
        }
        $tin = '';
        if (class_exists(\App\Models\ClientValidId::class)) {
            try {
                $tin = (string) \App\Models\ClientValidId::bir()->where('client_num', $num)->orderByDesc('id')
                    ->pluck('id_num')->map(fn ($t) => preg_replace('/\D/', '', (string) $t))->first(fn ($t) => strlen($t) >= 9);
            } catch (\Throwable $e) { $tin = ''; }
        }
        return [
            'client_num' => $num,
            'trade_name' => (string) ($main->trade_name ?? ''),
            'name'       => $reg,
            'tin'        => $tin ? implode('-', str_split(substr($tin, 0, 9), 3)) : '',
            'branches'   => $rows->count(),
        ];
    }

    /* ---------------- system amounts ---------------- */

    private function emptySystem(): array
    {
        return ['slsp' => [], 'q1701' => [], 'eq1601' => [], 'ewt_month' => [], 's2307' => [], 'it_2307' => 0, 'input_vat' => 0, 'filed' => []];
    }

    private function system(array $users, int $year): array
    {
        $from = sprintf('%04d-01', $year);
        $to = sprintf('%04d-12', $year);
        $in = implode(',', array_fill(0, count($users), '?'));
        $out = $this->emptySystem();
        $add = function (array &$arr, $k, string $f, $v) {
            $arr[$k] ??= [];
            $arr[$k][$f] = $this->r2(($arr[$k][$f] ?? 0) + (float) $v);
        };

        /* ---- Sales (SLS): vatable · exempt + zero rated · Gov't (invoice with 2307 VT) ---- */
        if (Schema::hasTable('t_sales_invoice_detail')) {
            $ex = $this->has('t_sales_invoice_detail', 'ex_rate') ? 'COALESCE(NULLIF(x.ex_rate,0),1)' : '1';
            $gov = $this->has('t_sales_invoice_master', '2307_vt')
                ? "CASE WHEN COALESCE(sm.`2307_vt`,0) <> 0 THEN COALESCE(x.net_of_vat,0)*{$ex} ELSE 0 END" : '0';
            $rows = DB::table('t_sales_invoice_detail as x')
                ->leftJoin('t_sales_invoice_master as sm', function ($j) {
                    $j->on('sm.invoice_no', '=', 'x.invoice_no')->on('sm.added_by', '=', 'x.added_by');
                })
                ->whereIn('x.added_by', $users)->where('x.invoice_status_id', '<>', self::VOID)->where('x.trans_type', 'posted')
                ->whereBetween('x.declared_month', [$from, $to])
                ->selectRaw($this->q('x.declared_month') . " AS q,
                    SUM(COALESCE(x.net_of_vat,0)*{$ex}) AS vatable, SUM(COALESCE(x.vat_exempt,0)*{$ex}) AS exempt,
                    SUM(COALESCE(x.zero_rated,0)*{$ex}) AS zero, SUM(COALESCE(x.input_vat,0)*{$ex}) AS vat, SUM({$gov}) AS gov")
                ->groupBy('q')->get();
            foreach ($rows as $r) {
                $q = (string) (int) $r->q;
                $add($out['slsp'], $q, 'vatable', $r->vatable);
                $add($out['slsp'], $q, 'exempt', (float) $r->exempt + (float) $r->zero);
                $add($out['slsp'], $q, 'gov', $r->gov);
                $add($out['slsp'], $q, 'output_vat', $r->vat);
                $add($out['q1701'], $q, 'sales', (float) $r->vatable + (float) $r->exempt + (float) $r->zero);
            }
        }

        /* ---- Purchases (SLP): receive inventory + AP Purchases + Expenses ---- */
        $purch = function ($rows, bool $isExpense) use (&$out, $add) {
            foreach ($rows as $r) {
                $q = (string) (int) $r->q;
                $add($out['slsp'], $q, 'vat_purch', $r->net);
                $add($out['slsp'], $q, 'exempt_purch', (float) $r->exempt + (float) $r->zero);
                $add($out['slsp'], $q, 'input_vat', $r->vat);
                $add($out['q1701'], $q, $isExpense ? 'itemized' : 'purchases', (float) $r->net + (float) $r->exempt + (float) $r->zero);
                $out['input_vat'] = $this->r2($out['input_vat'] + (float) $r->vat);
            }
        };
        $sel = fn (string $mon) => $this->q($mon) . ' AS q, SUM(COALESCE(x.net_of_vat,0)) AS net, SUM(COALESCE(x.input_vat,0)) AS vat,
            SUM(COALESCE(x.vat_exempt,0)) AS exempt, SUM(COALESCE(x.zero_rated,0)) AS zero';
        if (Schema::hasTable('t_grn_detail')) {
            $purch(DB::table('t_grn_detail as x')->whereIn('x.receive_by', $users)->where('x.grn_status_id', '<>', self::VOID)
                ->whereBetween('x.declared_month', [$from, $to])->selectRaw($sel('x.declared_month'))->groupBy('q')->get(), false);
        }
        if (Schema::hasTable('t_purchase_expense_detail')) {
            foreach (['purchases' => false, 'expenses' => true] as $type => $isExp) {
                $q = DB::table('t_purchase_expense_detail as x')
                    ->join('t_purchase_expense_master as mm', 'mm.invoice_no', '=', 'x.invoice_no')
                    ->whereIn('mm.added_by', $users)->where('mm.invoice_status_id', '<>', self::VOID)
                    ->whereBetween('mm.declared_month', [$from, $to])
                    ->whereRaw('LOWER(TRIM(mm.trans_type)) = ?', [$type]);
                if ($this->has('t_purchase_expense_master', 'record_status')) $q->where('mm.record_status', 'posted');
                if ($this->has('t_purchase_expense_detail', 'record_status')) $q->where('x.record_status', 'posted');
                $purch($q->selectRaw($sel('mm.declared_month'))->groupBy('q')->get(), $isExp);
            }
        }

        /* ---- 1601-EQ: 2307 IT withheld on AP invoices + receive inventory, by quarter and ATC rate ---- */
        $atcT = Schema::hasTable('m_atc') ? 'm_atc' : (Schema::hasTable('lib_atc') ? 'lib_atc' : null);
        $rateCol = $atcT && $this->has($atcT, 'rate') ? 'rate' : ($atcT && $this->has($atcT, 'tax_rate') ? 'tax_rate' : null);
        $eq = [];
        $em = [];
        foreach ([['t_purchase_expense_master', 'added_by', true], ['t_grn_master', 'receive_by', false]] as [$t, $by, $posted]) {
            if (!$this->has($t, '2307_it') || !$this->has($t, '2307_it_declared_month')) continue;
            $q = DB::table("{$t} as A")->whereIn("A.{$by}", $users)->where('A.invoice_status_id', '<>', self::VOID)
                ->whereBetween('A.2307_it_declared_month', [$from, $to])->where('A.2307_it', '<>', 0);
            if ($posted && $this->has($t, 'record_status')) $q->where('A.record_status', 'posted');
            $rate = '0';
            if ($atcT && $rateCol && $this->has($t, 'atc_it_id')) {
                $q->leftJoin("{$atcT} as D", 'D.id', '=', 'A.atc_it_id');
                $rate = "COALESCE(D.{$rateCol},0)";
            }
            $rows = $q->selectRaw("A.`2307_it_declared_month` AS mon, {$rate} AS rate, SUM(COALESCE(A.sub_total,0)) AS base, SUM(A.`2307_it`) AS tax")
                ->groupBy('mon', 'rate')->get();
            foreach ($rows as $r) {
                $qq = (string) (int) ceil(((int) substr($r->mon, 5, 2)) / 3);
                $rt = (float) $r->rate;
                $rt = $rt > 1 ? $rt / 100 : $rt;
                $rk = number_format($rt, 4, '.', '');
                $eq[$qq][$rk] ??= ['rate' => round($rt, 4), 'base' => 0.0, 'tax' => 0.0];
                $eq[$qq][$rk]['base'] += (float) $r->base;
                $eq[$qq][$rk]['tax'] += (float) $r->tax;
                $mm = substr($r->mon, 5, 2);
                $em[$mm] = $this->r2(($em[$mm] ?? 0) + (float) $r->tax);
            }
        }
        foreach ($eq as $qq => $lines) {
            ksort($lines);
            $out['eq1601'][$qq] = array_values(array_map(fn ($l) => ['rate' => $l['rate'], 'base' => $this->r2($l['base']), 'tax' => $this->r2($l['tax'])], $lines));
        }
        $out['ewt_month'] = $em;

        /* ---- 2307 on sales: VT per month (0605 / 2550M credit) · IT for the year (income tax credit) ---- */
        if ($this->has('t_sales_invoice_master', '2307_vt') && $this->has('t_sales_invoice_master', '2307_vt_declared_month')) {
            $rows = DB::table('t_sales_invoice_master')->whereIn('added_by', $users)->where('invoice_status_id', '<>', self::VOID)
                ->where('trans_type', 'posted')->whereBetween('2307_vt_declared_month', [$from, $to])->where('2307_vt', '<>', 0)
                ->selectRaw($this->m('`2307_vt_declared_month`') . ' AS mm, SUM(`2307_vt`) AS amt')->groupBy('mm')->get();
            foreach ($rows as $r) $out['s2307'][$r->mm] = $this->r2($r->amt);
        }
        if ($this->has('t_sales_invoice_master', '2307_it') && $this->has('t_sales_invoice_master', '2307_it_declared_month')) {
            $out['it_2307'] = $this->r2(DB::table('t_sales_invoice_master')->whereIn('added_by', $users)->where('invoice_status_id', '<>', self::VOID)
                ->where('trans_type', 'posted')->whereBetween('2307_it_declared_month', [$from, $to])->sum('2307_it'));
        }

        /* ---- filed returns (BIR & Tax → filing) ---- */
        if (Schema::hasTable('t_bir_return_filing')) {
            $rows = DB::table('t_bir_return_filing')->whereIn('added_by', $users)
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('period_from', [$from, $to])->orWhereBetween('period_to', [$from, $to]);
                })->get(['form', 'period_from', 'period_to', 'amount']);
            foreach ($rows as $r) {
                $form = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $r->form));
                $key = match (true) {
                    str_starts_with($form, '2550Q')                          => '2550Q',
                    str_starts_with($form, '2550M'), str_starts_with($form, '0605') => '2550M',
                    str_starts_with($form, '1701Q'), str_starts_with($form, '1702Q') => '1701Q',
                    str_starts_with($form, '0619E')                          => '0619E',
                    str_starts_with($form, '1601EQ')                         => '1601EQ',
                    str_starts_with($form, '1601C')                          => '1601C',
                    default                                                  => null,
                };
                if (!$key) continue;
                $end = (string) ($r->period_to ?: $r->period_from);
                if (substr($end, 0, 4) !== (string) $year) continue;
                $mm = substr($end, 5, 2);
                $slot = in_array($key, ['2550Q', '1701Q', '1601EQ'], true) ? (string) (int) ceil(((int) $mm) / 3) : $mm;
                $out['filed'][$key][$slot] = $this->r2(($out['filed'][$key][$slot] ?? 0) + (float) $r->amount);
            }
        }

        return $out;
    }
}