<?php

namespace App\Http\Controllers\Api;

use App\Models\BranchType;
use App\Models\ClientAccount;
use App\Models\TaxType;
use App\Models\VendorCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VendorCustomerController extends SalesController
{
    /* {type} in the URL: customer | vendor  →  user_type */
    private function userType(string $type): string
    {
        $t = ucfirst(strtolower($type));
        abort_unless(in_array($t, ['Customer', 'Vendor'], true), 404);
        return $t;
    }

    /* main branch can sync (old: obj_AddedByCAID === obj_ComID) */
    private function canSync(Request $request): bool
    {
        $caid = $this->caidFor($request);
        $mainCaid = $request->user()->added_by_caid ?? null;
        return $caid && (int) $mainCaid === (int) $caid;
    }

    /* ---------- Email / Viber / Messenger / WeChat / WhatsApp / Telegram of a vendor or customer ----------
       m_social_list (the apps) · m_social_party (vendor_id = m_vendor_customer.id, social_list_id, value) */
    private function hasSocial(): bool
    {
        static $has = null;
        return $has ??= Schema::hasTable('m_social_list') && Schema::hasTable('m_social_party');
    }

    /** [vendor_id => [{social_list_id, name, value}, …]] */
    private function socialsOf(array $ids): array
    {
        if (!$ids || !$this->hasSocial()) return [];
        $out = [];
        foreach (DB::table('m_social_party as P')->join('m_social_list as L', 'L.id', '=', 'P.social_list_id')
            ->whereIn('P.vendor_id', $ids)->orderBy('L.id')->orderBy('P.id')
            ->get(['P.vendor_id', 'P.social_list_id', 'L.name', 'P.value']) as $r) {
            $out[$r->vendor_id][] = ['social_list_id' => (int) $r->social_list_id, 'name' => $r->name, 'value' => $r->value];
        }
        return $out;
    }

    /** replaces the contact's list with what was sent (empty values are skipped) */
    private function saveSocials(int $vendorId, ?array $socials): void
    {
        if ($socials === null || !$this->hasSocial()) return;      // not sent → keep as is
        $valid = DB::table('m_social_list')->pluck('id')->map(fn ($x) => (int) $x)->all();
        DB::transaction(function () use ($vendorId, $socials, $valid) {
            DB::table('m_social_party')->where('vendor_id', $vendorId)->delete();
            $now = now();
            $seen = [];
            foreach ($socials as $s) {
                $id = (int) ($s['social_list_id'] ?? 0);
                $v = trim((string) ($s['value'] ?? ''));
                if (!$id || $v === '' || !in_array($id, $valid, true) || isset($seen["{$id}|{$v}"])) continue;
                $seen["{$id}|{$v}"] = true;
                DB::table('m_social_party')->insert([
                    'vendor_id' => $vendorId, 'social_list_id' => $id, 'value' => mb_substr($v, 0, 255),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    /* GET /api/contacts-socials/{id}   one vendor / customer's Email, Viber … (Send via popup) */
    public function contactSocials(Request $request, int $id)
    {
        $ok = VendorCustomer::where('id', $id)->where('entry_by', $this->addedByFor($request))->exists();
        if (!$ok) return response()->json([]);
        return response()->json($this->socialsOf([$id])[$id] ?? []);
    }

    /** form fields → m_vendor_customer columns (the table has branch_type, tax_type, link_by_caid) */
    private function contactRow(array $d): array
    {
        return [
            'name'         => $d['name'],
            'tin'          => $d['tin'] ?? null,
            'branch_code'  => $d['branch_code'],
            'branch_type'  => (int) $d['branch_type_id'],
            'tax_type'     => !empty($d['tax_type_id']) ? (int) $d['tax_type_id'] : null,
            'address'      => $d['address'] ?? null,
            'city_country' => $d['city_country'] ?? null,
            'link_by_caid' => (int) ($d['link_caid'] ?? 0),
        ];
    }

    /* GET /api/contacts/{type} */
    public function listContacts(Request $request, string $type)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $branchTypes = BranchType::pluck('name', 'id');
        $taxTypes    = TaxType::pluck('name', 'id');

        $rows = VendorCustomer::where('user_type', $this->userType($type))
            ->where('entry_by', $addedBy)
            ->orderBy('name')
            ->get();
        $socials = $this->socialsOf($rows->pluck('id')->all());

        return response()->json($rows->map(fn ($r) => [
            'id'             => $r->id,
            'name'           => $r->name,
            'tin'            => $r->tin,
            'branch_code'    => $r->branch_code,
            'branch_type_id' => $r->branch_type,
            'branch_type'    => $branchTypes[$r->branch_type] ?? null,
            'tax_type_id'    => $r->tax_type,
            'tax_type'       => $taxTypes[$r->tax_type] ?? null,
            'address'        => $r->address,
            'city_country'   => $r->city_country,
            'link_caid'      => (int) ($r->link_by_caid ?? 0),
            'is_default'     => (bool) $r->is_default,
            'created_at'     => $r->created_at?->format('Y-m-d H:i:s'),
            'socials'        => $socials[$r->id] ?? [],
        ])->values());
    }

    /* GET /api/contacts-lookups  (dropdowns for the form) */
    public function contactLookups(Request $request)
    {
        $canSync = $this->canSync($request);

        return response()->json([
            'branch_types' => BranchType::orderBy('name')->get(['id', 'name']),
            'tax_types'    => TaxType::orderBy('name')->get(['id', 'name']),
            'can_sync'     => $canSync,
            'social_list'  => $this->hasSocial() ? DB::table('m_social_list')->orderBy('id')->get(['id', 'name']) : [],
            'clients'      => $canSync
                ? ClientAccount::where('id', '<>', $this->caidFor($request))
                    ->orderBy('trade_name')
                    ->get(['id', 'trade_name', 'client_name', 'branch_code'])
                : [],
        ]);
    }

    /* GET /api/contacts-sync/{caid}  (old doGetSyncClient) */
    public function syncClient(Request $request, int $caid)
    {
        abort_unless($this->canSync($request), 403);

        $c = ClientAccount::find($caid);
        if (!$c) return response()->json(['message' => 'Client not found.'], 404);

        return response()->json([
            'name'           => $c->trade_name,
            'tin'            => $c->tin,
            'branch_code'    => $c->branch_code,
            'branch_type_id' => strtoupper((string) $c->branch_type) === 'MAIN' ? 1 : 2,
            'tax_type_id'    => $c->tax_reg_id,
            'address'        => $c->street,
            'city_country'   => trim("{$c->barangay} {$c->city_mun} {$c->province}"),
        ]);
    }

    private function contactRules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:150', 'regex:/^[a-zA-Z0-9\s.,\-&\']+$/'],
            'tin'            => ['nullable', 'string', 'max:20'],
            'branch_code'    => ['required', 'string', 'max:10'],
            'branch_type_id' => ['required', 'integer', 'min:1'],
            'tax_type_id'    => ['nullable', 'integer'],
            'address'        => ['nullable', 'string', 'max:255'],
            'city_country'   => ['nullable', 'string', 'max:255'],
            'link_caid'      => ['nullable', 'integer'],
            'socials'                  => ['nullable', 'array', 'max:30'],
            'socials.*.social_list_id' => ['required', 'integer'],
            'socials.*.value'          => ['nullable', 'string', 'max:255'],
        ];
    }

    private function contactMessages(): array
    {
        return [
            'name.required'           => 'Name is required.',
            'name.regex'              => 'Name contains invalid special characters.',
            'branch_code.required'    => 'Branch Code is required.',
            'branch_type_id.required' => 'Branch Type is required.',
            'branch_type_id.min'      => 'Branch Type is required.',
        ];
    }

    /* POST /api/contacts/{type} */
    public function storeContact(Request $request, string $type)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        $d = $request->validate($this->contactRules(), $this->contactMessages());
        $socials = $d['socials'] ?? null;
        unset($d['socials']);

        // forceFill: saves every field even when the model's $fillable doesn't list it (branch_type_id, tax_type_id …)
        $c = new VendorCustomer();
        $c->forceFill($this->contactRow($d) + [
            'user_type'  => $this->userType($type),
            'entry_by'   => $addedBy,
            'is_default' => 0,
        ])->save();

        $this->saveSocials((int) $c->id, $socials);

        return response()->json(['message' => "{$c->name} added", 'id' => $c->id]);
    }

    /* PUT /api/contacts/{type}/{id} */
    public function updateContact(Request $request, string $type, int $id)
    {
        $c = VendorCustomer::where('id', $id)
            ->where('user_type', $this->userType($type))
            ->where('entry_by', $this->addedByFor($request))
            ->first();
        if (!$c) return response()->json(['message' => 'Record not found.'], 404);

        $d = $request->validate($this->contactRules(), $this->contactMessages());
        $socials = array_key_exists('socials', $d) ? ($d['socials'] ?? []) : null;
        unset($d['socials']);
        $c->forceFill($this->contactRow($d))->save();   // Branch Type → branch_type, Tax Registration → tax_type
        $this->saveSocials((int) $c->id, $socials);

        return response()->json(['message' => "{$c->name} updated"]);
    }

    /* POST /api/contacts/{type}/{id}/default  (old doUpdateIsDefault) */
    public function setDefaultContact(Request $request, string $type, int $id)
    {
        $addedBy  = $this->addedByFor($request);
        $userType = $this->userType($type);

        $c = VendorCustomer::where('id', $id)->where('user_type', $userType)->where('entry_by', $addedBy)->first();
        if (!$c) return response()->json(['message' => 'Record not found.'], 404);

        (new VendorCustomer)->getConnection()->transaction(function () use ($c, $userType, $addedBy) {
            VendorCustomer::where('user_type', $userType)->where('entry_by', $addedBy)->update(['is_default' => 0]);
            $c->update(['is_default' => 1]);
        });

        return response()->json(['message' => "{$c->name} is now the default"]);
    }
}