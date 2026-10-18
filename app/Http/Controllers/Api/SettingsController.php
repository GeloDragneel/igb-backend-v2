<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientAccount;
use App\Models\ClientValidId;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingsController extends SalesController
{
    /* ---------- helpers ---------- */

    private function clientNum(Request $request): string
    {
        $caid = $this->caidFor($request);
        $num = $caid ? ClientAccount::where('id', $caid)->value('client_num') : null;
        abort_unless($num, 422, 'No client is selected.');
        return (string) $num;
    }

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

    // keep only columns that exist in the table
    private function onlyColumns(string $table, array $data): array
    {
        $cols = Schema::getColumnListing($table);
        return array_intersect_key($data, array_flip($cols));
    }

    private function accounts(string $clientNum)
    {
        $status = collect($this->options(['m_client_account_status'], ['description', 'name']))->pluck('label', 'id');
        $nature = collect($this->options(['m_nature_of_business'], ['description', 'name', 'business_nature']))->pluck('label', 'id');

        return DB::table('m_client_account')->where('client_num', $clientNum)->orderBy('branch_code')->get()
            ->map(function ($a) use ($status, $nature) {
                $a = (array) $a;
                $a['status_label'] = $status[$a['status_id'] ?? 0] ?? '';
                $a['nature_label'] = $nature[$a['nob_id'] ?? 0] ?? '';
                return $a;
            })->values();
    }

    private function validIds(string $clientNum)
    {
        return ClientValidId::where('client_num', $clientNum)->orderBy('institution')->orderByDesc('id')
            ->get(['id', 'institution', 'id_num', 'client_num_type', 'created_at']);
    }

    /* ---------- GET /api/settings ---------- */
    public function settingsShow(Request $request)
    {
        $num = $this->clientNum($request);
        $client = DB::table('m_client')->where('client_num', $num)->first();
        abort_if($client === null, 404, 'Client record not found.');

        $botDetails = Schema::hasTable('m_business_ownership_type_detail')
            ? DB::table('m_business_ownership_type_detail')->orderBy('id')->get(['id', 'name', 'business_ownership_type_id'])
            : collect();
        $bot = collect($this->options(['m_business_ownership_type'], ['name', 'description']))
            ->sortBy('id')->values()
            ->map(fn ($t) => $t + ['details' => $botDetails->where('business_ownership_type_id', $t['id'])->values()->map(fn ($d) => ['id' => $d->id, 'label' => $d->name])->all()]);

        return response()->json([
            'client'    => $client,
            'accounts'  => $this->accounts($num),
            'valid_ids' => $this->validIds($num),
            'has_rdo'   => Schema::hasColumn('m_client_account', 'rdo'),
            'lookups'   => [
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

    /* GET /api/settings/cities?province_id=… */
    public function cities(Request $request)
    {
        $pid = (int) $request->query('province_id');
        return response()->json(DB::table('m_city_mun')->when($pid, fn ($q) => $q->where('province_id', $pid))
            ->orderBy('description')->get(['id', 'description as label', 'zip_code', 'province_id']));
    }

    /* GET /api/settings/barangays?city_id=… */
    public function barangays(Request $request)
    {
        $cid = (int) $request->query('city_id');
        abort_unless($cid, 422, 'Choose a city first.');
        return response()->json(DB::table('m_barangay')->where('city_id', $cid)->orderBy('description')->get(['id', 'description as label']));
    }

    /* PUT /api/settings/client */
    public function updateClient(Request $request)
    {
        $num = $this->clientNum($request);
        $d = $request->validate([
            'last_name'                         => ['required', 'string', 'max:100'],
            'first_name'                        => ['nullable', 'string', 'max:100'],
            'middle_name'                       => ['nullable', 'string', 'max:100'],
            'suffix'                            => ['nullable', 'string', 'max:20'],
            'gender'                            => ['nullable', 'in:MALE,FEMALE'],
            'birthdate'                         => ['nullable', 'date'],
            'civ_stat_id'                       => ['nullable', 'integer'],
            'status_id'                         => ['nullable', 'integer'],
            'phone_num'                         => ['nullable', 'string', 'max:50'],
            'tell_num'                          => ['nullable', 'string', 'max:50'],
            'email_address'                     => ['nullable', 'email', 'max:150'],
            'street'                            => ['nullable', 'string', 'max:255'],
            'province_id'                       => ['nullable', 'integer'],
            'city_id'                           => ['nullable', 'integer'],
            'brgy_id'                           => ['nullable', 'integer'],
            'business_ownership_type_id'        => ['nullable', 'integer'],
            'business_ownership_type_detail_id' => ['nullable', 'integer'],
            'classification_id'                 => ['nullable', 'integer'],
            'industries_id'                     => ['nullable', 'integer'],
            'compliance_ids'                    => ['nullable', 'array'],
            'compliance_ids.*'                  => ['integer'],
            'date_of_engagement'                => ['nullable', 'date'],
            'date_of_disengagement'             => ['nullable', 'date'],
            'date_started'                      => ['nullable', 'date'],
            'date_registered'                   => ['nullable', 'date'],
        ]);

        $d['compliance_ids'] = implode(',', array_unique($d['compliance_ids'] ?? []));
        foreach (['civ_stat_id', 'status_id', 'province_id', 'city_id', 'brgy_id', 'business_ownership_type_id',
                  'business_ownership_type_detail_id', 'classification_id', 'industries_id'] as $k) {
            if (empty($d[$k])) $d[$k] = null;
        }
        $d['updated_at'] = now();

        DB::table('m_client')->where('client_num', $num)->update($this->onlyColumns('m_client', $d));
        return response()->json(['client' => DB::table('m_client')->where('client_num', $num)->first()]);
    }

    /* ---------- business accounts ---------- */
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
        ];
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

    private function myAccount(Request $request, int $id): object
    {
        $a = DB::table('m_client_account')->where('id', $id)->where('client_num', $this->clientNum($request))->first();
        abort_if($a === null, 404, 'Business account not found.');
        return $a;
    }

    private function dupCode(string $num, string $code, ?int $ignore = null): bool
    {
        return DB::table('m_client_account')->where('client_num', $num)->where('branch_code', $code)
            ->when($ignore, fn ($q) => $q->where('id', '<>', $ignore))->exists();
    }

    /* POST /api/settings/accounts */
    public function storeAccount(Request $request)
    {
        $num = $this->clientNum($request);
        $d = $request->validate($this->accountRules());
        abort_if($this->dupCode($num, $d['branch_code']), 422, 'That branch code is already used by this client.');

        $row = $this->cleanAccount($d) + ['client_num' => $num, 'created_at' => now(), 'updated_at' => now()];
        DB::table('m_client_account')->insert($this->onlyColumns('m_client_account', $row));
        return response()->json(['accounts' => $this->accounts($num)], 201);
    }

    /* PUT /api/settings/accounts/{id} */
    public function updateAccount(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        $this->myAccount($request, $id);
        $d = $request->validate($this->accountRules());
        abort_if($this->dupCode($num, $d['branch_code'], $id), 422, 'That branch code is already used by this client.');

        DB::table('m_client_account')->where('id', $id)->update($this->cleanAccount($d) + ['updated_at' => now()]);
        return response()->json(['accounts' => $this->accounts($num)]);
    }

    /* DELETE /api/settings/accounts/{id}  { confirm: branch code } */
    public function destroyAccount(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        $a = $this->myAccount($request, $id);
        abort_if((int) $id === (int) $this->caidFor($request), 422, "You can't delete the branch you're signed in to.");
        abort_unless(trim((string) $request->input('confirm')) === trim((string) $a->branch_code), 422, 'Type the branch code exactly to confirm.');

        try {
            DB::table('m_client_account')->where('id', $id)->delete();
        } catch (QueryException $e) {
            abort(422, "This branch already has users or transactions, so it can't be deleted. Set its status to inactive instead.");
        }
        return response()->json(['accounts' => $this->accounts($num)]);
    }

    /* ---------- valid IDs ---------- */

    /* POST /api/settings/valid-ids  { institution, id_num } */
    public function storeValidId(Request $request)
    {
        $num = $this->clientNum($request);
        $d = $request->validate([
            'institution' => ['required', 'string', 'max:60'],
            'id_num'      => ['required', 'string', 'max:60'],
        ]);

        if (strtoupper(trim($d['institution'])) === 'BIR') {
            abort_unless(preg_match('/^\d{3}-\d{3}-\d{3}$/', trim($d['id_num'])), 422, 'Enter the TIN as 000-000-000 (9 digits).');
        }

        ClientValidId::create([
            'client_num'      => $num,
            'client_num_type' => 'CLIENT',
            'institution'     => strtoupper(trim($d['institution'])),
            'id_num'          => trim($d['id_num']),
        ]);
        return response()->json(['valid_ids' => $this->validIds($num)], 201);
    }

    /* DELETE /api/settings/valid-ids/{id} */
    public function destroyValidId(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        ClientValidId::where('id', $id)->where('client_num', $num)->delete();
        return response()->json(['valid_ids' => $this->validIds($num)]);
    }
        /* PUT /api/settings/valid-ids/{id}  { institution, id_num } */
    public function settingsUpdateValidId(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        $d = $request->validate([
            'institution' => ['required', 'string', 'max:60'],
            'id_num'      => ['required', 'string', 'max:60'],
        ]);
        if (strtoupper(trim($d['institution'])) === 'BIR') {
            abort_unless(preg_match('/^\d{3}-\d{3}-\d{3}$/', trim($d['id_num'])) === 1, 422, 'Enter the TIN as 000-000-000 (9 digits).');
        }
        $row = ClientValidId::where('id', $id)->where('client_num', $num)->first();
        abort_if($row === null, 404, 'ID not found.');

        $row->institution = strtoupper(trim($d['institution']));
        $row->id_num = trim($d['id_num']);
        $row->save();

        return response()->json(['valid_ids' => $this->validIds($num)]);
    }
}