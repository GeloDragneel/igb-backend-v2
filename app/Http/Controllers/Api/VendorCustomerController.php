<?php

namespace App\Http\Controllers\Api;

use App\Models\BranchType;
use App\Models\ClientAccount;
use App\Models\TaxType;
use App\Models\VendorCustomer;
use Illuminate\Http\Request;

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

        return response()->json($rows->map(fn ($r) => [
            'id'             => $r->id,
            'name'           => $r->name,
            'tin'            => $r->tin,
            'branch_code'    => $r->branch_code,
            'branch_type_id' => $r->branch_type_id,
            'branch_type'    => $branchTypes[$r->branch_type_id] ?? null,
            'tax_type_id'    => $r->tax_type_id,
            'tax_type'       => $taxTypes[$r->tax_type_id] ?? null,
            'address'        => $r->address,
            'city_country'   => $r->city_country,
            'link_caid'      => (int) ($r->link_caid ?? 0),
            'is_default'     => (bool) $r->is_default,
            'created_at'     => $r->created_at?->format('Y-m-d H:i:s'),
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

        $c = VendorCustomer::create($d + [
            'user_type'     => $this->userType($type),
            'entry_by'      => $addedBy,
            'entry_by_caid' => $this->caidFor($request),
            'link_caid'     => $d['link_caid'] ?? 0,
            'is_default'    => 0,
        ]);

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
        $c->update($d + ['link_caid' => $d['link_caid'] ?? 0]);

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