<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Billing → SOA dashboard (old pages/Billing/Dashboard.php + function/Dashboard.php)
 *   client → trade name (client account) → service period (t_transaction) → SOA
 *   Services (t_service) · Payments (t_payment) · PF / Returns / Non-recurring balances
 * Invoice status on a service: 1 PAID · 2 UNPAID · 3 PARTIAL · 5 VOID · 9 CANCELLED
 */
class BillingController extends Controller
{
    const PAID = 1, UNPAID = 2, PARTIAL = 3, VOID = 5, CANCELLED = 9;
    const SUPERVISOR_TYPES = [4];          // old doGetSupervisorList: UserTypeID IN (4)

    // balance groups (old LoadCalculateBills): service type ids → payment collection type
    const GROUPS = [
        'pf'      => ['label' => 'PF & Other Charges',  'ser_types' => [4, 5, 6, 7], 'col_type' => 1],
        'returns' => ['label' => 'Returns',             'ser_types' => [3],          'col_type' => 2],
        'eo'      => ['label' => 'Non-recurring / EO',  'ser_types' => [2],          'col_type' => 4],
    ];

    /* ---------------- helpers ---------------- */

    private static array $cols = [];

    /** does the table exist? cached 1 hour (Schema::hasTable asks information_schema, slow on a big MySQL server) */
    private function hasTable(string $table): bool
    {
        static $seen = [];
        return $seen[$table] ??= (bool) \Illuminate\Support\Facades\Cache::remember("billing:table:{$table}", 3600, fn () => Schema::hasTable($table) ? 1 : 0);
    }

    private function cols(string $table): array
    {
        // column lists rarely change: keep them 1 hour instead of asking MySQL's information_schema on every request
        return self::$cols[$table] ??= \Illuminate\Support\Facades\Cache::remember("billing:cols:{$table}", 3600,
            fn () => $this->hasTable($table) ? Schema::getColumnListing($table) : []);
    }

    private function pick(string $table, array $names): ?string
    {
        foreach ($names as $n) foreach ($this->cols($table) as $c) if (strcasecmp($c, $n) === 0) return $c;
        return null;
    }

    private function firstTable(array $names): ?string
    {
        foreach ($names as $n) if ($this->hasTable($n)) return $n;
        return null;
    }

    private function only(string $table, array $row): array
    {
        return array_intersect_key($row, array_flip($this->cols($table)));
    }

    private function employeeSql(string $alias = 'E'): string
    {
        return "TRIM(CONCAT_WS(' ', {$alias}.last_name, {$alias}.first_name, {$alias}.middle_name))";
    }

    private function clientNameSql(string $alias = 'C'): string
    {
        $col = $this->pick('m_client', ['registered_name', 'client_name', 'business_name', 'company_name', 'name']);
        $person = "TRIM(CONCAT_WS(' ', {$alias}.last_name, {$alias}.first_name, {$alias}.middle_name))";
        return $col ? "COALESCE(NULLIF(TRIM({$alias}.{$col}), ''), {$person})" : $person;
    }

    /** a service's amount due: total + VAT − withholding − discount (old CalServiceFeeNet) */
    private function netSql(string $alias = 'S'): string
    {
        return "(COALESCE({$alias}.total_service_fee,0) + COALESCE({$alias}.inherited_vat,0) - COALESCE({$alias}.inherited_wh,0) - COALESCE({$alias}.inherited_discount_amount,0))";
    }

    private function placeName(?string $table, $id): string
    {
        if (!$table || !$id) return '';
        $col = $this->pick($table, ['name', 'barangay', 'city', 'city_mun', 'province', 'description']);
        return $col ? (string) DB::table($table)->where('id', $id)->value($col) : '';
    }

    /** supervisor username + password (m_user_staff, hashed) → staff row or null */
    private function supervisor(?string $username, ?string $password): ?object
    {
        if (!$username || !$password) return null;
        $u = DB::table('m_user_staff')->where('username', $username)->where('is_active', 1)
            ->whereIn('user_type_id', self::SUPERVISOR_TYPES)->first();
        return $u && Hash::check($password, (string) $u->password) ? $u : null;
    }

    /** the logged-in staff's m_employee id (employee_id / emp_id column, the employee() relation, or the same id) – only if it exists in m_employee */
    private function me(Request $request): ?int
    {
        static $cache = [];
        $u = $request->user();
        if (!$u) return null;
        $key = get_class($u) . ':' . $u->id;
        if (array_key_exists($key, $cache)) return $cache[$key];
 
        $exists = fn ($id) => $id > 0 && DB::table('m_employee')->where('id', $id)->exists();
 
        // 1) employee id on the login (m_user_client_staff.employee_id …)
        foreach (['employee_id', 'emp_id', 'employeeid'] as $k) {
            $v = (int) $u->getAttribute($k);
            if ($exists($v)) return $cache[$key] = $v;
        }
        // 2) staff login: m_user_staff.comid → m_employee.com_id  (same link as everywhere else)
        foreach (['comid', 'com_id'] as $k) {
            $c = $u->getAttribute($k);
            if ($c !== null && $c !== '' && ($id = (int) DB::table('m_employee')->where('com_id', $c)->value('id'))) return $cache[$key] = $id;
        }
        // 3) relation on the model, if there is one
        if (method_exists($u, 'employee') && ($e = $u->employee) && $exists((int) $e->id)) return $cache[$key] = (int) $e->id;
 
        return $cache[$key] = null;
    }

    /** recompute PAID / PARTIAL / UNPAID for each service of an SOA (old UpdateInvoiceStatusByPayment) */
    private function settle(string $transNumber): void
    {
        $paid = DB::table('t_payment')->where('trans_number', $transNumber)->whereNotNull('service_id')
            ->groupBy('service_id')->selectRaw('service_id, SUM(amount) AS amt')->pluck('amt', 'service_id');
        $services = DB::table('t_service as S')->where('S.trans_number', $transNumber)
            ->whereNotIn(DB::raw('COALESCE(S.invoice_status_id, 0)'), [self::VOID, self::CANCELLED])
            ->get(['S.id', DB::raw($this->netSql() . ' AS net')]);
        foreach ($services as $s) {
            $p = (float) ($paid[$s->id] ?? 0);
            $status = $p <= 0.009 ? self::UNPAID : ($p + 0.009 >= (float) $s->net ? self::PAID : self::PARTIAL);
            DB::table('t_service')->where('id', $s->id)->update(['invoice_status_id' => $status, 'updated_at' => now()]);
        }
    }

    /* =====================================================================
       Lookups
       ===================================================================== */

    /* GET /api/billing/lookups */
    public function lookups()
    {
        $feeCol = $this->pick('m_service_fee', ['service_fee', 'fee', 'amount']);
        $services = DB::table('m_service_list as L')
            ->leftJoin('m_service_fee as F', 'F.ser_list_id', '=', 'L.id')
            ->groupBy('L.id', 'L.name', 'L.ser_type_id')
            ->orderBy('L.name')
            ->get(['L.id', 'L.name', 'L.ser_type_id', DB::raw('MIN(F.id) AS fee_id'), DB::raw($feeCol ? "COALESCE(MIN(F.{$feeCol}), 0) AS fee" : '0 AS fee')]);

        $list = fn (string $t) => $this->hasTable($t) ? DB::table($t)->orderBy('id')->get(['id', 'name']) : [];

        return response()->json([
            'services'        => $services,
            'employees'       => DB::table('m_employee as E')->orderBy('E.last_name')->get(['E.id', DB::raw($this->employeeSql() . ' AS name')]),
            'payment_types'   => $list('m_payment_type'),
            'cheque_types'    => $list('m_payment_cheque_type'),
            'receipt_types'   => $list('m_payment_receipt_type'),
            'col_types'       => $this->hasTable('m_payment_col_type')
                ? DB::table('m_payment_col_type')->when(in_array('is_active', $this->cols('m_payment_col_type'), true), fn ($q) => $q->where('is_active', 1))->orderBy('id')->get(['id', 'name'])
                : [],
            'return_statuses' => $list('m_payment_return_status'),
            'invoice_statuses'=> DB::table('m_invoice_status')->orderBy('id')->get(['id', 'name']),
            'groups'          => collect(self::GROUPS)->map(fn ($g, $k) => ['key' => $k, 'label' => $g['label'], 'col_type' => $g['col_type']])->values(),
        ]);
    }

    /* GET /api/billing/clients */
    public function clients()
    {
        return response()->json(
            DB::table('m_client as C')->whereNotNull('C.client_num')
                ->orderByRaw($this->clientNameSql() . ' ASC')
                ->get(['C.client_num', DB::raw($this->clientNameSql() . ' AS name')])
                ->filter(fn ($c) => trim((string) $c->name) !== '')->values()
        );
    }

    /* GET /api/billing/clients/{clientNum}/accounts */
    public function accounts(string $clientNum)
    {
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);
        return response()->json(
            DB::table('m_client_account')->where('client_num', $clientNum)->orderBy('branch_code')
                ->get(['id', DB::raw($trade ? "{$trade} AS trade_name" : "'' AS trade_name"), 'branch_code'])
        );
    }

    /* GET /api/billing/accounts/{caid}   client account info + its service periods (SOAs) */
    public function account(int $caid)
    {
        $a = DB::table('m_client_account')->where('id', $caid)->first();
        if (!$a) return response()->json(['message' => 'Client account not found.'], 404);
        $c = DB::table('m_client')->where('client_num', $a->client_num)->first();

        $brgy = $this->firstTable(['m_barangay', 'm_brgy', 'm_barangays']);
        $city = $this->firstTable(['m_city', 'm_city_municipality', 'm_city_mun', 'm_cities']);
        $prov = $this->firstTable(['m_province', 'm_provinces']);
        $src = fn ($k) => $a->$k ?? $c->$k ?? null;

        $periods = DB::table('t_transaction')->where('ca_id', $caid)->whereNotNull('trans_number')
            ->orderByDesc('date_start')->orderByDesc('id')
            ->get(['id', 'trans_number', 'service_period', 'is_paid', 'ass_to_id']);

        return response()->json([
            'id' => $a->id,
            'tin' => $a->tin ?? $c->tin ?? '',
            'branch_type' => $a->branch_type ?? '',
            'branch_code' => $a->branch_code ?? '',
            'street' => (string) ($src('street') ?? ''),
            'barangay' => $this->placeName($brgy, $src('brgy_id')),
            'city' => $this->placeName($city, $src('city_id')),
            'province' => $this->placeName($prov, $src('province_id')),
            'periods' => $periods,
        ]);
    }

    /* =====================================================================
       All services / payments (with filters)
       ===================================================================== */

    /* GET /api/billing/account-list   "Client name — Trade name | Branch" for the filter */
    public function accountList()
    {
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);
        return response()->json(
            DB::table('m_client_account as A')
                ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
                ->get(['A.id', 'A.client_num', 'A.branch_code', DB::raw($trade ? "A.{$trade} AS trade_name" : "'' AS trade_name"),
                    DB::raw($this->clientNameSql() . ' AS client_name')])
                ->sortBy(fn ($a) => strtolower(trim($a->client_name . ' ' . $a->trade_name . ' ' . $a->branch_code)))
                ->values()
        );
    }

    /** filters shared by the service and payment lists */
    private function filters(Request $request): array
    {
        return $request->validate([
            'ca_id' => ['nullable', 'integer'],
            'client_num' => ['nullable', 'string', 'max:50'],          // "Client" view: every account of this client
            'service_period' => ['nullable', 'string', 'max:60'],      // "Client" view: one period across the accounts
            'groups' => ['nullable', 'string', 'max:40'],              // pf,eo,returns (any of them)
            'trans_number' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:unpaid,partial,paid,void,cancelled,open'],
            'q' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);
    }

    /** every account (m_client_account.id) of one client */
    private function clientCaids(string $clientNum): array
    {
        return DB::table('m_client_account')->where('client_num', $clientNum)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** "pf,eo" → the GROUPS picked (empty = all) */
    private function pickedGroups(?string $groups): array
    {
        $keys = array_filter(array_map('trim', explode(',', (string) $groups)));
        return array_values(array_intersect_key(self::GROUPS, array_flip($keys)));
    }

    /** the trans numbers of a client's accounts in one service period */
    private function periodTrans(array $caids, string $period)
    {
        return DB::table('t_transaction')->whereIn('ca_id', $caids ?: [0])->where('service_period', $period)->select('trans_number');
    }

    /* GET /api/billing/clients/{clientNum}/periods   service periods across all the client's accounts (newest first) */
    public function clientPeriods(string $clientNum)
    {
        $rows = DB::table('t_transaction')->whereIn('ca_id', $this->clientCaids($clientNum) ?: [0])->whereNotNull('service_period')
            ->groupBy('service_period')
            ->selectRaw('service_period, MAX(date_start) AS d, COUNT(*) AS soas, MIN(COALESCE(is_paid,0)) AS all_paid')
            ->orderByDesc('d')->get();
        return response()->json($rows->map(fn ($r) => ['service_period' => $r->service_period, 'soas' => (int) $r->soas, 'closed' => (bool) $r->all_paid])->values());
    }

    private function accountJoin($q, string $caCol): void
    {
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);
        $q->leftJoin('m_client_account as A', 'A.id', '=', $caCol)
            ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
            ->addSelect([DB::raw($trade ? "A.{$trade} AS trade_name" : "'' AS trade_name"), 'A.branch_code', DB::raw($this->clientNameSql() . ' AS client_name')]);
    }

    /* GET /api/billing/services?ca_id=&trans_number=&status=&q=&limit= */
    public function services(Request $request)
    {
        $f = $this->filters($request);
        $clientCa = !empty($f['client_num']) ? $this->clientCaids($f['client_num']) : null;
        // payments of THIS account / SOA only – summing every payment in the database made each load slow
        $paidSub = DB::table('t_payment')->whereNotNull('service_id')
            ->when(!empty($f['ca_id']), fn ($q) => $q->where('ca_id', $f['ca_id']))
            ->when($clientCa !== null, fn ($q) => $q->whereIn('ca_id', $clientCa ?: [0]))
            ->when(!empty($f['trans_number']), fn ($q) => $q->where('trans_number', $f['trans_number']))
            ->groupBy('service_id')
            ->selectRaw('service_id, SUM(amount) AS paid');

        $q = DB::table('t_service as S')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('m_invoice_status as I', 'I.id', '=', 'S.invoice_status_id')
            ->leftJoinSub($paidSub, 'PD', 'PD.service_id', '=', 'S.id')
            ->where('S.service_fee', '>', 0)
            ->select([
                'S.*',
                DB::raw("COALESCE(L.name, '') AS service"),
                'L.ser_type_id',
                DB::raw("COALESCE(I.name, '') AS status"),
                DB::raw($this->netSql() . ' AS net'),
                DB::raw('COALESCE(PD.paid, 0) AS paid')
            ]);


        $this->accountJoin($q, 'S.ca_id');

        if (!empty($f['ca_id'])) $q->where('S.ca_id', $f['ca_id']);
        if ($clientCa !== null) $q->whereIn('S.ca_id', $clientCa ?: [0]);
        if (!empty($f['service_period'])) $q->where('S.service_period', $f['service_period']);
        if (!empty($f['trans_number'])) $q->where('S.trans_number', $f['trans_number']);
        if ($g = $this->pickedGroups($f['groups'] ?? null)) $q->whereIn('L.ser_type_id', collect($g)->flatMap(fn ($x) => $x['ser_types'])->all());
        $st = $f['status'] ?? null;
        if ($st) {
            $map = ['paid' => [self::PAID], 'unpaid' => [self::UNPAID], 'partial' => [self::PARTIAL], 'void' => [self::VOID],
                'cancelled' => [self::CANCELLED], 'open' => [self::UNPAID, self::PARTIAL]];
            $q->whereIn('S.invoice_status_id', $map[$st]);
        }
        if (!empty($f['q'])) {
            $like = '%' . $f['q'] . '%';
            $q->where(fn ($w) => $w->where('L.name', 'like', $like)->orWhere('S.trans_number', 'like', $like)
                ->orWhere('S.service_period', 'like', $like)->orWhereRaw($this->clientNameSql() . ' LIKE ?', [$like]));
        }

        $limit = (int) ($f['limit'] ?? 500);
        $rows = $q->orderByDesc('S.prep_target_date')->orderByDesc('S.id')->limit($limit + 1)->get();
        $logCounts = $this->hasTable('t_service_override_log')
            ? DB::table('t_service_override_log')->whereIn('service_id', $rows->pluck('id'))->groupBy('service_id')->selectRaw('service_id, COUNT(*) AS n')->pluck('n', 'service_id')
            : collect();

        return response()->json([
            'rows' => $rows->take($limit)->map(function ($s) use ($logCounts) {
                foreach (['service_fee', 'qty', 'less', 'surcharge', 'total_service_fee', 'inherited_vat', 'inherited_wh', 'inherited_discount_amount', 'less_other_percentage', 'net', 'paid'] as $k) {
                    $s->$k = round((float) ($s->$k ?? 0), 2);
                }
                $dead = in_array((int) $s->invoice_status_id, [self::VOID, self::CANCELLED], true);
                $s->balance = $dead ? 0 : round(max(0, $s->net - $s->paid), 2);
                $s->prep_target_date = $s->prep_target_date ? substr((string) $s->prep_target_date, 0, 10) : null;
                $s->logs = (int) ($logCounts[$s->id] ?? 0);
                return $s;
            })->values(),
            'more' => $rows->count() > $limit,
        ]);
    }

    /* GET /api/billing/payments?ca_id=&trans_number=&q=&limit= */
    public function payments(Request $request)
    {
        $f = $this->filters($request);
        $q = DB::table('t_payment as P')
            ->leftJoin('t_service as S', 'S.id', '=', 'P.service_id')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('m_payment_type as PT', 'PT.id', '=', 'P.payment_type_id')
            ->leftJoin('m_payment_cheque_type as CT', 'CT.id', '=', 'P.cheque_type_id')
            ->leftJoin('m_payment_col_type as COL', 'COL.id', '=', 'P.payment_col_type_id')
            ->leftJoin('m_payment_receipt_type as RT', 'RT.id', '=', 'P.receipt_type_id')
            ->select(['P.*', DB::raw("COALESCE(L.name, 'Unassigned') AS service"), DB::raw('PT.name AS payment_type'),
                DB::raw('CT.name AS cheque_type'), DB::raw('COL.name AS col_type'), DB::raw('RT.name AS receipt_type'),
                DB::raw('S.return_status_id'), DB::raw('S.is_paid AS service_is_paid')]);
        $this->accountJoin($q, 'P.ca_id');

        if (!empty($f['ca_id'])) $q->where('P.ca_id', $f['ca_id']);
        $clientCa = !empty($f['client_num']) ? $this->clientCaids($f['client_num']) : null;
        if ($clientCa !== null) $q->whereIn('P.ca_id', $clientCa ?: [0]);
        if (!empty($f['service_period'])) $q->whereIn('P.trans_number', $this->periodTrans($clientCa ?? array_filter([(int) ($f['ca_id'] ?? 0)]), $f['service_period']));
        if (!empty($f['trans_number'])) $q->where('P.trans_number', $f['trans_number']);
        if ($g = $this->pickedGroups($f['groups'] ?? null)) $q->whereIn('P.payment_col_type_id', array_column($g, 'col_type'));
        if (!empty($f['q'])) {
            $like = '%' . $f['q'] . '%';
            $q->where(fn ($w) => $w->where('L.name', 'like', $like)->orWhere('P.trans_number', 'like', $like)
                ->orWhere('P.ar_number', 'like', $like)->orWhere('P.or_number', 'like', $like)->orWhere('P.details2', 'like', $like)
                ->orWhereRaw($this->clientNameSql() . ' LIKE ?', [$like]));
        }

        $limit = (int) ($f['limit'] ?? 500);
        $rows = $q->orderByDesc('P.payment_date')->orderByDesc('P.id')->limit($limit + 1)->get();
        return response()->json([
            'rows' => $rows->take($limit)->map(function ($p) {
                $p->amount = round((float) $p->amount, 2);
                $p->payment_date = $p->payment_date ? substr((string) $p->payment_date, 0, 10) : null;
                $p->cheque_type_date = $p->cheque_type_date ? substr((string) $p->cheque_type_date, 0, 10) : null;
                $p->receipt_no = match ((int) $p->receipt_type_id) { 2 => $p->ap_number, 3 => $p->or_number, default => $p->ar_number };
                return $p;
            })->values(),
            'more' => $rows->count() > $limit,
        ]);
    }

    /* =====================================================================
       SOA
       ===================================================================== */

    /** PF / Returns / Non-recurring: bill − payments, for one SOA, one account, or nothing (zeros) */
    private function groupTotals(?int $caid, ?string $transNumber, ?array $caids = null, ?string $period = null): array
    {
        $bills = collect();
        $paid = collect();
        if ($caid || $transNumber || $caids !== null) {
            $bills = DB::table('t_service as S')->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
                ->when($caid, fn ($q) => $q->where('S.ca_id', $caid))
                ->when($caids !== null, fn ($q) => $q->whereIn('S.ca_id', $caids ?: [0]))
                ->when($period, fn ($q) => $q->where('S.service_period', $period))
                ->when($transNumber, fn ($q) => $q->where('S.trans_number', $transNumber))
                ->whereNotIn(DB::raw('COALESCE(S.invoice_status_id, 0)'), [self::VOID, self::CANCELLED])
                ->groupBy('L.ser_type_id')->selectRaw('L.ser_type_id AS t, SUM(' . $this->netSql() . ') AS amt')->pluck('amt', 't');
            $paid = DB::table('t_payment')
                ->when($caid, fn ($q) => $q->where('ca_id', $caid))
                ->when($caids !== null, fn ($q) => $q->whereIn('ca_id', $caids ?: [0]))
                ->when($period, fn ($q) => $q->whereIn('trans_number', $this->periodTrans($caids ?? array_filter([(int) $caid]), $period)))
                ->when($transNumber, fn ($q) => $q->where('trans_number', $transNumber))
                ->groupBy('payment_col_type_id')->selectRaw('payment_col_type_id AS t, SUM(amount) AS amt')->pluck('amt', 't');
        }
        $out = [];
        foreach (self::GROUPS as $k => $g) {
            $bill = collect($g['ser_types'])->sum(fn ($t) => (float) ($bills[$t] ?? 0));
            $pay = (float) ($paid[$g['col_type']] ?? 0);
            $out[$k] = ['label' => $g['label'], 'bill' => round($bill, 2), 'paid' => round($pay, 2), 'balance' => round($bill - $pay, 2)];
        }
        return $out;
    }

    /* GET /api/billing/totals?ca_id=&trans_number=   (always shown on the page) */
    public function totals(Request $request)
    {
        $d = $request->validate(['ca_id' => ['nullable', 'integer'], 'trans_number' => ['nullable', 'string', 'max:60'],
            'client_num' => ['nullable', 'string', 'max:50'], 'service_period' => ['nullable', 'string', 'max:60']]);
        $caids = !empty($d['client_num']) ? $this->clientCaids($d['client_num']) : null;
        return response()->json($this->groupTotals($d['ca_id'] ?? null, $d['trans_number'] ?? null, $caids, $d['service_period'] ?? null));
    }

    /* GET /api/billing/soa/{transNumber} */
    public function soa(string $transNumber)
    {
        $t = DB::table('t_transaction')->where('trans_number', $transNumber)->first();
        if (!$t) return response()->json(['message' => 'SOA not found.'], 404);

        $logCounts = $this->hasTable('t_service_override_log')
            ? DB::table('t_service_override_log')
                ->whereIn('service_id', DB::table('t_service')->where('trans_number', $transNumber)->select('id'))
                ->groupBy('service_id')->selectRaw('service_id, COUNT(*) AS n')->pluck('n', 'service_id')
            : collect();

        $services = DB::table('t_service as S')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('m_invoice_status as I', 'I.id', '=', 'S.invoice_status_id')
            ->where('S.trans_number', $transNumber)->orderBy('S.id')
            ->get(['S.*', DB::raw("COALESCE(L.name, '') AS service"), 'L.ser_type_id', DB::raw("COALESCE(I.name, '') AS status"),
                DB::raw($this->netSql() . ' AS net')])
            ->map(function ($s) use ($logCounts) {
                $s->logs = (int) ($logCounts[$s->id] ?? 0);
                foreach (['service_fee', 'qty', 'less', 'surcharge', 'total_service_fee', 'inherited_vat', 'inherited_wh', 'inherited_discount_amount', 'less_other_percentage', 'net'] as $k) {
                    $s->$k = round((float) ($s->$k ?? 0), 2);
                }
                $s->prep_target_date = $s->prep_target_date ? substr((string) $s->prep_target_date, 0, 10) : null;
                return $s;
            });

        $payments = DB::table('t_payment as P')
            ->leftJoin('t_service as S', 'S.id', '=', 'P.service_id')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('m_payment_type as PT', 'PT.id', '=', 'P.payment_type_id')
            ->leftJoin('m_payment_cheque_type as CT', 'CT.id', '=', 'P.cheque_type_id')
            ->leftJoin('m_payment_col_type as COL', 'COL.id', '=', 'P.payment_col_type_id')
            ->leftJoin('m_payment_receipt_type as RT', 'RT.id', '=', 'P.receipt_type_id')
            ->where('P.trans_number', $transNumber)->orderBy('P.id')
            ->get(['P.*', DB::raw("COALESCE(L.name, 'Unassigned') AS service"), DB::raw('PT.name AS payment_type'),
                DB::raw('CT.name AS cheque_type'), DB::raw('COL.name AS col_type'), DB::raw('RT.name AS receipt_type'),
                DB::raw('S.return_status_id'), DB::raw('S.is_paid AS service_is_paid')])
            ->map(function ($p) {
                $p->amount = round((float) $p->amount, 2);
                $p->payment_date = $p->payment_date ? substr((string) $p->payment_date, 0, 10) : null;
                $p->cheque_type_date = $p->cheque_type_date ? substr((string) $p->cheque_type_date, 0, 10) : null;
                $p->receipt_no = match ((int) $p->receipt_type_id) { 2 => $p->ap_number, 3 => $p->or_number, default => $p->ar_number };
                return $p;
            });

        // PF / Returns / Non-recurring: bill − payments
        $totals = [];
        foreach (self::GROUPS as $k => $g) {
            $bill = $services->filter(fn ($s) => in_array((int) $s->ser_type_id, $g['ser_types'], true)
                && !in_array((int) $s->invoice_status_id, [self::VOID, self::CANCELLED], true))->sum('net');
            $paid = $payments->where('payment_col_type_id', $g['col_type'])->sum('amount');
            $totals[$k] = ['label' => $g['label'], 'bill' => round($bill, 2), 'paid' => round($paid, 2), 'balance' => round($bill - $paid, 2)];
        }

        return response()->json([
            'transaction' => [
                'id' => $t->id, 'trans_number' => $t->trans_number, 'service_period' => $t->service_period,
                'ca_id' => $t->ca_id, 'ass_to_id' => $t->ass_to_id, 'is_paid' => (bool) $t->is_paid, 'is_lock' => (bool) ($t->is_lock ?? false),
            ],
            'services' => $services->values(),
            'payments' => $payments->values(),
            'totals' => $totals,
        ]);
    }

    /* PUT /api/billing/soa/{transNumber}/fda   { ass_to_id } */
    public function setFda(Request $request, string $transNumber)
    {
        $d = $request->validate(['ass_to_id' => ['nullable', 'integer']]);
        $n = DB::table('t_transaction')->where('trans_number', $transNumber)->update(['ass_to_id' => $d['ass_to_id'] ?: null, 'updated_at' => now()]);
        return $n ? response()->json(['message' => 'FDA In-Charge updated']) : response()->json(['message' => 'SOA not found.'], 404);
    }

    /* POST /api/billing/soa/{transNumber}/close   only when every balance is 0 */
    public function close(string $transNumber)
    {
        $soa = $this->soa($transNumber)->getData(true);
        if (isset($soa['message'])) return response()->json($soa, 404);
        foreach ($soa['totals'] as $g) {
            if ($g['balance'] > 0.009) return response()->json(['message' => 'The SOA can\'t be closed while it has a remaining balance.'], 422);
        }
        DB::table('t_transaction')->where('trans_number', $transNumber)->update(['is_paid' => 1, 'updated_at' => now()]);
        return response()->json(['message' => 'SOA closed']);
    }

    /* =====================================================================
       Print (old merge.php: SOA.odt / CSOA.odt with ReportType)
       GET /api/billing/soa/{transNumber}/print/{type}?service_id=
       type: SOA | C_SOA | PT_SOA | EO_SOA | C_EO_SOA | PT_EO_SOA | C_RETURN | SOA2 (one service)
       ===================================================================== */
    const PRINT_TYPES = ['SOA', 'C_SOA', 'PT_SOA', 'EO_SOA', 'C_EO_SOA', 'PT_EO_SOA', 'C_RETURN', 'SOA2'];

    public function printSoa(Request $request, string $transNumber, string $type)
    {
        $type = strtoupper($type);
        if (!in_array($type, self::PRINT_TYPES, true)) return response()->json(['message' => 'Unknown report.'], 404);
        if (!DB::table('t_transaction')->where('trans_number', $transNumber)->exists()) {
            return response()->json(['message' => 'Please select transaction'], 404);
        }
        if ($type === 'SOA2' && !$request->integer('service_id')) {
            return response()->json(['message' => 'Pick the service to print.'], 422);
        }

        // C_SOA uses CSOA.odt, everything else SOA.odt (same as the old forms)
        $template = resource_path('templates/' . ($type === 'C_SOA' ? 'CSOA.odt' : 'SOA.odt'));
        if (!is_file($template)) {
            return response()->json(['message' => "The {$type} template isn't set up yet. Add " . basename($template)
                . ' to resources/templates and the SOA part of merge.php so the fields can be filled.'], 501);
        }
        return response()->json(['message' => "{$type} printing is waiting for the field mapping from merge.php."], 501);
    }

    /* =====================================================================
       Services
       ===================================================================== */

    /* POST /api/billing/soa/{transNumber}/services */
    public function addService(Request $request, string $transNumber)
    {
        return $this->saveService($request, $transNumber, null);
    }

    /* PUT /api/billing/services/{id}   (changing the unit price needs a supervisor) */
    public function updateService(Request $request, int $id)
    {
        return $this->saveService($request, null, $id);
    }

    private function saveService(Request $request, ?string $transNumber, ?int $id, bool $skipOverride = false)
    {
        $d = $request->validate([
            'ser_list_id' => ['required', 'integer'],
            'ser_fee_id' => ['nullable', 'integer'],
            'prep_target_date' => ['required', 'date'],
            'service_fee' => ['required', 'numeric', 'gt:0'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'less' => ['nullable', 'numeric', 'min:0'],
            'surcharge' => ['nullable', 'numeric', 'min:0'],          // percent
            'remarks' => ['nullable', 'string', 'max:1000'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
        ], ['ser_list_id.required' => 'Please select service', 'service_fee.gt' => 'Please input unit price']);

        $existing = $id ? DB::table('t_service')->where('id', $id)->first() : null;
        if ($id && !$existing) return response()->json(['message' => 'Service not found.'], 404);
        if ($existing && in_array((int) $existing->invoice_status_id, [self::VOID, self::CANCELLED], true)) {
            return response()->json(['message' => 'This service is void / cancelled.'], 422);
        }
        $transNumber = $existing->trans_number ?? $transNumber;
        $t = DB::table('t_transaction')->where('trans_number', $transNumber)->first();
        if (!$t) return response()->json(['message' => 'SOA not found.'], 404);

        // price changed on a saved service → supervisor override (old pendingServiceOverride)
        $priceChanged = !$skipOverride && $existing && abs((float) $existing->service_fee - (float) $d['service_fee']) > 0.004;
        $sup = null;
        if ($priceChanged) {
            $sup = $this->supervisor($d['username'] ?? null, $d['password'] ?? null);
            if (!$sup) return response()->json(['message' => 'Changing the unit price needs a supervisor.', 'needs_override' => true], 422);
        }

        $gross = round((float) $d['service_fee'] * (float) $d['qty'], 2);
        $total = round($gross - (float) ($d['less'] ?? 0) + $gross * ((float) ($d['surcharge'] ?? 0) / 100), 2);

        // keep VAT / withholding / discount in step with the new total
        $flags = $existing ?: (object) [];
        [$vat, $wh, $disc] = $this->inherited($total, $flags);

        $row = [
            'trans_number' => $transNumber, 'service_period' => $t->service_period, 'ca_id' => $t->ca_id,
            'eo_num' => $t->eo_num1 ?? $t->eo_num ?? $t->eo_number ?? null,
            'ser_list_id' => $d['ser_list_id'], 'ser_fee_id' => $d['ser_fee_id'] ?? null, 'prep_target_date' => $d['prep_target_date'],
            'service_fee' => round((float) $d['service_fee'], 2), 'qty' => $d['qty'], 'less' => round((float) ($d['less'] ?? 0), 2),
            'surcharge' => round((float) ($d['surcharge'] ?? 0), 2), 'total_service_fee' => $total,
            'inherited_vat' => $vat, 'inherited_wh' => $wh, 'inherited_discount_amount' => $disc, 'updated_at' => now(),
        ];

        DB::transaction(function () use ($request, $existing, $row, $sup, $d, $transNumber) {
            if ($existing) {
                DB::table('t_service')->where('id', $existing->id)->update($this->only('t_service', $row));
                if ($sup) DB::table('t_service_override_log')->insert($this->only('t_service_override_log', [
                    'service_id' => $existing->id, 'action' => 'PRICE',
                    'remarks' => trim(($d['remarks'] ?? '') . ' (unit price ' . number_format((float) $existing->service_fee, 2) . ' → ' . number_format((float) $d['service_fee'], 2) . ')'),
                    'override_by' => $this->me($request), 'approved_by' => $sup->id, 'created_at' => now(), 'updated_at' => now(),
                ]));
            } else {
                DB::table('t_service')->insert($this->only('t_service', $row + ['invoice_status_id' => self::UNPAID, 'created_at' => now()]));
            }
            $this->settle($transNumber);
        });

        return response()->json(['message' => 'Service successfully saved']);
    }

    /** VAT 12% · withholding 5% · discount SC 20% / SP 10% / PWD 20% / others n% of (fee + VAT − WH) */
    private function inherited(float $fee, object $f): array
    {
        $vat = !empty($f->d_vat) ? round($fee * 0.12, 2) : 0;
        $wh = !empty($f->d_witholding) ? round($fee * 0.05, 2) : 0;
        $base = $fee + $vat - $wh;
        $pct = !empty($f->d_less_sc) || !empty($f->d_less_pwd) ? 0.20 : (!empty($f->d_less_sp) ? 0.10
            : (!empty($f->d_less_others) ? ((float) ($f->less_other_percentage ?? 0)) / 100 : 0));
        return [$vat, $wh, round($base * $pct, 2)];
    }

    /* PUT /api/billing/services/{id}/options   VAT / withholding / discount / note */
    public function saveOptions(Request $request, int $id)
    {
        $d = $request->validate([
            'd_vat' => ['boolean'], 'd_witholding' => ['boolean'],
            'discount' => ['nullable', 'in:SC,SP,PWD,Others'],
            'less_other_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $s = DB::table('t_service')->where('id', $id)->first();
        if (!$s) return response()->json(['message' => 'Service not found.'], 404);
        // if (in_array((int) $s->invoice_status_id, [self::PAID, self::VOID, self::CANCELLED], true)) {
        //     return response()->json(['message' => 'Options can\'t change on a paid, void or cancelled service.'], 422);
        // }

        $flags = (object) [
            'd_vat' => (int) ($d['d_vat'] ?? 0), 'd_witholding' => (int) ($d['d_witholding'] ?? 0),
            'd_less_sc' => (int) (($d['discount'] ?? '') === 'SC'), 'd_less_sp' => (int) (($d['discount'] ?? '') === 'SP'),
            'd_less_pwd' => (int) (($d['discount'] ?? '') === 'PWD'), 'd_less_others' => (int) (($d['discount'] ?? '') === 'Others'),
            'less_other_percentage' => ($d['discount'] ?? '') === 'Others' ? (float) ($d['less_other_percentage'] ?? 0) : 0,
        ];
        [$vat, $wh, $disc] = $this->inherited((float) $s->total_service_fee, $flags);

        DB::transaction(function () use ($s, $flags, $vat, $wh, $disc, $d) {
            DB::table('t_service')->where('id', $s->id)->update($this->only('t_service', (array) $flags + [
                'inherited_vat' => $vat, 'inherited_wh' => $wh, 'inherited_discount_amount' => $disc,
                'note' => $d['note'] ?? null, 'updated_at' => now(),
            ]));
            $this->settle($s->trans_number);
        });
        return response()->json(['message' => 'Record successfully updated']);
    }

    /* POST /api/billing/services/void-cancel   { type: void|cancel, ids[], remarks, username, password } */
    public function voidCancel(Request $request)
    {
        $d = $request->validate([
            'type' => ['required', 'in:void,cancel'], 'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'username' => ['required', 'string'], 'password' => ['required', 'string'],
        ], ['username.required' => 'Username is required', 'password.required' => 'Password is required']);

        $sup = $this->supervisor($d['username'], $d['password']);
        if (!$sup) return response()->json(['message' => 'User not found'], 422);

        $services = DB::table('t_service')->whereIn('id', $d['ids'])->get();
        if ($services->isEmpty()) return response()->json(['message' => 'No services found.'], 404);
        $status = $d['type'] === 'void' ? self::VOID : self::CANCELLED;

        DB::transaction(function () use ($request, $services, $status, $d, $sup) {
            foreach ($services as $s) {
                DB::table('t_service')->where('id', $s->id)->update(['invoice_status_id' => $status, 'updated_at' => now()]);
                DB::table('t_service_override_log')->insert($this->only('t_service_override_log', [
                    'service_id' => $s->id, 'action' => strtoupper($d['type']), 'remarks' => $d['remarks'] ?? null,
                    'override_by' => $this->me($request), 'approved_by' => $sup->id, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        });
        return response()->json(['message' => $d['type'] === 'void' ? 'Transaction voided.' : 'Transaction cancelled.']);
    }

    /* GET /api/billing/services/{id}/logs */
    public function serviceLogs(int $id)
    {
        if (!$this->hasTable('t_service_override_log')) return response()->json([]);
        return response()->json(
            DB::table('t_service_override_log as A')
                ->leftJoin('m_employee as E', 'E.id', '=', 'A.override_by')
                ->leftJoin('m_user_staff as U', 'U.id', '=', 'A.approved_by')
                ->where('A.service_id', $id)->orderByDesc('A.id')
                ->get(array_filter([
                    'A.id', 'A.remarks', 'A.created_at',
                    in_array('action', $this->cols('t_service_override_log'), true) ? 'A.action' : null,
                    DB::raw($this->employeeSql() . ' AS employee'),
                    in_array('approved_by', $this->cols('t_service_override_log'), true) ? DB::raw('U.username AS approved_by') : null,
                ]))
        );
    }

    /* =====================================================================
       Payments
       ===================================================================== */

    /* POST /api/billing/soa/{transNumber}/payments */
    public function addPayment(Request $request, string $transNumber)
    {
        return $this->savePayment($request, $transNumber, null);
    }

    /* PUT /api/billing/payments/{id} */
    public function updatePayment(Request $request, int $id)
    {
        return $this->savePayment($request, null, $id);
    }

    private function savePayment(Request $request, ?string $transNumber, ?int $id)
    {
        $d = $request->validate([
            'service_id' => ['required', 'integer', 'min:1'],
            'payment_date' => ['required', 'date'],
            'payment_type_id' => ['required', 'integer', 'min:1'],
            'cheque_type_id' => ['nullable', 'integer'],
            'cheque_type_date' => ['nullable', 'date'],
            'details1' => ['nullable', 'string', 'max:150'],
            'details2' => ['nullable', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_col_type_id' => ['required', 'integer', 'min:1'],
            'receipt_type_id' => ['nullable', 'integer'],
            'receipt_no' => ['nullable', 'string', 'max:60'],
        ], [
            'service_id.required' => 'Service is required', 'service_id.min' => 'Service is required',
            'payment_type_id.min' => 'Please select payment method', 'payment_col_type_id.min' => 'Please select payment col type',
        ]);
        foreach (['payment_type_id' => ['m_payment_type', 'Payment method'], 'payment_col_type_id' => ['m_payment_col_type', 'Collection type'],
                  'receipt_type_id' => ['m_payment_receipt_type', 'Receipt type'], 'cheque_type_id' => ['m_payment_cheque_type', 'Cheque type']] as $f => [$t, $label]) {
            if (!empty($d[$f]) && $this->hasTable($t) && !DB::table($t)->where('id', $d[$f])->exists()) {
                return response()->json(['message' => "{$label} #{$d[$f]} doesn't exist in {$t}."], 422);
            }
        }
        $existing = $id ? DB::table('t_payment')->where('id', $id)->first() : null;
        if ($id && !$existing) return response()->json(['message' => 'Payment not found.'], 404);
        $transNumber = $existing->trans_number ?? $transNumber;
        $t = DB::table('t_transaction')->where('trans_number', $transNumber)->first();
        if (!$t) return response()->json(['message' => 'SOA not found.'], 404);
        if (!DB::table('t_service')->where('id', $d['service_id'])->where('trans_number', $transNumber)->exists()) {
            return response()->json(['message' => 'That service is not on this SOA.'], 422);
        }

        $cheque = (int) $d['payment_type_id'] === 2;    // old: PaymentTypeID 2 = cheque
        $rt = (int) ($d['receipt_type_id'] ?? 0);
        $no = $d['receipt_no'] ?? null;
        $amount = round((float) $d['amount'], 2);
        $row = [
            'trans_number' => $transNumber, 'service_period' => $t->service_period, 'ca_id' => $t->ca_id,
            'client_num' => DB::table('m_client_account')->where('id', $t->ca_id)->value('client_num'),
            'service_id' => $d['service_id'], 'payment_date' => $d['payment_date'], 'payment_type_id' => $d['payment_type_id'],
            'cheque_type_id' => $cheque ? ($d['cheque_type_id'] ?: null) : null, 'cheque_type_date' => $cheque ? ($d['cheque_type_date'] ?? null) : null,
            'details1' => $d['details1'] ?? null, 'details2' => $d['details2'] ?? null,
            'payment_col_type_id' => $d['payment_col_type_id'], 'receipt_type_id' => $rt ?: null,
            'ar_number' => in_array($rt, [1, 4], true) ? $no : null, 'ap_number' => $rt === 2 ? $no : null, 'or_number' => $rt === 3 ? $no : null,
            'amount' => $amount, 'total_gross_amount_cal' => $amount - (float) ($existing->co_amount ?? 0),
            'total_amount' => $amount - (float) ($existing->co_amount ?? 0), 'qty' => 1, 'company_id' => 1,
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($request, $existing, $row, $transNumber) {
            if ($existing) {
                DB::table('t_payment')->where('id', $existing->id)->update($this->only('t_payment', $row));
            } else {
                DB::table('t_payment')->insert($this->only('t_payment', $row + [
                    'user_id' => $this->me($request), 'deposited_by_id' => $this->me($request), 'co_amount' => 0, 'created_at' => now(),
                ]));
            }
            $this->settle($transNumber);
        });

        return response()->json(['message' => 'Payment successfully saved!']);
    }

    /* DELETE /api/billing/payments/{id} */
    public function deletePayment(int $id)
    {
        $p = DB::table('t_payment')->where('id', $id)->first();
        if (!$p) return response()->json(['message' => 'Payment not found.'], 404);
        if ((int) ($p->is_deposited ?? 0) === 1) return response()->json(['message' => 'This payment is already deposited.'], 422);
        DB::transaction(function () use ($p) {
            DB::table('t_payment')->where('id', $p->id)->delete();
            $this->settle($p->trans_number);
        });
        return response()->json(['message' => 'Payment removed']);
    }

    /**
     * PUT /api/billing/payments/{id}/return   (old Itemized Returns)
     * { total, return_status_id, is_paid } → updates the service; a paid return (status ≠ 1) is marked deposited
     */
    public function saveReturn(Request $request, int $id)
    {
        $d = $request->validate([
            'total' => ['required', 'numeric', 'min:0'],
            'return_status_id' => ['nullable', 'integer'],
            'is_paid' => ['boolean'],
        ]);
        $p = DB::table('t_payment')->where('id', $id)->first();
        if (!$p) return response()->json(['message' => 'Payment not found.'], 404);
        if (!$p->service_id) return response()->json(['message' => 'Please update service in payment first'], 422);
        if ((int) $p->payment_col_type_id !== 2) return response()->json(['message' => 'Available only for return payments'], 422);

        $paid = (bool) ($d['is_paid'] ?? false);
        $status = (int) ($d['return_status_id'] ?? 0);
        $me = $this->me($request);
        $date = substr((string) $p->payment_date, 0, 10) ?: now()->toDateString();

        DB::transaction(function () use ($p, $d, $paid, $status, $me, $date) {
            DB::table('t_service')->where('id', $p->service_id)->update($this->only('t_service', [
                'is_paid' => $paid ? 1 : 0, 'total_service_fee' => round((float) $d['total'], 2),
                'return_status_id' => $status ?: null, 'updated_at' => now(),
            ]));
            $deposit = ($status !== 1 && $paid)
                ? ['assigned_bank' => 8, 'assigned_by' => $me, 'assigned_bank_date' => $date,
                    'dep_slip_number' => (string) ((int) DB::table('t_payment')->max(DB::raw('CAST(dep_slip_number AS UNSIGNED)')) + 1),
                    'prepared_by_id' => $me, 'prepared_date' => $date, 'is_prepared' => 1, 'is_deposited' => 1,
                    'payment_status' => 3, 'deposited_by_id' => $me, 'deposited_date' => $date]
                : ['assigned_bank' => 0, 'assigned_by' => null, 'assigned_bank_date' => null, 'dep_slip_number' => null,
                    'prepared_by_id' => null, 'prepared_date' => null, 'is_prepared' => 0, 'is_deposited' => 0,
                    'payment_status' => 1, 'deposited_by_id' => $me, 'deposited_date' => null];
            DB::table('t_payment')->where('id', $p->id)->update($this->only('t_payment', $deposit + ['updated_at' => now()]));
            $this->settle($p->trans_number);
        });
        return response()->json(['message' => 'Record successfully updated']);
    }

    /* =====================================================================
       SERVICE LIST (old Billing/Service_List: tbl_service_list + tbl_service_fee)
       ===================================================================== */

    /** lookup tables of m_service_list: [field => [table candidates, name column candidates]] */
    private const SL_LOOKUPS = [
        'item_tax_type_id' => [['m_item_tax_type', 'm_item_tax_types'], ['name', 'item_tax_type', 'tax_type']],
        'item_tax_desc_id' => [['m_item_tax_type_desc', 'm_item_tax_desc', 'm_item_tax_type_descs'], ['name', 'item_tax_desc', 'description']],
        'ser_type_id'      => [['m_service_type', 'm_service_types'], ['name', 'service_type']],
        'rep_type_id'      => [['m_reporting_type', 'm_reporting_types'], ['name', 'reporting_type']],
        'submission_id'    => [['m_submission', 'm_submissions'], ['name', 'submission']],
        'institution_id'   => [['m_institution', 'm_institutions'], ['institution_abb', 'abbreviation', 'abb', 'name', 'institution']],
        'trans_type_id'    => [['m_transaction_type', 'm_transaction_types'], ['name', 'transaction_type']],
    ];

    /** [table, name column] of a lookup, or [null, null] */
    private function slLookup(string $field): array
    {
        [$tables, $names] = self::SL_LOOKUPS[$field];
        $table = $this->firstTable($tables);
        return [$table, $table ? $this->pick($table, $names) : null];
    }

    private function feeCol(): ?string
    {
        return $this->pick('m_service_fee', ['service_fee', 'fee', 'amount']);
    }

    /* GET /api/billing/service-list/lookups */
    public function serviceListLookups()
    {
        $out = [];
        foreach (array_keys(self::SL_LOOKUPS) as $field) {
            [$t, $n] = $this->slLookup($field);
            $out[$field] = $t && $n ? DB::table($t)->orderBy($n)->get(['id', DB::raw("{$n} AS name")]) : [];
        }
        return response()->json($out);
    }

    /* GET /api/billing/service-list */
    public function serviceList()
    {
        $fee = $this->feeCol();
        $q = DB::table('m_service_list as L')
            ->leftJoin('m_service_fee as F', 'F.id', '=', DB::raw('(SELECT MIN(F2.id) FROM m_service_fee F2 WHERE F2.ser_list_id = L.id)'))
            ->select(['L.*', DB::raw('F.id AS ser_fee_id'), DB::raw($fee ? "COALESCE(F.{$fee}, 0) AS service_fee" : '0 AS service_fee'),
                DB::raw(in_array('is_active', $this->cols('m_service_fee'), true) ? 'COALESCE(F.is_active, 0) AS is_active' : '1 AS is_active')]);
        foreach (array_keys(self::SL_LOOKUPS) as $i => $field) {
            [$t, $n] = $this->slLookup($field);
            $alias = "LK{$i}";
            $label = preg_replace('/_id$/', '', $field) . '_name';
            if ($t && $n && in_array($field, $this->cols('m_service_list'), true)) {
                $q->leftJoin("{$t} as {$alias}", "{$alias}.id", '=', "L.{$field}")->addSelect(DB::raw("{$alias}.{$n} AS {$label}"));
            } else {
                $q->addSelect(DB::raw("NULL AS {$label}"));
            }
        }
        return response()->json($q->orderBy('L.name')->get()->map(function ($r) {
            $r->service_fee = round((float) $r->service_fee, 2);
            $r->is_active = (int) $r->is_active;
            return $r;
        }));
    }

    /**
     * POST /api/billing/service-list        add
     * PUT  /api/billing/service-list/{id}   edit
     * saves m_service_list, then its fee row in m_service_fee (old SAVE_SERVICE_LIST + SAVE_SERVICE_FEE)
     */
    public function addServiceList(Request $request)
    {
        return $this->saveServiceList($request, null);
    }

    public function updateServiceList(Request $request, int $id)
    {
        return $this->saveServiceList($request, $id);
    }

    private function saveServiceList(Request $request, ?int $id)
    {
        $rules = ['name' => ['required', 'string', 'max:255'], 'ser_type_id' => ['required', 'integer', 'min:1'],
            'service_fee' => ['nullable', 'numeric', 'min:0'], 'is_active' => ['boolean']];
        foreach (array_keys(self::SL_LOOKUPS) as $f) if ($f !== 'ser_type_id') $rules[$f] = ['nullable', 'integer'];
        $d = $request->validate($rules, [
            'name.required' => 'Service is required', 'ser_type_id.required' => 'Service Type is required', 'ser_type_id.min' => 'Service Type is required',
        ]);

        if ($id && !DB::table('m_service_list')->where('id', $id)->exists()) return response()->json(['message' => 'Service not found.'], 404);
        $dup = DB::table('m_service_list')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($d['name']))])
            ->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($dup) return response()->json(['message' => 'That service already exists.'], 422);

        $row = ['name' => trim($d['name'])];
        foreach (array_keys(self::SL_LOOKUPS) as $f) $row[$f] = !empty($d[$f]) ? (int) $d[$f] : null;
        $fee = $this->feeCol();

        DB::transaction(function () use (&$id, $row, $d, $fee) {
            if ($id) {
                DB::table('m_service_list')->where('id', $id)->update($this->only('m_service_list', $row + ['updated_at' => now()]));
            } else {
                $id = DB::table('m_service_list')->insertGetId($this->only('m_service_list', $row + ['created_at' => now(), 'updated_at' => now()]));
            }
            $feeRow = array_filter([
                $fee => round((float) ($d['service_fee'] ?? 0), 2),
                'is_active' => !empty($d['is_active']) ? 1 : 0,
                'ser_list_id' => $id,
                'updated_at' => now(),
            ], fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH);
            $feeId = DB::table('m_service_fee')->where('ser_list_id', $id)->min('id');
            if ($feeId) DB::table('m_service_fee')->where('id', $feeId)->update($this->only('m_service_fee', $feeRow));
            else DB::table('m_service_fee')->insert($this->only('m_service_fee', $feeRow + ['created_at' => now()]));
        });

        return response()->json(['id' => $id, 'message' => 'Service successfully saved']);
    }

    /* =====================================================================
       RECURRING SERVICE (t_recurring_service): services billed to an account every period
       ===================================================================== */

    /**
     * GET /api/billing/recurring?ca_id=&eo_version=
     * no ca_id → every account (its latest EO version), amount > 0 only
     * ca_id    → that account's rows for the EO version, amount > 0 only
     */
    public function recurring(Request $request)
    {
        $d = $request->validate(['ca_id' => ['nullable', 'integer'], 'eo_version' => ['nullable', 'integer']]);
        $caid = $d['ca_id'] ?? null;
        $versions = collect();
        $version = null;
        if ($caid) {
            $versions = DB::table('t_recurring_service')->where('ca_id', $caid)->distinct()->orderByDesc('eo_version')->pluck('eo_version')->map(fn ($v) => (int) $v)->values();
            $version = $d['eo_version'] ?? ($versions->first() ?? 1);
        }
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);

        $rows = DB::table('t_recurring_service as R')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'R.ser_list_id')
            ->leftJoin('m_client_account as A', 'A.id', '=', 'R.ca_id')
            ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
            ->where('R.total_service_fee', '>', 0)
            ->when($caid, fn ($q) => $q->where('R.ca_id', $caid)->where('R.eo_version', $version))
            ->when(!$caid, fn ($q) => $q->whereRaw('R.eo_version = (SELECT MAX(R2.eo_version) FROM t_recurring_service R2 WHERE R2.ca_id = R.ca_id)'))
            ->orderBy(DB::raw($this->clientNameSql()))->orderBy('A.branch_code')->orderByDesc('R.is_active')->orderBy('L.name')
            ->get(['R.*', DB::raw("COALESCE(L.name, '') AS service"), 'L.ser_type_id', 'A.branch_code',
                DB::raw($trade ? "A.{$trade} AS trade_name" : "'' AS trade_name"), DB::raw($this->clientNameSql() . ' AS client_name')])
            ->map(function ($r) {
                [$vat, $wh, $disc] = $this->inherited((float) $r->total_service_fee, $r);
                foreach (['service_fee', 'surcharge', 'less', 'total_service_fee', 'less_other_percentage'] as $k) $r->$k = round((float) $r->$k, 2);
                $r->vat = $vat; $r->wh = $wh; $r->discount = $disc;
                $r->net = round((float) $r->total_service_fee + $vat - $wh - $disc, 2);
                $r->is_active = (int) $r->is_active;
                return $r;
            });

        return response()->json(['eo_version' => $version ? (int) $version : null, 'versions' => $versions, 'rows' => $rows,
            'total' => round($rows->where('is_active', 1)->sum('net'), 2)]);
    }

    private function recurringRules(): array
    {
        return [
            'ca_id' => ['required', 'integer'], 'ser_list_id' => ['required', 'integer', 'min:1'], 'ser_fee_id' => ['nullable', 'integer'],
            'eo_version' => ['required', 'integer', 'min:1'], 'qty' => ['required', 'integer', 'min:1'],
            'service_fee' => ['required', 'numeric', 'gt:0'], 'less' => ['nullable', 'numeric', 'min:0'], 'surcharge' => ['nullable', 'numeric', 'min:0'],
            'd_vat' => ['boolean'], 'd_witholding' => ['boolean'], 'discount' => ['nullable', 'in:SC,SP,PWD,Others'],
            'less_other_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'], 'is_active' => ['boolean'],
        ];
    }

    /* POST /api/billing/recurring   ·   PUT /api/billing/recurring/{id} */
    public function addRecurring(Request $request)
    {
        return $this->saveRecurring($request, null);
    }

    public function updateRecurring(Request $request, int $id)
    {
        return $this->saveRecurring($request, $id);
    }

    private function saveRecurring(Request $request, ?int $id)
    {
        $d = $request->validate($this->recurringRules(), ['ser_list_id.min' => 'Please select service', 'service_fee.gt' => 'Please input unit price']);
        if ($id && !DB::table('t_recurring_service')->where('id', $id)->exists()) return response()->json(['message' => 'Recurring service not found.'], 404);

        $dup = DB::table('t_recurring_service')->where('ca_id', $d['ca_id'])->where('eo_version', $d['eo_version'])
            ->where('ser_list_id', $d['ser_list_id'])->where('is_active', 1)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($dup && ($d['is_active'] ?? true)) return response()->json(['message' => 'This service is already on this EO version.'], 422);

        $gross = round((float) $d['service_fee'] * (int) $d['qty'], 2);
        $disc = $d['discount'] ?? '';
        $row = [
            'ca_id' => $d['ca_id'], 'ser_list_id' => $d['ser_list_id'],
            'ser_fee_id' => $d['ser_fee_id'] ?: DB::table('m_service_fee')->where('ser_list_id', $d['ser_list_id'])->min('id'),
            'eo_version' => $d['eo_version'], 'qty' => (int) $d['qty'], 'service_fee' => round((float) $d['service_fee'], 2),
            'less' => round((float) ($d['less'] ?? 0), 2), 'surcharge' => round((float) ($d['surcharge'] ?? 0), 2),
            'total_service_fee' => round($gross - (float) ($d['less'] ?? 0) + $gross * ((float) ($d['surcharge'] ?? 0) / 100), 2),
            'd_vat' => (int) !empty($d['d_vat']), 'd_witholding' => (int) !empty($d['d_witholding']),
            'd_less_sc' => (int) ($disc === 'SC'), 'd_less_sp' => (int) ($disc === 'SP'), 'd_less_pwd' => (int) ($disc === 'PWD'),
            'd_less_others' => (int) ($disc === 'Others'), 'less_other_percentage' => $disc === 'Others' ? round((float) ($d['less_other_percentage'] ?? 0), 2) : 0,
            'is_active' => (int) ($d['is_active'] ?? true), 'updated_at' => now(),
        ];
        if ($id) DB::table('t_recurring_service')->where('id', $id)->update($row);
        else $id = DB::table('t_recurring_service')->insertGetId($row + ['created_at' => now()]);

        return response()->json(['id' => $id, 'message' => 'Recurring service saved']);
    }

    /* POST /api/billing/recurring/{id}/toggle   active ↔ inactive */
    public function toggleRecurring(int $id)
    {
        $r = DB::table('t_recurring_service')->where('id', $id)->first();
        if (!$r) return response()->json(['message' => 'Recurring service not found.'], 404);
        DB::table('t_recurring_service')->where('id', $id)->update(['is_active' => $r->is_active ? 0 : 1, 'updated_at' => now()]);
        return response()->json(['message' => $r->is_active ? 'Service turned off' : 'Service turned on']);
    }

    /* POST /api/billing/recurring/copy   { ca_id, from_version }  → new EO version with the active services */
    public function copyRecurringVersion(Request $request)
    {
        $d = $request->validate(['ca_id' => ['required', 'integer'], 'from_version' => ['required', 'integer']]);
        $rows = DB::table('t_recurring_service')->where('ca_id', $d['ca_id'])->where('eo_version', $d['from_version'])->where('is_active', 1)->get();
        if ($rows->isEmpty()) return response()->json(['message' => 'No active services to copy.'], 422);
        $next = (int) DB::table('t_recurring_service')->where('ca_id', $d['ca_id'])->max('eo_version') + 1;
        DB::transaction(function () use ($rows, $next) {
            foreach ($rows as $r) {
                $copy = (array) $r;
                unset($copy['id']);
                DB::table('t_recurring_service')->insert(['eo_version' => $next, 'created_at' => now(), 'updated_at' => now()] + $copy);
            }
        });
        return response()->json(['eo_version' => $next, 'message' => "EO version {$next} created"]);
    }

    /* =====================================================================
       GENERATE BILLING (old pages/Billing/Generate_Billing + ajax/generate_billing.php)
       recurring PF services → one SOA (t_transaction) per account + its services (t_service)
       + one sales invoice per new service (t_sales_invoice_master / detail, trans_type "billing")
       ===================================================================== */

    /**
     * old qry_004_billing_recurring_to_service_sub:
     *   t_recurring_service A + m_service_list B + m_client_account C
     *   WHERE B.ser_type_id = 4 AND A.service_fee > 0 AND C.status_id = 9
     * (+ the reporting type picked on the page, and only services that are turned on)
     */
    private function recurringToService(?int $repTypeId)
    {
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);
        $repCol = $this->pick('m_service_list', ['rep_type_id']);
        $activeCol = $this->pick('t_recurring_service', ['is_active']);

        return DB::table('t_recurring_service as A')
            ->join('m_service_list as B', 'B.id', '=', 'A.ser_list_id')
            ->join('m_client_account as C', 'C.id', '=', 'A.ca_id')
            ->leftJoin('m_client as CL', 'CL.client_num', '=', 'C.client_num')
            ->where('B.ser_type_id', 4)
            ->where('A.service_fee', '>', 0)
            ->where('C.status_id', 9)
            ->when($repTypeId && $repCol, fn ($q) => $q->where("B.{$repCol}", $repTypeId))
            ->when($activeCol, fn ($q) => $q->where("A.{$activeCol}", 1))
            ->orderBy('A.ca_id')->orderBy('B.name')
            ->get(['A.*', DB::raw('B.name AS service'), 'C.client_num', 'C.branch_code',
                DB::raw(($this->pick('m_client_account', ['assigned_daid']) ? 'C.assigned_daid' : 'NULL') . ' AS assigned_daid'),
                DB::raw($trade ? "C.{$trade} AS trade_name" : "'' AS trade_name"),
                DB::raw($this->clientNameSql('CL') . ' AS client_name')]);
    }

    /** "2026-10" → "OCTOBER 2026" (how the old system stores service_period) */
    private function periodLabel(string $ym): string
    {
        return strtoupper(\Carbon\Carbon::createFromFormat('Y-m-d', $ym . '-01')->format('F Y'));
    }

    /* GET /api/billing/generate/lookups */
    public function generateLookups()
    {
        [$t, $n] = $this->slLookup('rep_type_id');
        return response()->json([
            'reporting_types' => $t && $n ? DB::table($t)->orderBy('id')->get(['id', DB::raw("{$n} AS name")]) : [],
        ]);
    }

    /* GET /api/billing/generate/preview?rep_type_id=&service_period=YYYY-MM   accounts that will be billed */
    public function generatePreview(Request $request)
    {
        $d = $request->validate(['rep_type_id' => ['nullable', 'integer'], 'service_period' => ['nullable', 'date_format:Y-m']]);
        $rows = $this->recurringToService($d['rep_type_id'] ?? null);

        $billed = collect();
        if (!empty($d['service_period']) && $rows->isNotEmpty()) {
            $billed = DB::table('t_transaction')->whereIn('ca_id', $rows->pluck('ca_id')->unique())
                ->where('service_period', $this->periodLabel($d['service_period']))
                ->pluck('trans_number', 'ca_id');
        }

        $accounts = $rows->groupBy('ca_id')->map(function ($g, $caid) use ($billed) {
            $f = $g->first();
            $net = $g->sum(function ($r) {
                [$vat, $wh, $disc] = $this->inherited((float) $r->total_service_fee, $r);
                return (float) $r->total_service_fee + $vat - $wh - $disc;
            });
            return [
                'ca_id' => (int) $caid, 'client_num' => $f->client_num, 'client_name' => $f->client_name,
                'trade_name' => $f->trade_name, 'branch_code' => $f->branch_code,
                'services' => $g->count(), 'service_names' => $g->pluck('service')->filter()->unique()->values(),
                'total' => round($g->sum(fn ($r) => (float) $r->total_service_fee), 2), 'net' => round($net, 2),
                'trans_number' => $billed[$caid] ?? null,
            ];
        })->sortBy(fn ($a) => strtolower($a['client_name'] . ' ' . $a['trade_name'] . ' ' . $a['branch_code']))->values();

        return response()->json(['accounts' => $accounts, 'total' => round($accounts->sum('total'), 2), 'net' => round($accounts->sum('net'), 2)]);
    }

    /**
     * POST /api/billing/generate   { date_start, service_period: YYYY-MM, rep_type_id }
     * Invoices go to the logged-in staff's company books (m_employee.company_caid).
     */
    public function generate(Request $request)
    {
        $d = $request->validate([
            'date_start' => ['required', 'date'],
            'service_period' => ['required', 'date_format:Y-m'],
            'rep_type_id' => ['required', 'integer'],
        ], ['date_start.required' => 'Please provide all required data!', 'service_period.required' => 'Please provide all required data!', 'rep_type_id.required' => 'Please provide all required data!']);

        if ($d['service_period'] > now()->format('Y-m')) return response()->json(['message' => 'Service period cannot be in the future.'], 422);

        $period = $this->periodLabel($d['service_period']);
        $eoVersion = (int) substr($d['service_period'], 0, 4);
        $employeeId = $this->me($request);

        $rows = $this->recurringToService((int) $d['rep_type_id']);
        if ($rows->isEmpty()) return response()->json(['message' => 'No recurring services found for this reporting type.'], 422);

        // the logged-in staff's company books: m_employee.company_caid → m_user_client (old obj_AddedByCAID + doGetAddedBy)
        $addedBy = $this->companyBooks($request);
        if (is_string($addedBy)) return response()->json(['message' => $addedBy], 422);

        $inv = $this->billingInvoiceSetup($addedBy);
        if (is_string($inv)) return response()->json(['message' => $inv], 422);

        $created = 0; $services = 0; $invoices = 0; $skipped = []; $noCustomer = [];

        DB::transaction(function () use ($rows, $d, $period, $eoVersion, $employeeId, $addedBy, $inv, &$created, &$services, &$invoices, &$skipped, &$noCustomer) {
            $prepTarget = \Carbon\Carbon::parse($d['date_start'])->addDays(5)->toDateString();
            $declared = $d['service_period'];

            foreach ($rows->groupBy('ca_id') as $caid => $group) {
                $caid = (int) $caid;
                $first = $group->first();

                // one SOA per account + period (reuse it if it already exists)
                $trans = DB::table('t_transaction')->where('ca_id', $caid)->where('service_period', $period)->lockForUpdate()->value('trans_number');
                if ($trans) {
                    $skipped[] = $caid;
                } else {
                    $trans = $this->nextTransNumber($d['service_period']);
                    DB::table('t_transaction')->insert($this->only('t_transaction', [
                        'trans_number' => $trans, 'trans_type_id' => 1, 'ca_id' => $caid, 'service_period' => $period,
                        'date_start' => $d['date_start'], 'ass_to_id' => ((int) ($first->assigned_daid ?? 0)) ?: null,
                        'eo_version' => $eoVersion, 'prepared_by_id' => $employeeId, 'is_paid' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]));
                    $created++;
                }

                foreach ($group as $r) {
                    // service already on this SOA → skip (old "Bypassing SerFeeID")
                    $dup = DB::table('t_service')->where('trans_number', $trans)->where('service_period', $period)
                        ->where(fn ($q) => $r->ser_fee_id ? $q->where('ser_fee_id', $r->ser_fee_id) : $q->where('ser_list_id', $r->ser_list_id))
                        ->exists();
                    if ($dup) continue;

                    $total = round((float) $r->total_service_fee, 2);
                    [$vat, $wh, $disc] = $this->inherited($total, $r);
                    DB::table('t_service')->insert($this->only('t_service', [
                        'service_period' => $period, 'trans_number' => $trans, 'ca_id' => $caid,
                        'ser_fee_id' => $r->ser_fee_id ?: null, 'ser_list_id' => $r->ser_list_id, 'service' => $r->service,
                        'service_fee' => round((float) $r->service_fee, 2), 'qty' => $r->qty ?? 1,
                        'less' => round((float) ($r->less ?? 0), 2), 'surcharge' => round((float) ($r->surcharge ?? 0), 2),
                        'total_service_fee' => $total, 'prep_target_date' => $prepTarget,
                        'inherited_vat' => $vat, 'inherited_wh' => $wh, 'inherited_discount_amount' => $disc,
                        'd_vat' => $r->d_vat ?? 0, 'd_witholding' => $r->d_witholding ?? 0, 'd_less_sc' => $r->d_less_sc ?? 0,
                        'd_less_sp' => $r->d_less_sp ?? 0, 'd_less_pwd' => $r->d_less_pwd ?? 0, 'd_less_others' => $r->d_less_others ?? 0,
                        'less_other_percentage' => $r->less_other_percentage ?? 0,
                        'invoice_status_id' => self::UNPAID, 'created_at' => now(), 'updated_at' => now(),
                    ]));
                    $services++;

                    // sales invoice in the firm's own books (old doSaveSalesInvoie)
                    $customerId = $inv['customers'][$caid] ?? null;
                    if (!$customerId) { $noCustomer[$caid] = true; continue; }
                    $this->insertBillingInvoice($inv, $addedBy, $customerId, $employeeId, $trans, $d['date_start'], $declared, $total);
                    $invoices++;
                }
            }
        });

        if ($created === 0 && $services === 0) {
            return response()->json(['message' => 'No Billing Generated (every account already has these services for ' . $period . ').'], 422);
        }

        return response()->json([
            'message' => 'Billing successfully generated',
            'period' => $period, 'soa_created' => $created, 'services_added' => $services, 'invoices_created' => $invoices,
            'skipped_transactions' => $skipped, 'no_customer' => array_keys($noCustomer),
        ]);
    }

    /** PREFIX-YYYYMM-0001 – the sequence runs across all numbers with that prefix (PF / R / EO) */
    private function nextTransNumber(string $ym, string $prefix = 'PF'): string
    {
        $last = DB::table('t_transaction')->where('trans_number', 'like', $prefix . '-%')
            ->orderByRaw("CAST(SUBSTRING_INDEX(trans_number, '-', -1) AS UNSIGNED) DESC")->lockForUpdate()->value('trans_number');
        $seq = $last ? ((int) substr(strrchr($last, '-'), 1)) + 1 : 1;
        return $prefix . '-' . str_replace('-', '', $ym) . '-' . ($seq < 10000 ? str_pad((string) $seq, 4, '0', STR_PAD_LEFT) : (string) $seq);
    }

    /**
     * user id of the logged-in staff's company books (old obj_AddedByCAID + doGetAddedBy):
     *   m_employee (this login) → company_caid → m_client_account → m_user_client
     * The employee is found the same way as everywhere else (me()): employee_id, else m_user_staff.comid = m_employee.com_id.
     */
    private function companyBooks(Request $request)
    {
        $u = $request->user();
        if (!$u) return 'Not logged in.';

        $empId = $this->me($request);
        if (!$empId) {
            return "Your login (#{$u->id}) is not linked to an employee. Check User Access → Login for this staff.";
        }

        $caid = (int) DB::table('m_employee')->where('id', $empId)->value('company_caid');
        if ($caid <= 0) {
            $e = DB::table('m_employee')->where('id', $empId)->first(['last_name', 'first_name']);
            $name = trim(($e->last_name ?? '') . ' ' . ($e->first_name ?? ''));
            return "No company account is set for {$name} (employee #{$empId}). Set the employee's Company account in HR Management, then try again.";
        }

        $userId = (int) DB::table('m_user_client')->where('caid', $caid)->orderBy('id')->value('id');
        if ($userId <= 0) {
            return "The company account (#{$caid}) of employee #{$empId} has no login (m_user_client). Create one for that account, then try again.";
        }
        return $userId;
    }

    /* =====================================================================
       MANUAL BILL (old modal_Manual_Billing + ajax/manual_billing.php)
       one account, picked services → SOA (R / EO / PF) + services + sales invoices
       ===================================================================== */

    const MANUAL_PREFIX = [1 => 'R', 2 => 'EO', 3 => 'PF'];
    const FDA_DESIGNATION = 3;

    private function designation(?int $employeeId): int
    {
        return $employeeId ? (int) DB::table('m_employee')->where('id', $employeeId)->value('designation_id') : 0;
    }

    /* GET /api/billing/manual/lookups   accounts · transaction types · service periods · services */
    public function manualLookups(Request $request)
    {
        $me = $this->me($request);
        $isFda = $this->designation($me) === self::FDA_DESIGNATION;
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);

        $accounts = DB::table('m_client_account as A')
            ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
            ->when($isFda, fn ($q) => $q->where('A.assigned_daid', $me))      // FDA: own accounts only
            ->get(['A.id', 'A.branch_code', DB::raw($trade ? "A.{$trade} AS trade_name" : "'' AS trade_name"), DB::raw($this->clientNameSql() . ' AS client_name')])
            ->sortBy(fn ($a) => strtolower(trim($a->trade_name . ' ' . $a->branch_code)))->values();

        [$tt, $tn] = $this->slLookup('trans_type_id');
        $types = $tt && $tn
            ? DB::table($tt)->whereIn('id', $isFda ? [1] : [1, 2, 3])->orderBy('id')->get(['id', DB::raw("{$tn} AS name")])
            : collect($isFda ? [1] : [1, 2, 3])->map(fn ($i) => ['id' => $i, 'name' => [1 => 'Recurring', 2 => 'Engagement Order', 3 => 'Professional Fee'][$i]]);

        // service periods: accounting period table + periods already used on SOAs + EO-<year>
        $periods = collect();
        $pt = $this->firstTable(['m_accounting_period', 't_accounting_period', 'm_accounting_periods', 'accounting_period', 'm_acc_period', 'm_service_period', 'm_service_periods']);
        $pc = $pt ? $this->pick($pt, ['service_period', 'period', 'name']) : null;
        if ($pc) {
            $periods = $periods->merge(DB::table($pt)->pluck($pc));
        }
        $periods = $periods->merge(DB::table('t_transaction')->whereNotNull('service_period')->distinct()->pluck('service_period'));
        $periods->push('EO-' . now()->format('Y'));
        if ($periods->filter()->isEmpty()) {
            $periods = collect(range(0, 23))->map(fn ($i) => strtoupper(now()->startOfMonth()->subMonths($i)->format('F Y')));
        }

        // EO-2026, EO-2025 … first, then months newest first
        $periods = $periods->map(fn ($p) => strtoupper(trim((string) $p)))->filter()->unique()
            ->sortByDesc(function ($p) {
                if (preg_match('/^EO-(\d{4})$/', $p, $m)) return '9' . $m[1];
                $t = strtotime('1 ' . $p);
                return $t ? '1' . date('Ym', $t) : '0' . $p;
            })->values();

        return response()->json([
            'accounts' => $accounts, 'trans_types' => $types, 'service_periods' => $periods,
            'eo_version' => (int) now()->format('Y'), 'is_fda' => $isFda,
        ]);
    }

    /**
     * POST /api/billing/manual
     * { ca_id, trans_type_id (1 R · 2 EO · 3 PF), service_period ("OCTOBER 2026"), eo_version,
     *   lines: [{ ser_list_id, ser_fee_id, prep_target_date, service_fee, qty }] }
     */
    public function manualBill(Request $request)
    {
        $d = $request->validate([
            'ca_id' => ['required', 'integer', 'min:1'],
            'trans_type_id' => ['required', 'integer', 'in:1,2,3'],
            'service_period' => ['required', 'string', 'max:30'],
            'eo_version' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ser_list_id' => ['required', 'integer', 'min:1'],
            'lines.*.ser_fee_id' => ['nullable', 'integer'],
            'lines.*.prep_target_date' => ['required', 'date'],
            'lines.*.service_fee' => ['required', 'numeric', 'gt:0'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], [
            'ca_id.required' => 'Trade Name is required', 'ca_id.min' => 'Trade Name is required',
            'trans_type_id.required' => 'Transaction type is required', 'service_period.required' => 'Service Period is required',
            'lines.required' => 'Service list is required', 'lines.min' => 'Service list is required',
            'lines.*.ser_list_id.*' => 'Please select a service on every row', 'lines.*.prep_target_date.*' => 'Due date is required on every row',
            'lines.*.service_fee.*' => 'Unit price is required on every row',
        ]);

        $me = $this->me($request);
        $type = (int) $d['trans_type_id'];
        if ($this->designation($me) === self::FDA_DESIGNATION && $type !== 1) {
            return response()->json(['message' => 'You can only create recurring billing.'], 403);
        }
        // EO (type 2) bills an EO-YYYY period; every other type bills a month
        if (($type === 2) !== (bool) preg_match('/^EO-/i', trim($d['service_period']))) {
            return response()->json(['message' => $type === 2 ? 'Engagement Order needs an EO service period.' : 'EO service periods are only for Engagement Order.'], 422);
        }
        $account = DB::table('m_client_account')->where('id', $d['ca_id'])->first();
        if (!$account) return response()->json(['message' => 'Client account not found.'], 404);

        $total = array_sum(array_map(fn ($l) => round((float) $l['service_fee'] * (float) $l['qty'], 2), $d['lines']));
        if ($total <= 0) return response()->json(['message' => 'Total is required'], 422);

        $addedBy = $this->companyBooks($request);
        if (is_string($addedBy)) return response()->json(['message' => $addedBy], 422);
        $inv = $this->billingInvoiceSetup($addedBy);
        if (is_string($inv)) return response()->json(['message' => $inv], 422);

        $names = DB::table('m_service_list')->whereIn('id', array_column($d['lines'], 'ser_list_id'))->pluck('name', 'id');
        $today = now()->toDateString();
        $declared = now()->format('Y-m');
        $period = strtoupper(trim($d['service_period']));
        $eoVersion = (int) ($d['eo_version'] ?? now()->format('Y'));

        try {
            $result = DB::transaction(function () use ($d, $account, $type, $period, $eoVersion, $me, $addedBy, $inv, $names, $today, $declared) {
                $caid = (int) $account->id;
                $created = false; $added = 0; $invoices = 0; $noCustomer = false;

                // PF reuses the account's SOA for the period; R and EO always get a new one (old: TransTypeID NOT IN (1,2))
                $trans = $type === 3
                    ? DB::table('t_transaction')->where('ca_id', $caid)->where('trans_type_id', 3)->where('service_period', $period)->lockForUpdate()->value('trans_number')
                    : null;
                if (!$trans) {
                    $trans = $this->nextTransNumber(now()->format('Y-m'), self::MANUAL_PREFIX[$type]);
                    DB::table('t_transaction')->insert($this->only('t_transaction', [
                        'trans_number' => $trans, 'trans_type_id' => $type, 'ca_id' => $caid, 'service_period' => $period,
                        'date_start' => $today, 'ass_to_id' => ((int) ($account->assigned_daid ?? 0)) ?: null,
                        'eo_version' => $eoVersion, 'prepared_by_id' => $me, 'is_paid' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]));
                    $created = true;
                }

                foreach ($d['lines'] as $l) {
                    $dup = DB::table('t_service')->where('trans_number', $trans)->where('service_period', $period)
                        ->where(fn ($q) => !empty($l['ser_fee_id']) ? $q->where('ser_fee_id', $l['ser_fee_id']) : $q->where('ser_list_id', $l['ser_list_id']))
                        ->exists();
                    if ($dup) continue;                                                    // old "Bypassing SerFeeID"

                    $lineTotal = round((float) $l['service_fee'] * (float) $l['qty'], 2);
                    DB::table('t_service')->insert($this->only('t_service', [
                        'service_period' => $period, 'trans_number' => $trans, 'ca_id' => $caid,
                        'ser_fee_id' => ($l['ser_fee_id'] ?? 0) ?: null, 'ser_list_id' => $l['ser_list_id'], 'service' => $names[$l['ser_list_id']] ?? '',
                        'service_fee' => round((float) $l['service_fee'], 2), 'qty' => $l['qty'], 'less' => 0, 'surcharge' => 0,
                        'total_service_fee' => $lineTotal, 'prep_target_date' => $l['prep_target_date'],
                        'inherited_vat' => 0, 'inherited_wh' => 0, 'inherited_discount_amount' => 0,
                        'invoice_status_id' => self::UNPAID, 'created_at' => now(), 'updated_at' => now(),
                    ]));
                    $added++;

                    $customerId = $inv['customers'][$caid] ?? null;
                    if (!$customerId) { $noCustomer = true; continue; }
                    $this->insertBillingInvoice($inv, $addedBy, $customerId, $me, $trans, $today, $declared, $lineTotal);
                    $invoices++;
                }

                // nothing new → roll everything back (old "Billing already exists")
                if (!$created && $added === 0) throw new \RuntimeException('Billing already exists', 409);
                return compact('trans', 'created', 'added', 'invoices', 'noCustomer');
            });
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) return response()->json(['message' => $e->getMessage()], 422);
            throw $e;
        }

        return response()->json([
            'message' => 'Records updated successfully', 'trans_number' => $result['trans'],
            'soa_created' => $result['created'], 'services_added' => $result['added'], 'invoices_created' => $result['invoices'],
            'no_customer' => $result['noCustomer'],
        ]);
    }

    /**
     * AR account, "Sales VAT" item and client-account → customer links of the firm's books
     * (old doGetMasterAccount / doGetDetailAccount / doGetLinkCAID). Returns an error message on failure.
     */
    private function billingInvoiceSetup(int $addedBy)
    {
        $descCols = array_filter([$this->pick('m_charts_of_account', ['account_name']), $this->pick('m_charts_of_account', ['description'])]);
        $ar = '';
        foreach ($descCols as $c) {
            $ar = (string) DB::table('m_charts_of_account')->where('added_by', $addedBy)
                ->where(fn ($q) => $q->whereRaw("UPPER(TRIM({$c})) REGEXP '^AR$'")->orWhereRaw("UPPER(TRIM({$c})) REGEXP '^A/AR$'")
                    ->orWhereRaw("UPPER(TRIM({$c})) REGEXP '^A[C]?C?OUNTS?[[:space:]-]*RECEIVABLES?$'"))
                ->value('account_code');
            if ($ar !== '') break;
        }

        $itemDesc = $this->pick('m_items', ['description', 'item_name', 'name']);
        $gl = $this->pick('m_items', ['gl_sales_account', 'gl_sales_acount', 'gl_sales_acct']);
        $item = $itemDesc ? DB::table('m_items')->where('added_by', $addedBy)
            ->where(fn ($q) => $q->whereRaw("UPPER(TRIM({$itemDesc})) REGEXP '^VARIOUS SALES[[:space:]-]*(VAT|VT|VATABLE|VATABLES)$'")
                ->orWhereRaw("UPPER(TRIM({$itemDesc})) REGEXP '^SALES[[:space:]-]*(VAT|VT|VATABLE|VATABLES)$'"))
            ->first() : null;
        if (!$item) return 'No sales detail account found (an item named "Sales VAT" / "Various Sales VAT") for AddedBy: ' . $addedBy;

        $linkCol = $this->pick('m_vendor_customer', ['link_ca_id', 'linked_ca_id', 'link_caid', 'ca_id']);
        $customers = $linkCol ? DB::table('m_vendor_customer')->where('entry_by', $addedBy)->where('user_type', 'Customer')
            ->whereNotNull($linkCol)->pluck('id', $linkCol)->all() : [];

        $masterFk = $this->pick('t_sales_invoice_detail', ['invoice_master_id', 'sales_invoice_master_id', 'master_id', 'invoice_id']);

        return [
            'ar' => $ar, 'item_id' => $item->id, 'unit_type_id' => $item->unit_type_id ?? null,
            'tax_type' => (int) ($item->tax_type_id ?? $item->tax_type ?? 0), 'gl' => $gl ? $item->$gl : null,
            'customers' => $customers, 'master_fk' => $masterFk,
        ];
    }

    /** one sales invoice (UNPAID, For Collection) for one billed service */
    private function insertBillingInvoice(array $inv, int $addedBy, int $customerId, ?int $employeeId, string $docNo, string $date, string $declared, float $amount): void
    {
        $sub = round($amount, 4);
        $vat = $net = $non = $zero = 0.0; $exempt = $sub;
        switch ($inv['tax_type']) {
            case 1: $vat = round($sub * 12 / 112, 4); $net = round($sub - $vat, 4); $exempt = 0.0; break; // vatable
            case 7: $non = $sub; $exempt = 0.0; break;                                                     // non-VAT
            case 6: $zero = $sub; $exempt = 0.0; break;                                                    // zero rated
        }

        do {
            $no = 'INV-' . date('ymd-his') . random_int(10000, 99999);
        } while (DB::table('t_sales_invoice_master')->where('invoice_no', $no)->exists());
        $seq = (int) DB::table('t_sales_invoice_master')->where('added_by', $addedBy)->lockForUpdate()->max('seq_no') + 1;

        $common = [
            'added_by' => $addedBy, 'customer_id' => $customerId, 'transaction_date' => $date, 'document_no' => $docNo,
            'declared_month' => $declared, 'invoice_status_id' => self::UNPAID, 'assigned_daid' => $employeeId ?: null,
            'trans_type' => 'billing', 'currency' => 'PHP', 'ex_rate' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];
        $masterId = DB::table('t_sales_invoice_master')->insertGetId($this->only('t_sales_invoice_master', $common + [
            'invoice_no' => $no, 'seq_no' => $seq, 'sub_total' => $sub, 'total' => $sub, 'grand_total' => $sub,
            'discount_amount' => 0, 'document_type_id' => 40, 'account_code' => $inv['ar'] ?: null,
        ]));

        DB::table('t_sales_invoice_detail')->insert($this->only('t_sales_invoice_detail', $common + array_filter([
            $inv['master_fk'] ?? '' => $masterId,
        ], fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY) + [
            'invoice_no' => $no, 'item_id' => $inv['item_id'], 'unit_type_id' => $inv['unit_type_id'], 'quantity' => 1,
            'unit_price' => $sub, 'amount' => $sub, 'discount_amount' => 0, 'input_vat' => $vat, 'net_of_vat' => $net,
            'vat_exempt' => $exempt, 'non_vat' => $non, 'zero_rated' => $zero, 'account_code' => $inv['gl'],
        ]));
    }

    /* =====================================================================
       COLLECTION (old pages/Billing/Collection) and DEPOSIT (old pages/Billing/Deposit)
       t_payment flow:  on hand (payment_status 1)  →  SUBMIT to a bank account (prepared, status 2)
                        →  DEPOSIT (deposited, status 3)
       ===================================================================== */

    const PAY_ON_HAND = 1, PAY_SUBMITTED = 2, PAY_DEPOSITED = 3;
    const EFPS_OTC = 6;                         // payment type shown read-only on the EFPS and OTC tab

    /** [bank name table, its columns] and [bank account table, its columns] (old tbl_accounting_bank_name / bank account) */
    private function bankTables(): array
    {
        $bn = $this->firstTable(['m_accounting_bank_name', 'm_bank_name', 'm_banks', 'm_bank']);
        $ba = $this->firstTable(['m_accounting_bank_account', 'm_bank_account', 'm_bank_accounts']);
        return [
            $bn, $bn ? [
                'abb' => $this->pick($bn, ['bank_name_abb', 'abbreviation', 'abb', 'code']),
                'name' => $this->pick($bn, ['bank_name_des', 'bank_name', 'description', 'name']),
                'active' => $this->pick($bn, ['is_active', 'active']),
            ] : [],
            $ba, $ba ? [
                'bank' => $this->pick($ba, ['abaid', 'bank_name_id', 'accounting_bank_name_id', 'bank_id']),
                'number' => $this->pick($ba, ['account_number', 'account_no', 'number']),
                'name' => $this->pick($ba, ['account_name', 'name', 'description']),
            ] : [],
        ];
    }

    private function receiptNo(object $p): ?string
    {
        return match ((int) $p->receipt_type_id) { 2 => $p->ap_number, 3 => $p->or_number, default => $p->ar_number };
    }

    /* GET /api/billing/collection/lookups   payment types (tabs) · collection types · banks with their accounts */
    public function collectionLookups()
    {
        [$bn, $bc, $ba, $ac] = $this->bankTables();
        $banks = collect();
        if ($bn) {
            $accounts = $ba && $ac['bank'] && $ac['number']
                ? DB::table($ba)->get()->groupBy($ac['bank'])
                : collect();
            $banks = DB::table($bn)->when($bc['active'], fn ($q) => $q->where($bc['active'], 1))->orderBy('id')->get()
                ->map(fn ($b) => [
                    'id' => $b->id, 'abb' => $bc['abb'] ? $b->{$bc['abb']} : '', 'name' => $bc['name'] ? $b->{$bc['name']} : '',
                    'accounts' => ($accounts[$b->id] ?? collect())->map(fn ($a) => [
                        'id' => $a->id, 'number' => $a->{$ac['number']}, 'name' => $ac['name'] ? $a->{$ac['name']} : null,
                    ])->values(),
                ]);
        }
        return response()->json([
            'payment_types' => DB::table('m_payment_type')->orderBy('id')->get(['id', 'name']),
            'col_types' => DB::table('m_payment_col_type')->when(in_array('is_active', $this->cols('m_payment_col_type'), true), fn ($q) => $q->where('is_active', 1))->orderBy('id')->get(['id', 'name']),
            'banks' => $banks,
            'efps_type_id' => self::EFPS_OTC,
        ]);
    }

    /** a t_payment column if it exists (old / new column names) */
    private function payCol(string ...$names): ?string
    {
        return $this->pick('t_payment', $names);
    }

    /** WHERE <col> IS NULL OR <col> = 0 – only when the column exists */
    private function zeroOrNull($q, ?string $col, string $alias = 'P')
    {
        if ($col) $q->where(fn ($w) => $w->whereNull("{$alias}.{$col}")->orWhere("{$alias}.{$col}", 0));
        return $q;
    }

    /** payments still on hand: not submitted to a bank, not deposited */
    private function onHand($q)
    {
        // old doGetCollectionList: AssignedBank = 0
        return $this->zeroOrNull($q, $this->payCol('assigned_bank'));
    }

    /** base query for the collection / deposit lists */
    private function collectionQuery()
    {
        $retCol = $this->pick('t_service', ['return_status_id']);
        $q = DB::table('t_payment as P')
            ->leftJoin('m_payment_type as PT', 'PT.id', '=', 'P.payment_type_id')
            ->leftJoin('m_payment_col_type as COL', 'COL.id', '=', 'P.payment_col_type_id')
            ->leftJoin('t_service as S', 'S.id', '=', 'P.service_id')
            ->select(['P.*', DB::raw('PT.name AS payment_type'), DB::raw('COL.name AS col_type')]);
        if ($retCol && $this->hasTable('m_payment_return_status')) {
            $q->leftJoin('m_payment_return_status as RS', 'RS.id', '=', "S.{$retCol}")->addSelect(DB::raw('RS.name AS return_status'));
        } else {
            $q->addSelect(DB::raw('NULL AS return_status'));
        }
        $this->accountJoin($q, 'P.ca_id');
        return $q;
    }

    private function collectionRow(object $p): object
    {
        foreach (['amount', 'co_amount', 'total_amount'] as $k) $p->$k = round((float) ($p->$k ?? 0), 2);
        if ($p->total_amount == 0 && $p->amount != 0) $p->total_amount = round($p->amount - $p->co_amount, 2);
        $p->payment_date = !empty($p->payment_date) ? substr((string) $p->payment_date, 0, 10) : null;
        $p->receipt_type_id = $p->receipt_type_id ?? null;
        foreach (['ar_number', 'ap_number', 'or_number', 'details1', 'details2', 'remarks'] as $k) $p->$k = $p->$k ?? null;
        $p->receipt_no = $this->receiptNo($p);
        $p->details = trim(($p->details1 ?? '') . ' ' . ($p->details2 ?? ''));
        return $p;
    }

    /**
     * GET /api/billing/collection?payment_type_id=&col_type_id=
     * payments still on hand (not yet submitted to a bank) + the cash / cheque on hand totals
     */
    public function collection(Request $request)
    {
        $d = $request->validate(['payment_type_id' => ['required', 'integer'], 'col_type_id' => ['nullable', 'integer']]);
        $type = (int) $d['payment_type_id'];

        // old doGetCollectionList
        //   type 1-5 : PaymentTypeID = type AND AssignedBank = 0 [AND PaymentColType = col]
        //   type 6+  : PaymentTypeID != 0 AND AssignedBank = 8 (EFPS and OTC, with the return status)
        $q = $this->collectionQuery();
        if ($type <= 5) {
            $q->where('P.payment_type_id', $type);
            $this->onHand($q);
            if (!empty($d['col_type_id'])) $q->where('P.payment_col_type_id', $d['col_type_id']);
        } else {
            $q->where('P.payment_type_id', '!=', 0)->where('P.assigned_bank', 8);
        }
        $rows = $q->orderByDesc('P.ar_number')->get()->map(fn ($p) => $this->collectionRow($p));

        $co = $this->payCol('co_amount');
        $tot = $this->payCol('total_amount');
        $net = $tot
            ? "CASE WHEN COALESCE(P.{$tot},0) = 0 THEN COALESCE(P.amount,0)" . ($co ? " - COALESCE(P.{$co},0)" : '') . " ELSE P.{$tot} END"
            : 'COALESCE(P.amount,0)' . ($co ? " - COALESCE(P.{$co},0)" : '');
        $tq = DB::table('t_payment as P')->whereIn('P.payment_type_id', [1, 2]);
        $this->onHand($tq);
        $totals = $tq->groupBy('P.payment_type_id')->selectRaw("P.payment_type_id, SUM({$net}) AS t")->pluck('t', 'payment_type_id');
        $cash = round((float) ($totals[1] ?? 0), 2);
        $cheque = round((float) ($totals[2] ?? 0), 2);

        return response()->json(['rows' => $rows, 'totals' => ['cash' => $cash, 'cheque' => $cheque, 'cash_cheque' => round($cash + $cheque, 2)]]);
    }

    /* PUT /api/billing/collection/{id}   { co_amount?, remarks? }  (old doUpdateCOAmount / doUpdateRemarks) */
    public function updateCollection(Request $request, int $id)
    {
        $d = $request->validate(['co_amount' => ['nullable', 'numeric', 'min:0'], 'remarks' => ['nullable', 'string', 'max:500']]);
        $p = DB::table('t_payment')->where('id', $id)->first();
        if (!$p) return response()->json(['message' => 'Payment not found.'], 404);
        if ((int) ($p->is_deposited ?? 0) === 1) return response()->json(['message' => 'This payment is already deposited.'], 422);

        $upd = ['updated_at' => now()];
        $msg = 'Note successfully updated';
        if ($request->has('co_amount')) {
            $co = round((float) ($d['co_amount'] ?? 0), 2);
            if ($co > (float) $p->amount) return response()->json(['message' => 'C/O cannot be more than the amount.'], 422);
            $upd += ['co_amount' => $co, 'total_amount' => round((float) $p->amount - $co, 2)];
            $msg = 'C/O amount successfully updated';
        }
        if ($request->has('remarks')) $upd['remarks'] = $d['remarks'] ?? null;
        DB::table('t_payment')->where('id', $id)->update($this->only('t_payment', $upd));
        return response()->json(['message' => $msg]);
    }

    /**
     * POST /api/billing/collection/submit   (old ajax/submit_collection.php)
     * { payment_type_id, bank_account_id, date, ids[] } → payments assigned to the bank account (ready for deposit)
     */
    public function submitCollection(Request $request)
    {
        $d = $request->validate([
            'payment_type_id' => ['required', 'integer'],
            'bank_name_id' => ['nullable', 'integer'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], ['bank_account_id.required' => 'Bank Account is required', 'bank_account_id.min' => 'Bank Account is required', 'ids.required' => 'Please select the payments to submit', 'ids.min' => 'Please select the payments to submit']);
        if ((int) $d['payment_type_id'] > 5) return response()->json(['message' => 'EFPS and OTC payments are not submitted here.'], 422);
        if (!$this->payCol('assigned_bank')) return response()->json(['message' => 't_payment has no assigned_bank column.'], 422);

        $me = $this->me($request);
        if (!$me) return response()->json(['message' => 'Your login is not linked to an employee.'], 422);
        $q = DB::table('t_payment as P')->whereIn('P.id', $d['ids']);
        $this->onHand($q);
        $n = $q->update($this->only('t_payment', [
            'aba_id' => $d['bank_name_id'] ?? null, 'assigned_bank' => $d['bank_account_id'], 'assigned_by' => $me, 'assigned_bank_date' => $d['date'],
            'payment_status' => self::PAY_SUBMITTED, 'updated_at' => now(),
        ]));
        if (!$n) return response()->json(['message' => 'Nothing submitted – those payments were already submitted.'], 422);
        return response()->json(['message' => "{$n} payment(s) successfully submitted", 'count' => $n]);
    }

    /* GET /api/billing/deposit   payments submitted to a bank and waiting to be deposited (old qry2_deposit_list) */
    public function depositList()
    {
        // old qry2_deposit_list: WHERE AssignedBankBy > 0 AND PreparedByID IS NULL ORDER BY PreparedDate DESC, PaymentID DESC
        if (!$this->payCol('assigned_by') || !$this->payCol('prepared_by_id')) return response()->json(['rows' => [], 'total' => 0]);
        [, , $ba, $ac] = $this->bankTables();
        $q = $this->collectionQuery()->where('P.assigned_by', '>', 0);
        $this->zeroOrNull($q, 'prepared_by_id');                     // PreparedByID IS NULL (or 0)
        if ($ba && $ac['number']) {
            $q->leftJoin("{$ba} as BA", 'BA.id', '=', 'P.assigned_bank')->addSelect(DB::raw("BA.{$ac['number']} AS bank_account"));
        } else {
            $q->addSelect(DB::raw('NULL AS bank_account'));
        }
        if ($pd = $this->payCol('prepared_date')) $q->orderByDesc("P.{$pd}");
        $rows = $q->orderByDesc('P.id')->get()
            ->map(function ($p) {
                $p = $this->collectionRow($p);
                $p->payment_details = $p->details; // CONCAT(Details1, ' ', Details2)
                return $p;
            });
        return response()->json(['rows' => $rows, 'total' => round($rows->sum('total_amount'), 2)]);
    }

    /* POST /api/billing/deposit   { date, ids[] }   (old ajax/submit_deposit.php) */
    public function deposit(Request $request)
    {
        $d = $request->validate([
            'date' => ['required', 'date'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'Please select row to deposit', 'ids.min' => 'Please select row to deposit']);
        if (!$this->payCol('prepared_by_id')) return response()->json(['message' => 't_payment has no prepared_by_id column.'], 422);

        $me = $this->me($request);
        if (!$me) return response()->json(['message' => 'Your login is not linked to an employee.'], 422);
        $slipCol = $this->payCol('dep_slip_number');
        $n = DB::transaction(function () use ($d, $me, $slipCol) {
            $slip = $slipCol ? (string) ((int) DB::table('t_payment')->lockForUpdate()->max(DB::raw("CAST({$slipCol} AS UNSIGNED)")) + 1) : null;
            $q = DB::table('t_payment as P')->whereIn('P.id', $d['ids'])->where('P.assigned_by', '>', 0);
            $this->zeroOrNull($q, 'prepared_by_id');
            return $q->update($this->only('t_payment', [
                    'prepared_by_id' => $me, 'prepared_date' => $d['date'], 'is_prepared' => 1, 'dep_slip_number' => $slip,
                    'payment_status' => self::PAY_DEPOSITED, 'updated_at' => now(),
                ]));
        });
        if (!$n) return response()->json(['message' => 'Nothing deposited – those payments were already deposited.'], 422);
        return response()->json(['message' => "{$n} payment(s) successfully deposited", 'count' => $n]);
    }

    /* =====================================================================
       RETURN AR (old pages/Billing/Return_AR) – reuses the SOA dashboard endpoints
       ===================================================================== */

    /* GET /api/billing/return-ar/accounts   admin (user type 1) sees all, others only their assigned accounts */
    public function returnArAccounts(Request $request)
    {
        $me = $this->me($request);
        $isAdmin = (int) ($request->user()->user_type_id ?? 0) === 1;
        $trade = $this->pick('m_client_account', ['trade_name', 'business_name', 'name']);
        return response()->json(
            DB::table('m_client_account as A')
                ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
                ->when(!$isAdmin, fn ($q) => $q->where('A.assigned_daid', $me ?: -1))
                ->get(['A.id', 'A.branch_code', DB::raw($trade ? "A.{$trade} AS trade_name" : "'' AS trade_name"), DB::raw($this->clientNameSql() . ' AS client_name')])
                ->sortBy(fn ($a) => strtolower(trim($a->client_name . ' ' . $a->branch_code . ' ' . $a->trade_name)))->values()
        );
    }

    /* PUT /api/billing/return-ar/services/{id}   edit a return / other charge (no supervisor, like the old page) */
    public function updateReturnService(Request $request, int $id)
    {
        return $this->saveService($request, null, $id, true);
    }

}