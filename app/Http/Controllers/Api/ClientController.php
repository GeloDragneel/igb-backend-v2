<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientValidId;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client → Masterlist: every m_client and m_client_account.
 * Same lookups, rules and tables as SettingsController, but for any client (by client_num) instead of the signed-in one.
 * Cities / barangays come from the existing /settings/cities and /settings/barangays.
 */
class ClientController extends Controller
{
    /* ---------- helpers (same as SettingsController) ---------- */

    // first existing table + label column → [{id, label}]
    private function options(array $tables, array $labels, array $extra = []): array
    {
        foreach ($tables as $t) {
            if (!Schema::hasTable($t)) continue;
            $cols = Schema::getColumnListing($t);
            $label = collect($labels)->first(fn ($c) => in_array($c, $cols, true));
            if (!$label) continue;
            $select = array_merge(['id', "{$label} as label"], array_values(array_filter($extra, fn ($c) => in_array($c, $cols, true))));
            return DB::table($t)->orderBy($label)->get($select)->map(fn ($r) => (array) $r)->all();
        }
        return [];
    }

    private function onlyColumns(string $table, array $data): array
    {
        return array_intersect_key($data, array_flip(Schema::getColumnListing($table)));
    }

    private function client(string $num): object
    {
        $c = DB::table('m_client')->where('client_num', $num)->first();
        abort_if($c === null, 404, 'Client not found.');
        return $c;
    }

    private function accountsOf(?string $clientNum = null)
    {
        $status = collect($this->options(['m_client_account_status'], ['status_name', 'description', 'name']))->pluck('label', 'id');
        $nature = collect($this->options(['m_nature_of_business'], ['description', 'name', 'business_nature']))->pluck('label', 'id');
        $names = DB::table('m_client')->get(['client_num', 'last_name', 'first_name', 'middle_name', 'suffix'])
            ->mapWithKeys(fn ($c) => [$c->client_num => $this->fullName($c)]);

        $rows = DB::table('m_client_account')
            ->when($clientNum !== null, fn ($q) => $q->where('client_num', $clientNum))
            ->orderBy('client_num')->orderBy('branch_code')->get();
        $logins = $this->loginsFor($rows->pluck('id')->map(fn ($v) => (int) $v)->all());

        return $rows
            ->map(function ($a) use ($status, $nature, $names, $logins) {
                $a = (array) $a;
                unset($a['password']);
                $a['login'] = $logins[(int) $a['id']] ?? null;
                $a['status_label'] = $status[$a['status_id'] ?? 0] ?? '';
                $a['nature_label'] = $nature[$a['nob_id'] ?? 0] ?? '';
                $a['client_name'] = $names[$a['client_num']] ?? '';
                return $a;
            })->values();
    }

    private function validIds(string $clientNum)
    {
        return ClientValidId::where('client_num', $clientNum)->orderBy('institution')->orderByDesc('id')
            ->get(['id', 'institution', 'id_num', 'client_num_type', 'created_at']);
    }

    private function fullName(object $c): string
    {
        $first = trim(implode(' ', array_filter([$c->first_name ?? '', $c->middle_name ?? '', $c->suffix ?? ''])));
        return trim(($c->last_name ?? '') . ($first !== '' ? ", {$first}" : ''));
    }

    /* =====================================================================
       Lists
       ===================================================================== */

    /* GET /api/clients/lookups   same lookups as Settings */
    public function lookups()
    {
        $botDetails = Schema::hasTable('m_business_ownership_type_detail')
            ? DB::table('m_business_ownership_type_detail')->orderBy('id')->get(['id', 'name', 'business_ownership_type_id'])
            : collect();
        $bot = collect($this->options(['m_business_ownership_type'], ['name', 'description']))
            ->sortBy('id')->values()
            ->map(fn ($t) => $t + ['details' => $botDetails->where('business_ownership_type_id', $t['id'])->values()->map(fn ($d) => ['id' => $d->id, 'label' => $d->name])->all()]);

        return response()->json([
            'has_rdo' => Schema::hasColumn('m_client_account', 'rdo'),
            'lookups' => [
                'civil'          => $this->options(['m_civil_status', 'm_civil_statuses'], ['description', 'name', 'civil_status']),
                'status'         => $this->options(['m_client_account_status'], ['status_name']),
                'bot'            => $bot,
                'compliance'     => $this->options(['m_compliance', 'm_compliances'], ['description', 'name', 'compliance']),
                'classification' => $this->options(['m_classification', 'm_classifications'], ['description', 'name', 'classification']),
                'industries'     => $this->options(['m_industries', 'm_industry'], ['description', 'name', 'industry']),
                'nature'         => $this->options(['m_nature_of_business'], ['description', 'name', 'business_nature']),
                'branch_types'   => $this->options(['m_branch_type'], ['name', 'description']),
                'tax_reg'        => $this->options(['m_tax_registration', 'm_tax_registrations'], ['description', 'name', 'registration']),
                'provinces'      => $this->options(['m_province'], ['province_name']),
            ],
        ]);
    }

    /** page + per_page from the request (per_page 1–200, default 25) */
    private function pageArgs(Request $request): array
    {
        $per = max(1, min(200, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        return [$page, $per];
    }

    /** LIMIT/OFFSET a query → { data, total, page, per_page, last_page } (+ counts for the two tabs) */
    private function paged($q, int $page, int $per, callable $map): array
    {
        $total = (clone $q)->count();
        $last = max(1, (int) ceil($total / $per));
        $page = min($page, $last);
        $rows = $q->offset(($page - 1) * $per)->limit($per)->get();
        return [
            'data' => $map($rows)->values(), 'total' => $total, 'page' => $page, 'per_page' => $per, 'last_page' => $last,
            'counts' => ['clients' => DB::table('m_client')->count(), 'accounts' => DB::table('m_client_account')->count()],
        ];
    }

    /** "%term%" search over several columns that exist */
    private function search($q, ?string $term, array $cols): void
    {
        $term = trim((string) $term);
        if ($term === '') return;
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
        $q->where(function ($w) use ($cols, $like) {
            foreach ($cols as $c) $w->orWhere(DB::raw($c), 'like', $like);
        });
    }

    /** [table, label column] of the first lookup table that exists */
    private function labelTable(array $tables, array $labels): array
    {
        foreach ($tables as $t) {
            if (!Schema::hasTable($t)) continue;
            $cols = Schema::getColumnListing($t);
            foreach ($labels as $l) if (in_array($l, $cols, true)) return [$t, $l];
        }
        return [null, null];
    }

    /* GET /api/clients?page=&per_page=&q=   one page of clients + their number of accounts */
    public function clients(Request $request)
    {
        [$page, $per] = $this->pageArgs($request);
        [$st, $sl] = $this->labelTable(['m_client_account_status'], ['status_name', 'description', 'name']);

        $q = DB::table('m_client as C')->select('C.*');
        if ($st) $q->leftJoin("{$st} as ST", 'ST.id', '=', 'C.status_id')->addSelect(DB::raw("ST.{$sl} AS status_label"));
        else $q->addSelect(DB::raw("'' AS status_label"));
        $this->search($q, $request->query('q'), array_filter([
            'C.client_num', 'C.last_name', 'C.first_name', 'C.middle_name', "CONCAT_WS(' ', C.first_name, C.last_name)",
            "CONCAT_WS(', ', C.last_name, C.first_name)", 'C.phone_num', 'C.tell_num', 'C.email_address', $st ? "ST.{$sl}" : null,
        ]));
        $q->orderBy('C.last_name')->orderBy('C.first_name')->orderBy('C.client_num');

        return response()->json($this->paged($q, $page, $per, function ($rows) {
            $counts = DB::table('m_client_account')->whereIn('client_num', $rows->pluck('client_num'))
                ->groupBy('client_num')->selectRaw('client_num, COUNT(*) AS n')->pluck('n', 'client_num');
            return $rows->map(function ($c) use ($counts) {
                unset($c->password);
                $c->full_name = $this->fullName($c);
                $c->accounts = (int) ($counts[$c->client_num] ?? 0);
                $c->status_label = $c->status_label ?? '';
                return $c;
            });
        }));
    }

    /* GET /api/clients/options   light list (client # + name) for the client filter */
    public function clientOptions()
    {
        return response()->json(DB::table('m_client')->orderBy('last_name')->orderBy('first_name')
            ->get(['client_num', 'last_name', 'first_name', 'middle_name', 'suffix'])
            ->map(fn ($c) => ['client_num' => $c->client_num, 'name' => $this->fullName($c)]));
    }

    /* GET /api/client-accounts?page=&per_page=&q=&client_num=&active=all|active|inactive   one page of client accounts */
    public function allAccounts(Request $request)
    {
        [$page, $per] = $this->pageArgs($request);
        [$st, $sl] = $this->labelTable(['m_client_account_status'], ['status_name', 'description', 'name']);
        [$nt, $nl] = $this->labelTable(['m_nature_of_business'], ['description', 'name', 'business_nature']);
        $hasRdo = Schema::hasColumn('m_client_account', 'rdo');

        $q = DB::table('m_client_account as A')
            ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
            ->select(['A.*', 'C.last_name', 'C.first_name', 'C.middle_name', 'C.suffix']);
        if ($st) $q->leftJoin("{$st} as ST", 'ST.id', '=', 'A.status_id')->addSelect(DB::raw("ST.{$sl} AS status_label"));
        if ($nt) $q->leftJoin("{$nt} as NB", 'NB.id', '=', 'A.nob_id')->addSelect(DB::raw("NB.{$nl} AS nature_label"));

        if ($n = trim((string) $request->query('client_num'))) $q->where('A.client_num', $n);
        $active = $request->query('active', 'all');
        if ($active === 'active') $q->where(fn ($w) => $w->whereNull('A.is_active')->orWhere('A.is_active', 1));
        if ($active === 'inactive') $q->where('A.is_active', 0);

        $this->search($q, $request->query('q'), array_filter([
            'A.trade_name', 'A.branch_code', 'A.branch_type', 'A.client_num', 'A.tax_registration', 'A.email_address',
            $hasRdo ? 'A.rdo' : null, 'C.last_name', 'C.first_name', "CONCAT_WS(' ', C.first_name, C.last_name)",
            $st ? "ST.{$sl}" : null, $nt ? "NB.{$nl}" : null,
            Schema::hasTable('m_user_client') ? "(SELECT GROUP_CONCAT(UL.username) FROM m_user_client UL WHERE UL.{$this->loginCaCol()} = A.id)" : null,
        ]));
        $q->orderBy('A.client_num')->orderBy('A.branch_code');

        return response()->json($this->paged($q, $page, $per, function ($rows) {
            $logins = $this->loginsFor($rows->pluck('id')->map(fn ($v) => (int) $v)->all());
            return $rows->map(function ($a) use ($logins) {
                unset($a->password);
                $a->login = $logins[(int) $a->id] ?? null;
                $a->client_name = $this->fullName($a);
                $a->status_label = $a->status_label ?? '';
                $a->nature_label = $a->nature_label ?? '';
                foreach (['last_name', 'first_name', 'middle_name', 'suffix'] as $k) unset($a->$k);
                return $a;
            });
        }));
    }

    /* GET /api/clients/{num}   one client + its accounts + valid IDs (the Settings data, for this client) */
    public function show(string $num)
    {
        return response()->json([
            'client' => $this->client($num),
            'accounts' => $this->accountsOf($num),
            'valid_ids' => $this->validIds($num),
        ]);
    }

    /* =====================================================================
       Client
       ===================================================================== */

    private function clientRules(): array
    {
        return [
            'last_name'                         => ['required', 'string', 'max:100'],
            'first_name'                        => ['nullable', 'string', 'max:100'],
            'middle_name'                       => ['nullable', 'string', 'max:100'],
            'suffix'                            => ['nullable', 'string', 'max:20'],
            'gender'                            => ['nullable', 'in:MALE,FEMALE'],
            'birthdate'                         => ['nullable', 'date'],
            'civ_stat_id'                       => ['nullable', 'integer'],
            'status_id'                         => ['required', 'integer', 'min:1'],
            'phone_num'                         => ['required', 'string', 'max:50'],
            'tell_num'                          => ['nullable', 'string', 'max:50'],
            'email_address'                     => ['nullable', 'email', 'max:150'],
            'street'                            => ['nullable', 'string', 'max:255'],
            'province_id'                       => ['nullable', 'integer'],
            'city_id'                           => ['nullable', 'integer'],
            'brgy_id'                           => ['nullable', 'integer'],
            'business_ownership_type_id'        => ['required', 'integer', 'min:1'],
            'business_ownership_type_detail_id' => ['nullable', 'integer'],
            'classification_id'                 => ['nullable', 'integer'],
            'industries_id'                     => ['nullable', 'integer'],
            'compliance_ids'                    => ['nullable', 'array'],
            'compliance_ids.*'                  => ['integer'],
            'date_of_engagement'                => ['nullable', 'date'],
            'date_of_disengagement'             => ['nullable', 'date'],
            'date_started'                      => ['nullable', 'date'],
            'date_registered'                   => ['nullable', 'date'],
        ];
    }

    private function clientMessages(): array
    {
        return [
            'last_name.required' => 'Last name is required.',
            'status_id.required' => 'Client status is required.', 'status_id.min' => 'Client status is required.',
            'phone_num.required' => 'Cellphone number is required.',
            'business_ownership_type_id.required' => 'Business ownership type is required.',
            'business_ownership_type_id.min' => 'Business ownership type is required.',
            'accounts.required' => 'Add at least one business account.', 'accounts.min' => 'Add at least one business account.',
        ];
    }

    private function cleanClient(array $d): array
    {
        $d['compliance_ids'] = implode(',', array_unique($d['compliance_ids'] ?? []));
        foreach (['civ_stat_id', 'status_id', 'province_id', 'city_id', 'brgy_id', 'business_ownership_type_id',
                  'business_ownership_type_detail_id', 'classification_id', 'industries_id'] as $k) {
            if (empty($d[$k])) $d[$k] = null;
        }
        return $d;
    }

    /** next client number: IGB-1104 → IGB-1105 (highest number after "IGB-" + 1) */
    private function nextClientNum(): string
    {
        $prefix = 'IGB-';
        $max = (int) DB::table('m_client')->where('client_num', 'like', $prefix . '%')->lockForUpdate()
            ->max(DB::raw("CAST(SUBSTRING(client_num, " . (strlen($prefix) + 1) . ") AS UNSIGNED)"));
        return $prefix . ($max + 1);
    }

    /* POST /api/clients   { …same fields as Settings, accounts: [ {business account}, … ] }  – client_num is generated (IGB-####) */
    public function storeClient(Request $request)
    {
        $rules = $this->clientRules() + ['accounts' => ['required', 'array', 'min:1']];
        foreach ($this->accountRules() as $k => $r) $rules["accounts.*.{$k}"] = $r;
        $d = $request->validate($rules, $this->clientMessages() + [
            'accounts.*.trade_name.required' => 'Every business account needs a trade name.',
            'accounts.*.branch_type.required' => 'Every business account needs a branch type.',
            'accounts.*.branch_code.required' => 'Every business account needs a branch code.',
        ] + $this->loginMessages('accounts.*.'));

        $codes = array_map(fn ($a) => trim($a['branch_code']), $d['accounts']);
        abort_if(count($codes) !== count(array_unique($codes)), 422, 'Two business accounts have the same branch code.');
        $names = array_values(array_filter(array_map(fn ($a) => strtolower(trim((string) ($a['login']['username'] ?? ''))), $d['accounts'])));
        abort_if(count($names) !== count(array_unique($names)), 422, 'Two business accounts have the same login username.');

        $accounts = $d['accounts'];
        unset($d['accounts']);
        $row = $this->cleanClient($d);

        $by = $request->user()?->id;
        $num = DB::transaction(function () use ($row, $accounts, $by) {
            $num = $this->nextClientNum();
            DB::table('m_client')->insert($this->onlyColumns('m_client', $row + ['client_num' => $num, 'created_at' => now(), 'updated_at' => now()]));
            foreach ($accounts as $a) {
                $a['branch_code'] = trim($a['branch_code']);
                $caid = DB::table('m_client_account')->insertGetId($this->onlyColumns('m_client_account',
                    $this->cleanAccount($a) + ['client_num' => $num, 'created_at' => now(), 'updated_at' => now()]));
                $uid = $this->saveLogin((int) $caid, $a['login'] ?? null, $by);
                if ($uid) $this->applyCoa($uid, $a['nob_id'] ?? null, array_key_exists('coa', $a) ? $a['coa'] : null);
            }
            return $num;
        });
        return response()->json(['client' => $this->client($num), 'accounts' => $this->accountsOf($num), 'valid_ids' => [], 'message' => "Client {$num} added."], 201);
    }

    /* PUT /api/clients/{num} */
    public function updateClient(Request $request, string $num)
    {
        $this->client($num);
        $d = $this->cleanClient($request->validate($this->clientRules(), $this->clientMessages()));
        abort_unless(DB::table('m_client_account')->where('client_num', $num)->exists(), 422, 'Add at least one business account.');
        DB::table('m_client')->where('client_num', $num)->update($this->onlyColumns('m_client', $d + ['updated_at' => now()]));
        return response()->json(['client' => $this->client($num), 'message' => 'Client saved.']);
    }

    /* =====================================================================
       Business accounts
       ===================================================================== */

    private function accountRules(): array
    {
        return [
            'trade_name'       => ['required', 'string', 'max:255'],
            'nob_id'           => ['nullable', 'integer'],
            'branch_type'      => ['required', 'string', 'max:20'],
            'branch_code'      => ['required', 'string', 'max:10'],
            'tax_registration' => ['nullable', 'string', 'max:50'],
            'rdo'              => ['nullable', 'string', 'max:10'],
            'status_id'        => ['nullable', 'integer'],
            'street'           => ['nullable', 'string', 'max:255'],
            'province_id'      => ['nullable', 'integer'],
            'city_id'          => ['nullable', 'integer'],
            'brgy_id'          => ['nullable', 'integer'],
            'phone_num'        => ['nullable', 'string', 'max:50'],
            'tell_num'         => ['nullable', 'string', 'max:50'],
            'email_address'    => ['nullable', 'email', 'max:150'],
            'is_active'        => ['boolean'],
            // new account only: account codes picked on the "Charts of account" tab (missing = the whole default chart)
            'coa'              => ['nullable', 'array'],
            'coa.*'            => ['string', 'max:20'],
        ] + $this->loginRules();
    }

    private function cleanAccount(array $d): array
    {
        foreach (['nob_id', 'status_id', 'province_id', 'city_id', 'brgy_id'] as $k) {
            if (empty($d[$k])) $d[$k] = null;
        }
        $d['branch_type'] = strtoupper($d['branch_type']);
        $d['is_active'] = !empty($d['is_active']) ? 1 : 0;
        return $this->onlyColumns('m_client_account', $d);
    }

    private function dupCode(string $num, string $code, ?int $ignore = null): bool
    {
        return DB::table('m_client_account')->where('client_num', $num)->where('branch_code', $code)
            ->when($ignore, fn ($q) => $q->where('id', '<>', $ignore))->exists();
    }

    private function account(int $id): object
    {
        $a = DB::table('m_client_account')->where('id', $id)->first();
        abort_if($a === null, 404, 'Business account not found.');
        return $a;
    }

    /* POST /api/clients/{num}/accounts */
    public function storeAccount(Request $request, string $num)
    {
        $this->client($num);
        $d = $request->validate($this->accountRules(), $this->loginMessages());
        abort_if($this->dupCode($num, $d['branch_code']), 422, 'That branch code is already used by this client.');

        $row = $this->cleanAccount($d) + ['client_num' => $num, 'created_at' => now(), 'updated_at' => now()];
        $by = $request->user()?->id;
        DB::transaction(function () use ($row, $d, $by) {
            $caid = DB::table('m_client_account')->insertGetId($this->onlyColumns('m_client_account', $row));
            $uid = $this->saveLogin((int) $caid, $d['login'] ?? null, $by);
            if ($uid) $this->applyCoa($uid, $d['nob_id'] ?? null, array_key_exists('coa', $d) ? $d['coa'] : null);
        });
        return response()->json(['accounts' => $this->accountsOf($num), 'message' => 'Business account added.'], 201);
    }

    /* PUT /api/client-accounts/{id} */
    public function updateAccount(Request $request, int $id)
    {
        $a = $this->account($id);
        $d = $request->validate($this->accountRules(), $this->loginMessages());
        abort_if($this->dupCode($a->client_num, $d['branch_code'], $id), 422, 'That branch code is already used by this client.');

        $by = $request->user()?->id;
        DB::transaction(function () use ($id, $d, $by) {
            DB::table('m_client_account')->where('id', $id)->update($this->cleanAccount($d) + ['updated_at' => now()]);
            $this->saveLogin($id, $d['login'] ?? null, $by);
        });
        return response()->json(['accounts' => $this->accountsOf($a->client_num), 'message' => 'Business account updated.']);
    }

    /* DELETE /api/client-accounts/{id}  { confirm: branch code } */
    public function destroyAccount(Request $request, int $id)
    {
        $a = $this->account($id);
        abort_unless(trim((string) $request->input('confirm')) === trim((string) $a->branch_code), 422, 'Type the branch code exactly to confirm.');
        abort_if(DB::table('m_client_account')->where('client_num', $a->client_num)->count() <= 1, 422, 'A client needs at least one business account, so its last account can\'t be deleted.');
        try {
            DB::table('m_client_account')->where('id', $id)->delete();
        } catch (QueryException $e) {
            abort(422, "This branch already has users or transactions, so it can't be deleted. Set its status to inactive instead.");
        }
        return response()->json(['accounts' => $this->accountsOf($a->client_num), 'message' => 'Business account deleted.']);
    }

    /* =====================================================================
       Valid IDs
       ===================================================================== */

    private function idRules(): array
    {
        return ['institution' => ['required', 'string', 'max:60'], 'id_num' => ['required', 'string', 'max:60']];
    }

    private function checkTin(array $d): void
    {
        if (strtoupper(trim($d['institution'])) === 'BIR') {
            abort_unless(preg_match('/^\d{3}-\d{3}-\d{3}$/', trim($d['id_num'])), 422, 'Enter the TIN as 000-000-000 (9 digits).');
        }
    }

    /* POST /api/clients/{num}/valid-ids */
    public function storeValidId(Request $request, string $num)
    {
        $this->client($num);
        $d = $request->validate($this->idRules());
        $this->checkTin($d);
        ClientValidId::create([
            'client_num' => $num, 'client_num_type' => 'CLIENT',
            'institution' => strtoupper(trim($d['institution'])), 'id_num' => trim($d['id_num']),
        ]);
        return response()->json(['valid_ids' => $this->validIds($num)], 201);
    }

    /* PUT /api/client-valid-ids/{id} */
    public function updateValidId(Request $request, int $id)
    {
        $row = ClientValidId::find($id);
        abort_if($row === null, 404, 'ID not found.');
        $d = $request->validate($this->idRules());
        $this->checkTin($d);
        $row->institution = strtoupper(trim($d['institution']));
        $row->id_num = trim($d['id_num']);
        $row->save();
        return response()->json(['valid_ids' => $this->validIds($row->client_num)]);
    }

    /* DELETE /api/client-valid-ids/{id} */
    public function destroyValidId(int $id)
    {
        $row = ClientValidId::find($id);
        abort_if($row === null, 404, 'ID not found.');
        $num = $row->client_num;
        $row->delete();
        return response()->json(['valid_ids' => $this->validIds($num)]);
    }


    /* =====================================================================
       Logins of a business account (m_user_client: id, caid, username, password, is_active, is_pos, is_web, is_mobile)
    ===================================================================== */
        const USER_FLAGS = ['is_active', 'is_pos', 'is_web', 'is_mobile'];

    private function loginRules(string $prefix = ''): array
    {
        return [
            "{$prefix}login"           => ['nullable', 'array'],
            "{$prefix}login.username"  => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^\S+$/'],
            "{$prefix}login.password"  => ['nullable', 'string', 'min:6', 'max:100'],
            "{$prefix}login.is_active" => ['boolean'],
            "{$prefix}login.is_pos"    => ['boolean'],
            "{$prefix}login.is_web"    => ['boolean'],
            "{$prefix}login.is_mobile" => ['boolean'],
        ];
    }

    private function loginMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}login.username.min"   => 'Username needs at least 3 characters.',
            "{$prefix}login.username.regex" => 'Username can\'t have spaces.',
            "{$prefix}login.password.min"   => 'Password needs at least 6 characters.',
        ];
    }

    private function loginCaCol(): string
    {
        static $col = null;
        return $col ??= (Schema::hasColumn('m_user_client', 'caid') ? 'caid' : 'ca_id');
    }

    private function loginsFor(array $caids): array
    {
        if (!$caids || !Schema::hasTable('m_user_client')) return [];
        $ca = $this->loginCaCol();
        $cols = array_values(array_intersect(['id', $ca, 'username', ...self::USER_FLAGS], Schema::getColumnListing('m_user_client')));
        $out = [];
        foreach (DB::table('m_user_client')->whereIn($ca, $caids)->orderBy('id')->get($cols) as $u) {
            $key = (int) $u->$ca;
            if (isset($out[$key])) continue;
            $login = ['id' => (int) $u->id, 'username' => $u->username];
            foreach (self::USER_FLAGS as $f) if (property_exists($u, $f)) $login[$f] = (int) $u->$f === 1;
            $out[$key] = $login;
        }
        return $out;
    }

    private function usernameTaken(string $username, ?int $ignoreId = null): bool
    {
        foreach (['m_user_client', 'm_user_client_staff', 'm_user_staff'] as $t) {
            if (!Schema::hasTable($t)) continue;
            $q = DB::table($t)->where('username', $username);
            if ($t === 'm_user_client' && $ignoreId) $q->where('id', '!=', $ignoreId);
            if ($q->exists()) return true;
        }
        return false;
    }

    /** @return int|null the m_user_client id (null = no login) */
    private function saveLogin(int $caid, ?array $login, ?int $by): ?int
    {
        $username = trim((string) ($login['username'] ?? ''));
        if ($username === '') return null;

        $ca = $this->loginCaCol();
        $existing = DB::table('m_user_client')->where($ca, $caid)->orderBy('id')->first();
        abort_if($this->usernameTaken($username, $existing?->id), 422, "The username \"{$username}\" is already taken.");

        $row = ['username' => $username];
        foreach (self::USER_FLAGS as $f) if (array_key_exists($f, $login)) $row[$f] = $login[$f] ? 1 : 0;
        if (!empty($login['password'])) $row['password'] = Hash::make($login['password']);

        if ($existing) {
            DB::table('m_user_client')->where('id', $existing->id)
                ->update($this->onlyColumns('m_user_client', $row + ['updated_by' => $by, 'updated_at' => now()]));
            return (int) $existing->id;
        }
        abort_if(empty($login['password']), 422, "Enter a password for the login \"{$username}\".");
        return (int) DB::table('m_user_client')->insertGetId($this->onlyColumns('m_user_client', $row + ['is_active' => 1] + [
            $ca => $caid, 'added_by' => $by, 'updated_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /* =====================================================================
       Default Charts of Account per Nature of business (m_nature_coa_template → m_charts_of_account)
       ===================================================================== */

    /** template rows of a nature; a nature without its own chart uses the general one (nature_of_business_id NULL) */
    private function coaTemplateRows(?int $nobId)
    {
        if (!Schema::hasTable('m_nature_coa_template')) return collect();
        $rows = $nobId ? DB::table('m_nature_coa_template')->where('nature_of_business_id', $nobId)->orderBy('account_code')->get() : collect();
        if ($rows->isEmpty()) $rows = DB::table('m_nature_coa_template')->whereNull('nature_of_business_id')->orderBy('account_code')->get();
        return $rows;
    }

    /* GET /api/client-coa-template?nob_id=   the default chart shown on the "Charts of account" tab */
    public function coaTemplate(Request $request)
    {
        $nob = (int) $request->query('nob_id') ?: null;
        $rows = $this->coaTemplateRows($nob);
        $own = $nob && $rows->isNotEmpty() && (int) ($rows->first()->nature_of_business_id ?? 0) === $nob;
        return response()->json([
            'source'   => $rows->isEmpty() ? 'none' : ($own ? 'nature' : 'general'),
            'template' => $rows->first()->template ?? null,
            'rows'     => $rows->map(fn ($r) => [
                'code' => (string) $r->account_code, 'name' => $r->account_name, 'type' => $r->account_type,
                'parent' => (string) $r->root_name === '0' ? null : (string) $r->root_name,
                'is_sales' => (bool) $r->is_sales, 'is_purchases' => (bool) $r->is_purchases,
                'is_expenses' => (bool) $r->is_expenses, 'is_for_paid' => (bool) $r->is_for_paid,
            ])->values(),
        ]);
    }

    /**
     * Copy the default chart into m_charts_of_account for a new login (added_by = m_user_client id).
     * $codes null = the whole default chart · [] = none · otherwise those codes (+ their parent headers).
     * Codes that login already has are skipped.
     */
    private function applyCoa(int $userId, $nobId, ?array $codes): int
    {
        if ($codes !== null && !$codes) return 0;
        if (!Schema::hasTable('m_charts_of_account')) return 0;
        $rows = $this->coaTemplateRows((int) $nobId ?: null)->keyBy(fn ($r) => (string) $r->account_code);
        if ($rows->isEmpty()) return 0;

        $keys = array_map('strval', $rows->keys()->all());           // numeric codes become int array keys → compare as strings
        $want = $codes === null ? $keys : array_values(array_intersect(array_map('strval', $codes), $keys));
        foreach ($want as $code) {                                   // keep the tree whole: add every parent header
            $p = (string) ($rows[$code]->root_name ?? '0');
            while ($p !== '0' && $p !== '' && isset($rows[$p]) && !in_array($p, $want, true)) {
                $want[] = $p;
                $p = (string) $rows[$p]->root_name;
            }
        }

        $have = DB::table('m_charts_of_account')->where('added_by', $userId)->pluck('account_code')->map(fn ($c) => (string) $c)->all();
        $now = now();
        $cols = array_flip(Schema::getColumnListing('m_charts_of_account'));
        $insert = [];
        foreach ($rows as $code => $r) {
            $code = (string) $code;
            if (!in_array($code, $want, true) || in_array($code, $have, true)) continue;
            $insert[] = array_intersect_key([
                'root_name' => (string) $r->root_name, 'account_code' => $code, 'account_name' => $r->account_name,
                'account_type' => $r->account_type, 'description' => $r->description,
                'is_for_paid' => (int) $r->is_for_paid, 'is_sales' => (int) $r->is_sales,
                'is_purchases' => (int) $r->is_purchases, 'is_expenses' => (int) $r->is_expenses,
                'added_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ], $cols);
        }
        foreach (array_chunk($insert, 200) as $chunk) DB::table('m_charts_of_account')->insert($chunk);
        return count($insert);
    }
    /* =====================================================================
       Master File → Nature of Business → "Charts of account" popup: edit the default chart of a nature
       nob 0 = the general chart (used by natures without their own)

       GET    /nature-coa/{nob}                 rows (+ whether it is the nature's own chart)
       POST   /nature-coa/{nob}                 add an account
       PUT    /nature-coa/{nob}/{code}          edit an account
       DELETE /nature-coa/{nob}/{code}          delete an account (not while it has sub-accounts)
       POST   /nature-coa/{nob}/copy-general    give the nature its own chart, starting from the general one
       ===================================================================== */

    private const COA_TYPES = ['Asset', 'Liability', 'Equity', 'Revenue', 'Cost of Sales', 'Expense', 'Other Income', 'Other Expense'];

    private function natureCoaScope(int $nob)
    {
        abort_unless(Schema::hasTable('m_nature_coa_template'), 422, 'Run nature_of_business_coa.sql first (table m_nature_coa_template is missing).');
        if ($nob > 0) abort_unless(DB::table('m_nature_of_business')->where('id', $nob)->exists(), 404, 'Nature of business not found.');
        return DB::table('m_nature_coa_template')->when($nob > 0, fn ($q) => $q->where('nature_of_business_id', $nob), fn ($q) => $q->whereNull('nature_of_business_id'));
    }

    private function natureCoaRows(int $nob): array
    {
        return $this->natureCoaScope($nob)->orderBy('account_code')->get()->map(fn ($r) => [
            'code' => (string) $r->account_code, 'name' => $r->account_name, 'type' => $r->account_type,
            'parent' => (string) $r->root_name === '0' ? '' : (string) $r->root_name, 'description' => $r->description,
            'is_sales' => (bool) $r->is_sales, 'is_purchases' => (bool) $r->is_purchases,
            'is_expenses' => (bool) $r->is_expenses, 'is_for_paid' => (bool) $r->is_for_paid,
        ])->values()->all();
    }

    public function natureCoa(int $nob)
    {
        $rows = $this->natureCoaRows($nob);
        $general = $nob > 0 && !$rows ? count($this->natureCoaRows(0)) : 0;
        return response()->json([
            'nature' => $nob > 0 ? (string) DB::table('m_nature_of_business')->where('id', $nob)->value('description') : 'General (natures without their own chart)',
            'own' => $nob === 0 || (bool) $rows,
            'general_count' => $general,
            'types' => self::COA_TYPES,
            'rows' => $rows,
        ]);
    }

    private function natureCoaValues(Request $request, int $nob, ?string $code = null): array
    {
        $d = $request->validate([
            'code'         => ['required', 'regex:/^\d{3,10}$/'],
            'name'         => ['required', 'string', 'max:255'],
            'type'         => ['required', 'in:' . implode(',', self::COA_TYPES)],
            'parent'       => ['nullable', 'string', 'max:20'],
            'description'  => ['nullable', 'string', 'max:255'],
            'is_sales'     => ['boolean'], 'is_purchases' => ['boolean'], 'is_expenses' => ['boolean'], 'is_for_paid' => ['boolean'],
        ], ['code.regex' => 'The account code must be numbers only (e.g. 61500).', 'code.required' => 'Enter the account code.', 'name.required' => 'Enter the account name.']);

        $parent = trim((string) ($d['parent'] ?? ''));
        if ($parent !== '') {
            abort_if($parent === $d['code'], 422, "An account can't be its own parent.");
            abort_unless($this->natureCoaScope($nob)->where('account_code', $parent)->exists(), 422, "Parent account {$parent} is not in this chart.");
            // no loops: the parent can't be one of this account's sub-accounts
            for ($p = $parent, $i = 0; $p !== '0' && $p !== '' && $i < 20; $i++) {
                abort_if($code !== null && $p === $code, 422, "Account {$parent} is under {$code}, so it can't be its parent.");
                $p = (string) ($this->natureCoaScope($nob)->where('account_code', $p)->value('root_name') ?? '0');
            }
        }
        $dup = $this->natureCoaScope($nob)->where('account_code', $d['code'])->when($code !== null, fn ($q) => $q->where('account_code', '<>', $code))->exists();
        abort_if($dup, 422, "Account code {$d['code']} is already in this chart.");

        return [
            'root_name' => $parent === '' ? '0' : $parent, 'account_code' => $d['code'], 'account_name' => trim($d['name']),
            'account_type' => $d['type'], 'description' => $d['description'] ?? null,
            'is_sales' => !empty($d['is_sales']) ? 1 : 0, 'is_purchases' => !empty($d['is_purchases']) ? 1 : 0,
            'is_expenses' => !empty($d['is_expenses']) ? 1 : 0, 'is_for_paid' => !empty($d['is_for_paid']) ? 1 : 0,
            'updated_at' => now(),
        ];
    }

    /** a nature that still uses the general chart gets its own copy before its first change */
    private function natureCoaOwn(int $nob): void
    {
        if ($nob === 0 || $this->natureCoaScope($nob)->exists()) return;
        $now = now();
        $rows = $this->natureCoaScope(0)->get()->map(fn ($r) => [
            'nature_of_business_id' => $nob, 'template' => $r->template ?? 'GENERAL', 'root_name' => $r->root_name,
            'account_code' => $r->account_code, 'account_name' => $r->account_name, 'account_type' => $r->account_type,
            'description' => $r->description, 'is_for_paid' => $r->is_for_paid, 'is_sales' => $r->is_sales,
            'is_purchases' => $r->is_purchases, 'is_expenses' => $r->is_expenses, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        foreach (array_chunk($rows, 200) as $chunk) DB::table('m_nature_coa_template')->insert($chunk);
    }

    public function natureCoaCopyGeneral(int $nob)
    {
        abort_if($nob === 0, 422, 'This is already the general chart.');
        DB::transaction(fn () => $this->natureCoaOwn($nob));
        return response()->json(['rows' => $this->natureCoaRows($nob), 'own' => true, 'message' => 'This nature now has its own chart (copied from the general one).']);
    }

    public function storeNatureCoa(Request $request, int $nob)
    {
        $this->natureCoaScope($nob);
        DB::transaction(function () use ($request, $nob) {
            $this->natureCoaOwn($nob);
            $row = $this->natureCoaValues($request, $nob);
            DB::table('m_nature_coa_template')->insert($row + ['nature_of_business_id' => $nob ?: null, 'template' => 'CUSTOM', 'created_at' => now()]);
        });
        return response()->json(['rows' => $this->natureCoaRows($nob), 'own' => true, 'message' => 'Account added.'], 201);
    }

    public function updateNatureCoa(Request $request, int $nob, string $code)
    {
        $this->natureCoaScope($nob);
        DB::transaction(function () use ($request, $nob, $code) {
            $this->natureCoaOwn($nob);
            abort_unless($this->natureCoaScope($nob)->where('account_code', $code)->exists(), 404, 'Account not found.');
            $row = $this->natureCoaValues($request, $nob, $code);
            $this->natureCoaScope($nob)->where('account_code', $code)->update($row);
            // a changed code moves its sub-accounts along
            if ($row['account_code'] !== $code) $this->natureCoaScope($nob)->where('root_name', $code)->update(['root_name' => $row['account_code'], 'updated_at' => now()]);
        });
        return response()->json(['rows' => $this->natureCoaRows($nob), 'own' => true, 'message' => 'Account updated.']);
    }

    public function destroyNatureCoa(int $nob, string $code)
    {
        $this->natureCoaScope($nob);
        DB::transaction(function () use ($nob, $code) {
            $this->natureCoaOwn($nob);
            abort_unless($this->natureCoaScope($nob)->where('account_code', $code)->exists(), 404, 'Account not found.');
            abort_if($this->natureCoaScope($nob)->where('root_name', $code)->exists(), 422, "Account {$code} has sub-accounts. Delete or move those first.");
            $this->natureCoaScope($nob)->where('account_code', $code)->delete();
        });
        return response()->json(['rows' => $this->natureCoaRows($nob), 'own' => true, 'message' => 'Account deleted.']);
    }

    /* =========================================================================
       CLIENT → MASTER FILE  (old pages/Client/Master_File: doGetMasterFileList / doSaveComplex / doDeleteMasterFile)
       + the Masterlist dropdowns that had no screen of their own.

       GET    /client-master-files                    the files + their fields (+ options for select fields)
       GET    /client-master-files/{key}?q=&page=&per_page=
       POST   /client-master-files/{key}
       PUT    /client-master-files/{key}/{id}
       DELETE /client-master-files/{key}/{id}
       ========================================================================= */

    /**
     * key => [label, group, table candidates, fields]
     * field: [key, label, type text|textarea|rich|select, column candidates, required, (select) source file key]
     */
    private const MASTER_FILES = [
        'bot' => ['Business Ownership', 'Master files', ['m_business_ownership_type'], [
            ['name', 'Business ownership type', 'text', ['name', 'business_ownership_type'], true],
            ['description', 'Description', 'textarea', ['description'], false],
        ]],
        'botd' => ['Sub Business Ownership', 'Master files', ['m_business_ownership_type_detail'], [
            ['parent', 'Main business ownership', 'select', ['business_ownership_type_id'], true, 'bot'],
            ['name', 'Sub business ownership', 'text', ['name', 'business_ownership_type_detail'], true],
        ]],
        'compliance' => ['Compliance', 'Master files', ['m_compliance', 'm_compliances'], [
            ['name', 'Compliance', 'text', ['name', 'compliance', 'description'], true],
            ['definition', 'Definition', 'rich', ['defination', 'definition'], false],
            ['deadline', 'Deadline', 'rich', ['deadline'], false],
        ]],
        'classification' => ['Classification', 'Master files', ['m_classification', 'm_classifications'], [
            ['name', 'Category', 'text', ['name', 'classification'], true],
            ['description', 'Description', 'rich', ['description'], false],
        ]],
        'industry' => ['Industry', 'Master files', ['m_industries', 'm_industry'], [
            ['name', 'Industry', 'text', ['name', 'industry', 'description'], true],
        ]],
        'documents' => ['Documents', 'Master files', ['m_documents', 'm_document', 'm_document_list'], [
            ['alias', 'Alias', 'text', ['alias'], false],
            ['name', 'Document name', 'text', ['document_name', 'name', 'description'], true],
        ]],
        // Masterlist dropdowns with no screen of their own
        'civil_status' => ['Civil Status', 'Dropdowns', ['m_civil_status', 'm_civil_statuses'], [
            ['name', 'Civil status', 'text', ['description', 'name', 'civil_status'], true],
        ]],
        'account_status' => ['Client Account Status', 'Dropdowns', ['m_client_account_status'], [
            ['name', 'Status', 'text', ['status_name', 'name', 'description'], true],
        ]],
        'nature' => ['Nature of Business', 'Dropdowns', ['m_nature_of_business'], [
            ['name', 'Nature of business', 'text', ['description', 'name', 'business_nature'], true],
        ]],
        'branch_type' => ['Branch Type', 'Dropdowns', ['m_branch_type'], [
            ['name', 'Branch type', 'text', ['name', 'description'], true],
        ]],
        'tax_registration' => ['Tax Registration', 'Dropdowns', ['m_tax_registration', 'm_tax_registrations'], [
            ['name', 'Tax registration', 'text', ['description', 'name', 'registration'], true],
        ]],
    ];

    /** where each file / child points (so a parent can't be deleted while in use) */
    private const MASTER_CHILDREN = ['bot' => ['botd', 'parent']];

    /** resolved: [label, group, table|null, fields[key => {label,type,col,required,source}]] */
    private function mfResolve(string $key): array
    {
        abort_unless(isset(self::MASTER_FILES[$key]), 404, 'Unknown master file.');
        [$label, $group, $tables, $fields] = self::MASTER_FILES[$key];
        $table = collect($tables)->first(fn ($t) => Schema::hasTable($t));
        $cols = $table ? Schema::getColumnListing($table) : [];
        $used = [];
        $out = [];
        foreach ($fields as $f) {
            $col = collect($f[3])->first(fn ($c) => in_array($c, $cols, true) && !in_array($c, $used, true));
            if ($col) $used[] = $col;
            $out[$f[0]] = ['label' => $f[1], 'type' => $f[2], 'col' => $col, 'required' => $f[4], 'source' => $f[5] ?? null];
        }
        return [$label, $group, $table, $out, $cols];
    }

    /** {id, label} of a file (for select fields) */
    private function mfOptions(string $key): array
    {
        [, , $table, $fields] = $this->mfResolve($key);
        $col = $fields['name']['col'] ?? null;
        if (!$table || !$col) return [];
        return DB::table($table)->orderBy($col)->get(['id', "{$col} as label"])->map(fn ($r) => ['id' => (int) $r->id, 'label' => (string) $r->label])->all();
    }

    /** rich text from the editor: keep simple formatting tags only */
    private function mfClean(?string $html): string
    {
        $html = (string) $html;
        $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><br><b><strong><i><em><u><s><strike><ol><ul><li><h1><h2><h3><span><div>');
        $html = preg_replace('/\s(?!class=|style=)[a-z-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);   // only class / style stay
        $html = preg_replace_callback('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', function ($m) {   // style: text-align only
            return preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $m[1], $a) ? ' style="text-align: ' . strtolower($a[1]) . ';"' : '';
        }, $html);
        $html = trim($html);
        return in_array($html, ['<p><br></p>', '<br>', '<div><br></div>'], true) ? '' : $html;
    }

    public function masterFiles()
    {
        $list = [];
        foreach (array_keys(self::MASTER_FILES) as $key) {
            [$label, $group, $table, $fields] = $this->mfResolve($key);
            $list[] = [
                'key' => $key, 'label' => $label, 'group' => $group, 'ready' => (bool) $table,
                'count' => $table ? DB::table($table)->count() : 0,
                'fields' => collect($fields)->filter(fn ($f) => $f['col'])->map(fn ($f, $k) => [
                    'key' => $k, 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'],
                ] + ($f['type'] === 'select' ? ['options' => $this->mfOptions($f['source'])] : []))->values(),
            ];
        }
        return response()->json($list);
    }

    public function masterFileRows(Request $request, string $key)
    {
        [$label, , $table, $fields] = $this->mfResolve($key);
        abort_unless($table, 422, "{$label}: table not found. Run the migration.");
        [$page, $per] = $this->pageArgs($request);

        $q = DB::table("{$table} as M");
        $select = ['M.id'];
        foreach ($fields as $k => $f) {
            if (!$f['col']) continue;
            $select[] = DB::raw("M.{$f['col']} AS `{$k}`");
            if ($f['type'] === 'select') {
                [, , $pt, $pf] = $this->mfResolve($f['source']);
                $pc = $pf['name']['col'] ?? null;
                if ($pt && $pc) {
                    $q->leftJoin("{$pt} as P", 'P.id', '=', "M.{$f['col']}");
                    $select[] = DB::raw("P.{$pc} AS `{$k}_label`");
                }
            }
        }
        $searchCols = collect($fields)->filter(fn ($f) => $f['col'] && $f['type'] !== 'select')->map(fn ($f) => "M.{$f['col']}")->values()->all();
        if (collect($fields)->contains(fn ($f) => $f['type'] === 'select' && $f['col'])) {
            $pf = $this->mfResolve('bot')[3]['name']['col'] ?? null;
            if ($pf) $searchCols[] = "P.{$pf}";
        }
        $this->search($q, $request->query('q'), $searchCols);
        $q->orderByDesc('M.id')->select($select);    // old: ORDER BY ID DESC

        $total = (clone $q)->count();
        $last = max(1, (int) ceil($total / $per));
        $page = min($page, $last);
        $rows = $q->offset(($page - 1) * $per)->limit($per)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => (array) $r)->values(),
            'total' => $total, 'page' => $page, 'per_page' => $per, 'last_page' => $last,
        ]);
    }

    private function mfValues(Request $request, string $key, ?int $id = null): array
    {
        [$label, , $table, $fields, $cols] = $this->mfResolve($key);
        abort_unless($table, 422, "{$label}: table not found. Run the migration.");

        $rules = [];
        $names = [];
        foreach ($fields as $k => $f) {
            if (!$f['col']) continue;
            $r = [$f['required'] ? 'required' : 'nullable'];
            $r[] = $f['type'] === 'select' ? 'integer' : 'string';
            if ($f['type'] === 'text') $r[] = 'max:255';
            if ($f['type'] === 'select') {
                [, , $pt] = $this->mfResolve($f['source']);
                if ($pt) $r[] = "exists:{$pt},id";
            }
            $rules[$k] = $r;
            $names[$k] = strtolower($f['label']);
        }
        $data = $request->validate($rules, [
            'required' => ':attribute is required.',
            'exists'   => 'Pick a valid :attribute.',
        ], $names);

        $row = [];
        foreach ($fields as $k => $f) {
            if (!$f['col']) continue;
            $v = $data[$k] ?? null;
            $row[$f['col']] = match ($f['type']) {
                'select' => $v !== null ? (int) $v : null,
                'rich'   => $this->mfClean($v),
                default  => trim((string) $v),
            };
        }

        // no duplicates (same name, and same parent for sub business ownership)
        $nameCol = $fields['name']['col'] ?? null;
        if ($nameCol) {
            $dup = DB::table($table)->whereRaw("LOWER(TRIM({$nameCol})) = ?", [mb_strtolower($row[$nameCol])])
                ->when($id, fn ($q) => $q->where('id', '<>', $id))
                ->when(isset($fields['parent']['col']), fn ($q) => $q->where($fields['parent']['col'], $row[$fields['parent']['col']]))
                ->exists();
            abort_if($dup, 422, "{$row[$nameCol]} already exists in {$label}.");
        }

        $now = now();
        $by = (int) ($request->user()->id ?? 0) ?: null;
        if (in_array('updated_at', $cols, true)) $row['updated_at'] = $now;
        if (in_array('updated_by', $cols, true)) $row['updated_by'] = $by;
        if (!$id) {
            if (in_array('created_at', $cols, true)) $row['created_at'] = $now;
            foreach (['added_by', 'created_by', 'entered_by'] as $c) if (in_array($c, $cols, true)) $row[$c] = $by;
        }
        return [$table, $row, $label];
    }

    public function storeMasterFile(Request $request, string $key)
    {
        [$table, $row] = $this->mfValues($request, $key);
        $id = DB::table($table)->insertGetId($row);
        return response()->json(['message' => 'Saved successfully', 'id' => $id], 201);
    }

    public function updateMasterFile(Request $request, string $key, int $id)
    {
        [, , $table] = $this->mfResolve($key);
        abort_unless($table && DB::table($table)->where('id', $id)->exists(), 404, 'Record not found.');
        [$table, $row] = $this->mfValues($request, $key, $id);
        DB::table($table)->where('id', $id)->update($row);
        return response()->json(['message' => 'Saved successfully', 'id' => $id]);
    }

    public function destroyMasterFile(string $key, int $id)
    {
        [$label, , $table] = $this->mfResolve($key);
        abort_unless($table && DB::table($table)->where('id', $id)->exists(), 404, 'Record not found.');

        if (isset(self::MASTER_CHILDREN[$key])) {
            [$childKey, $field] = self::MASTER_CHILDREN[$key];
            [$cl, , $ct, $cf] = $this->mfResolve($childKey);
            if ($ct && ($cc = $cf[$field]['col'] ?? null) && DB::table($ct)->where($cc, $id)->exists()) {
                abort(422, "This is used by {$cl}. Delete or move those first.");
            }
        }
        try {
            DB::table($table)->where('id', $id)->delete();
        } catch (QueryException $e) {
            abort(422, "This {$label} is still used by clients, so it can't be deleted.");
        }
        return response()->json(['message' => 'Deleted successfully']);
    }
}