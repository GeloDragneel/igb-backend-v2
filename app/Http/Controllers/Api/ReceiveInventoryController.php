<?php

namespace App\Http\Controllers\Api;

use App\Models\ATC;
use App\Models\ChartsOfAccount;
use App\Models\DeclaredMonthSettings;
use App\Models\InvoiceStatus;
use App\Models\VendorCustomer;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
/**
 * Task → Inventory → Receive Inventory (old Inventory_And_Services/Receive_Inventory.php)
 * Tables: t_grn_master / t_grn_detail, m_store_warehouse (Location)
 * Also the Stocks tab (ledger + inventory adjustments: t_inventory_adjustment).
 *
 * Saving also does what the old set_grn_dtls_trigger did:
 *   - adds the received qty to t_porder_detail.receive_qty and updates the PO status (Open / Partial Received / Closed)
 *   - adds the qty to m_items.inventory_qty
 * VOID takes both back.
 */
class ReceiveInventoryController extends SalesController
{
    const M = 't_grn_master';
    const D = 't_grn_detail';
    const STATUS_NEW = 2;    // old ClearFields(): InvoiceStatus = 2
    const STATUS_VOID = 5;   // old doVoidMaster
    const AP_ACCOUNT = '21100';

    private static array $cols = [];

    private function only(string $table, array $row): array
    {
        $cols = self::$cols[$table] ??= Schema::getColumnListing($table);
        return array_intersect_key($row, array_flip($cols));
    }

    private function hasCol(string $table, string $col): bool
    {
        return in_array($col, self::$cols[$table] ??= Schema::getColumnListing($table), true);
    }

    private function base(int $addedBy)
    {
        return DB::table(self::M)->where('receive_by', $addedBy);
    }

    private function locked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    private function poStatusId(string $name, int $fallback): int
    {
        return (int) (DB::table('m_pos_status')->whereRaw('UPPER(name) LIKE ?', [strtoupper($name) . '%'])->value('id') ?: $fallback);
    }

    // old get_next_NO: GRN-yymmdd-001
    private function nextNo(): string
    {
        $prefix = 'GRN-' . date('ymd') . '-';
        $n = DB::table(self::M)->where('grn_no', 'like', $prefix . '%')->count() + 1;
        do {
            $no = $prefix . str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while (DB::table(self::M)->where('grn_no', $no)->exists());
        return $no;
    }

    private function lineRows(array $lines): array
    {
        $rows = [];
        foreach ($lines as $l) {
            $gross  = $this->r4($l['qty'] * $l['price']);
            $disc   = $this->r4($l['discount'] ?? 0);
            $amount = $this->r4($gross - $disc);
            $vat = $net = $exempt = $non = $zero = 0;
            switch ($l['vat_type']) {
                case 'exempt': $exempt = $amount; break;
                case 'zero':   $zero   = $amount; break;
                case 'nonvat': $non    = $amount; break;
                default:       $vat = $this->r4($amount * 12 / 112); $net = $this->r4($amount - $vat);
            }
            $rows[] = [
                'item_id' => (int) $l['item_id'], 'unit_type_id' => (int) ($l['unit_type_id'] ?? 0),
                'account_code' => $l['account_code'] ?? null,
                'qty' => $l['qty'], 'receive_qty' => $l['qty'], 'price' => $l['price'], 'total' => $gross,
                'discount_amount' => $disc, 'input_vat' => $vat, 'net_of_vat' => $net,
                'vat_exempt' => $exempt, 'non_vat' => $non, 'zero_rated' => $zero,
                'po_id' => (int) ($l['po_id'] ?? 0), 'po_number' => $l['po_number'] ?? null, 'ap_invoice_no' => $l['ap_invoice_no'] ?? null,
            ];
        }
        return $rows;
    }

    /* =====================================================================
       GET /api/inventory/receive?from=2026-01&to=2026-09
       ===================================================================== */
    public function riIndex(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $list = $this->base($addedBy)
            ->whereRaw("COALESCE(NULLIF(declared_month, ''), DATE_FORMAT(grn_date, '%Y-%m')) BETWEEN ? AND ?", [$d['from'], $d['to']])
            ->orderByDesc('id')->get();

        $vendors   = VendorCustomer::whereIn('id', $list->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');
        $locations = DB::table('m_store_warehouse')->whereIn('id', $list->pluck('location_id')->filter()->unique())->pluck('description', 'id');
        $statuses  = InvoiceStatus::pluck('name', 'id');

        return response()->json($list->map(fn ($m) => [
            'id'         => $m->id,
            'no'         => $m->grn_no,
            'vendor'     => $vendors[$m->vendor_id] ?? '—',
            'date'       => $m->grn_date ? substr((string) $m->grn_date, 0, 10) : null,
            'location'   => $locations[$m->location_id] ?? '—',
            'po'         => $m->ref_data,
            'total'      => (float) $m->total,
            'status'     => (int) $m->invoice_status_id === self::STATUS_VOID ? 'VOID' : ($statuses[$m->invoice_status_id] ?? 'RECEIVED'),
        ])->values());
    }

    /* GET /api/inventory/receive/lookups */
    public function riLookups(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        return response()->json([
            'vendors'   => VendorCustomer::where('user_type', 'Vendor')->where('entry_by', $addedBy)->orderBy('name')
                ->get(array_values(array_intersect(['id', 'name', 'tin', 'address', 'city_country'], Schema::getColumnListing('m_vendor_customer')))),
            'accounts'  => ChartsOfAccount::where('added_by', $addedBy)->where('root_name', '!=', '0')->orderBy('account_code')->get(['account_code', 'account_name']),
            'atc'       => ATC::orderBy('name')->get(['id', 'name', 'rate', 'category']),
            'locations' => DB::table('m_store_warehouse')->where('added_by', $addedBy)->where('is_active', 1)->orderByDesc('id')->get(['id', 'description']),
            'items'     => DB::table('m_items')->where('added_by', $addedBy)->orderBy('item_code')->get(['id', 'item_code', 'description']),
            'locked_months' => DeclaredMonthSettings::where('ca_id', $this->caidFor($request))->pluck('declared_month'),
        ]);
    }

    /* POST /api/inventory/receive/locations   quick add a store / warehouse */
    public function riStoreLocation(Request $request)
    {
        $d = $request->validate(['description' => ['required', 'string', 'max:150']]);
        $id = DB::table('m_store_warehouse')->insertGetId($d + [
            'added_by' => $this->addedByFor($request), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'description' => $d['description']]);
    }

    /* =====================================================================
       Apply PO#  (old doGetPOListByVendor / doGetPODetailByPONumber / doGetPurchaseMaster)
       Sources: open purchase orders + vendor invoices (purchases) of the vendor
       ===================================================================== */
    public function riSources(Request $request)
    {
        $d = $request->validate(['vendor_id' => ['required', 'integer']]);
        $addedBy = $this->addedByFor($request);
        $closed = $this->poStatusId('CLOSED', 3);
        $void   = $this->poStatusId('VOID', 5);

        $pos = DB::table('t_porder_master')->where('added_by', $addedBy)->where('vendor_id', $d['vendor_id'])
            ->whereNotIn('po_status_id', [$closed, $void])->orderByDesc('id')
            ->get(['po_number', 'document_no', 'po_date', 'grand_total'])
            ->map(fn ($p) => ['ref' => $p->po_number, 'type' => 'PO',
                'label' => 'PO ' . ($p->document_no ?: $p->po_number) . ' · ' . substr((string) $p->po_date, 0, 10)]);

        $aps = DB::table('t_purchase_expense_master')->where('added_by', $addedBy)->where('vendor_id', $d['vendor_id'])
            ->whereRaw("LOWER(trans_type) = 'purchases'")->where('invoice_status_id', '<>', 5)->orderByDesc('id')->limit(100)
            ->get(['invoice_no', 'document_no', 'transaction_date'])
            ->map(fn ($p) => ['ref' => $p->invoice_no, 'type' => 'AP',
                'label' => 'Invoice ' . ($p->document_no ?: $p->invoice_no) . ' · ' . substr((string) $p->transaction_date, 0, 10)]);

        return response()->json($pos->concat($aps)->values());
    }

    /* GET /api/inventory/receive/source/{ref}   header amounts + lines (PO lines show what is still to receive) */
    public function riSource(Request $request, string $ref)
    {
        $addedBy = $this->addedByFor($request);

        $po = DB::table('t_porder_master')->where('added_by', $addedBy)->where('po_number', $ref)->first();
        if ($po) {
            $lines = DB::table('t_porder_detail as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
                ->where('L.po_number', $ref)->orderBy('L.id')
                ->get(['L.*', 'I.item_code', 'I.description'])
                ->map(fn ($l) => [
                    'item_id' => $l->item_id, 'item_code' => $l->item_code, 'description' => $l->description,
                    'account_code' => $l->account_code, 'unit_type_id' => $l->unit_type_id,
                    'qty' => max(0, (float) $l->qty - (float) $l->receive_qty), 'ordered' => (float) $l->qty, 'received' => (float) $l->receive_qty,
                    'price' => (float) $l->price, 'discount' => (float) $l->discount_amount,
                    'vat_type' => $this->vatTypeOf($l), 'po_id' => $l->id, 'po_number' => $ref, 'ap_invoice_no' => null,
                ])->filter(fn ($l) => $l['qty'] > 0)->values();

            return response()->json(['header' => [
                'atc_vt_id' => $po->atc_vt_id ?: null, 'atc_it_id' => $po->atc_it_id ?: null,
                'vt_month' => $po->{'2307_vt_declared_month'}, 'it_month' => $po->{'2307_it_declared_month'},
            ], 'lines' => $lines]);
        }

        $ap = DB::table('t_purchase_expense_master')->where('added_by', $addedBy)->where('invoice_no', $ref)->first();
        if (!$ap) return response()->json(['message' => 'Reference not found.'], 404);

        $lines = DB::table('t_purchase_expense_detail as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->where('L.invoice_no', $ref)->orderBy('L.id')
            ->get(['L.*', 'I.item_code', 'I.description'])
            ->map(fn ($l) => [
                'item_id' => $l->item_id, 'item_code' => $l->item_code, 'description' => $l->description,
                'account_code' => $l->account_code, 'unit_type_id' => $l->unit_type_id,
                'qty' => (float) $l->quantity, 'ordered' => (float) $l->quantity, 'received' => 0,
                'price' => (float) $l->unit_price, 'discount' => (float) $l->discount_amount,
                'vat_type' => $this->vatTypeOf($l), 'po_id' => 0, 'po_number' => null, 'ap_invoice_no' => $ref,
            ]);

        return response()->json(['header' => [
            'atc_vt_id' => $ap->atc_vt_id ?: null, 'atc_it_id' => $ap->atc_it_id ?: null,
            'vt_month' => $ap->{'2307_vt_declared_month'} ?? null, 'it_month' => $ap->{'2307_it_declared_month'} ?? null,
        ], 'lines' => $lines]);
    }

    private function vatTypeOf(object $l): string
    {
        if ((float) ($l->vat_exempt ?? 0) > 0) return 'exempt';
        if ((float) ($l->zero_rated ?? 0) > 0) return 'zero';
        if ((float) ($l->non_vat ?? 0) > 0) return 'nonvat';
        return 'vatable';
    }

    /* =====================================================================
       GET /api/inventory/receive/record/{grnNo}
       ===================================================================== */
    public function riShow(Request $request, string $grnNo)
    {
        $m = $this->base($this->addedByFor($request))->where('grn_no', $grnNo)->first();
        if (!$m) return response()->json(['message' => 'Receive Inventory not found.'], 404);

        $lines = DB::table(self::D . ' as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->where('L.grn_no', $grnNo)->orderBy('L.id')->get(['L.*', 'I.item_code', 'I.description']);

        return response()->json([
            'record' => (array) $m + [
                'status_name' => (int) $m->invoice_status_id === self::STATUS_VOID ? 'VOID'
                    : (InvoiceStatus::where('id', $m->invoice_status_id)->value('name') ?? 'RECEIVED'),
            ],
            'lines' => $lines->map(fn ($l) => [
                'item_id' => $l->item_id, 'item_code' => $l->item_code, 'description' => $l->description,
                'account_code' => $l->account_code, 'unit_type_id' => $l->unit_type_id,
                'qty' => (float) $l->qty, 'price' => (float) $l->price, 'discount' => (float) $l->discount_amount,
                'vat_type' => $this->vatTypeOf($l), 'po_id' => $l->po_id, 'po_number' => $l->po_number, 'ap_invoice_no' => $l->ap_invoice_no,
            ]),
            'vendor' => VendorCustomer::find($m->vendor_id),
        ]);
    }

    /* GET /api/inventory/receive/record/{grnNo}/journal   (old doGetJournalList) */
    public function riJournal(Request $request, string $grnNo)
    {
        $addedBy = $this->addedByFor($request);
        $m = $this->base($addedBy)->where('grn_no', $grnNo)->first();
        if (!$m || (int) $m->invoice_status_id === self::STATUS_VOID) return response()->json(['journal' => [], 'collection' => []]);

        $vatCol = $this->hasCol('m_items', 'input_vat_account') ? 'input_vat_account' : null;
        $lines = DB::table(self::D . ' as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')->where('L.grn_no', $grnNo)
            ->get(array_filter(['L.account_code', 'L.input_vat', 'L.net_of_vat', 'L.vat_exempt', 'L.non_vat', 'L.zero_rated', $vatCol ? "I.$vatCol as vat_account" : null]));
        $names = ChartsOfAccount::where('added_by', $addedBy)->pluck('account_name', 'account_code');

        $rows = [['account_code' => self::AP_ACCOUNT, 'account_name' => $names[self::AP_ACCOUNT] ?? 'Accounts Payable', 'debit' => 0, 'credit' => round((float) $m->grand_total, 2)]];
        if ((float) $m->{'2307_it'} > 0) $rows[] = ['account_code' => '21910', 'account_name' => 'Withholding Tax', 'debit' => 0, 'credit' => round((float) $m->{'2307_it'}, 2)];
        if ((float) $m->{'2307_vt'} > 0) $rows[] = ['account_code' => '21910', 'account_name' => 'Withholding Tax', 'debit' => 0, 'credit' => round((float) $m->{'2307_vt'}, 2)];
        foreach ($lines as $l) {
            if ((float) $l->input_vat > 0) {
                $code = ($l->vat_account ?? null) ?: '0';
                $rows[] = ['account_code' => (string) $code, 'account_name' => $names[$code] ?? 'Tax', 'debit' => round((float) $l->input_vat, 2), 'credit' => 0];
            }
            $rows[] = ['account_code' => (string) ($l->account_code ?: '0'), 'account_name' => $names[$l->account_code] ?? '',
                'debit' => round((float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated, 2), 'credit' => 0];
        }
        return response()->json(['journal' => $rows, 'collection' => []]);
    }

    /* =====================================================================
       POST /api/inventory/receive        new
       PUT  /api/inventory/receive/{id}   edit (old page saved changes directly, no ticket)
       ===================================================================== */
    public function riStore(Request $request)
    {
        return $this->save($request, null);
    }

    public function riUpdate(Request $request, int $id)
    {
        return $this->save($request, $id);
    }

    private function save(Request $request, ?int $id)
    {
        $d = $request->validate([
            'vendor_id'        => ['required', 'integer', 'min:1'],
            'grn_date'         => ['required', 'date'],
            'location_id'      => ['required', 'integer', 'min:1'],
            'ref_data'         => ['nullable', 'string', 'max:40'],
            'declared_month'   => ['nullable', 'date_format:Y-m'],
            'atc_vt_id'        => ['nullable', 'integer'],
            'atc_it_id'        => ['nullable', 'integer'],
            'vt_month'         => ['nullable', 'date_format:Y-m'],
            'it_month'         => ['nullable', 'date_format:Y-m'],
            'sub_total'        => ['required', 'numeric'],
            '2307_vt'          => ['nullable', 'numeric', 'min:0'],
            '2307_it'          => ['nullable', 'numeric', 'min:0'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'total'            => ['required', 'numeric'],
            'grand_total'      => ['required', 'numeric'],
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.item_id'       => ['required', 'integer', 'min:1'],
            'lines.*.account_code'  => ['nullable', 'string', 'max:20'],
            'lines.*.unit_type_id'  => ['nullable', 'integer'],
            'lines.*.qty'           => ['required', 'numeric', 'gt:0'],
            'lines.*.price'         => ['required', 'numeric', 'min:0'],
            'lines.*.discount'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_type'      => ['required', 'in:vatable,exempt,zero,nonvat'],
            'lines.*.po_id'         => ['nullable', 'integer'],
            'lines.*.po_number'     => ['nullable', 'string', 'max:40'],
            'lines.*.ap_invoice_no' => ['nullable', 'string', 'max:40'],
        ], [
            'vendor_id.min' => 'Vendor is required', 'vendor_id.required' => 'Vendor is required',
            'location_id.min' => 'Location is required', 'location_id.required' => 'Location is required',
            'lines.required' => 'Please add item', 'lines.*.item_id.min' => 'Each line needs an item.',
        ]);

        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);
        if ($this->locked($this->caidFor($request), [$d['declared_month'] ?? null])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        $existing = null;
        if ($id) {
            $existing = $this->base($addedBy)->where('id', $id)->first();
            if (!$existing) return response()->json(['message' => 'Receive Inventory not found.'], 404);
            if ((int) $existing->invoice_status_id === self::STATUS_VOID) return response()->json(['message' => 'This record is void.'], 422);
        }

        $rows = $this->lineRows($d['lines']);

        // over-receiving a PO line is not allowed
        foreach ($rows as $r) {
            if (!$r['po_id']) continue;
            $po = DB::table('t_porder_detail')->where('id', $r['po_id'])->first(['qty', 'receive_qty']);
            $already = (float) ($po->receive_qty ?? 0);
            if ($existing) {
                $already -= (float) DB::table(self::D)->where('grn_no', $existing->grn_no)->where('po_id', $r['po_id'])->sum('qty');
            }
            if ($po && $r['qty'] > (float) $po->qty - $already + 0.0001) {
                return response()->json(['message' => 'Qty is more than what is left to receive on the PO (' . max(0, (float) $po->qty - $already) . ').'], 422);
            }
        }

        return DB::transaction(function () use ($d, $addedBy, $rows, $existing) {
            $grnNo = $existing->grn_no ?? $this->nextNo();
            $vt = ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null;
            $it = ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null;

            $master = [
                'grn_no' => $grnNo, 'vendor_id' => $d['vendor_id'], 'grn_date' => $d['grn_date'],
                'location_id' => $d['location_id'], 'ref_data' => $d['ref_data'] ?? null,
                'total' => $this->r4($d['total']), 'sub_total' => $this->r4($d['sub_total']), 'grand_total' => $this->r4($d['grand_total']),
                'atc_vt_id' => (int) ($d['atc_vt_id'] ?? 0), 'atc_it_id' => (int) ($d['atc_it_id'] ?? 0),
                '2307_vt' => $this->r4($d['2307_vt'] ?? 0), '2307_it' => $this->r4($d['2307_it'] ?? 0),
                'discount_amount' => $this->r4($d['discount_amount'] ?? 0),
                'declared_month' => $d['declared_month'] ?? null,
                '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it,
                'updated_at' => now(),
            ];

            if ($existing) {
                $this->applyStock($existing->grn_no, -1);         // take back the old lines first
                DB::table(self::D)->where('grn_no', $existing->grn_no)->delete();
                DB::table(self::M)->where('id', $existing->id)->update($this->only(self::M, $master));
                $id = $existing->id;
            } else {
                $id = DB::table(self::M)->insertGetId($this->only(self::M, $master + [
                    'receive_by' => $addedBy, 'invoice_status_id' => self::STATUS_NEW, 'created_at' => now(),
                ]));
            }

            foreach ($rows as $r) {
                DB::table(self::D)->insert($this->only(self::D, $r + [
                    'grn_no' => $grnNo, 'grn_date' => $d['grn_date'], 'vendor_id' => $d['vendor_id'],
                    'warehouse_id' => $d['location_id'], 'receive_by' => $addedBy,
                    'declared_month' => $d['declared_month'] ?? null,
                    '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            $this->applyStock($grnNo, +1);

            return response()->json(['id' => $id, 'no' => $grnNo]);
        });
    }

    /* POST /api/inventory/receive/{id}/void */
    public function riVoid(Request $request, int $id)
    {
        $m = $this->base($this->addedByFor($request))->where('id', $id)->first();
        if (!$m) return response()->json(['message' => 'Receive Inventory not found.'], 404);
        if ((int) $m->invoice_status_id === self::STATUS_VOID) return response()->json(['message' => 'Already void.'], 422);
        if ($this->locked($this->caidFor($request), [$m->declared_month])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        DB::transaction(function () use ($m) {
            $this->applyStock($m->grn_no, -1);
            DB::table(self::M)->where('id', $m->id)->update(['invoice_status_id' => self::STATUS_VOID, 'updated_at' => now()]);
        });
        return response()->json(['message' => "{$m->grn_no} voided"]);
    }

    /** +1 adds the GRN quantities to the PO lines and item stock, −1 takes them back */
    private function applyStock(string $grnNo, int $sign): void
    {
        $lines = DB::table(self::D)->where('grn_no', $grnNo)->get(['item_id', 'qty', 'po_id', 'po_number']);
        $hasStock = $this->hasCol('m_items', 'inventory_qty');

        foreach ($lines as $l) {
            if ($l->po_id) {
                DB::table('t_porder_detail')->where('id', $l->po_id)
                    ->update(['receive_qty' => DB::raw('GREATEST(0, receive_qty + ' . ($sign * (float) $l->qty) . ')')]);
            }
            if ($hasStock && $l->item_id) {
                DB::table('m_items')->where('id', $l->item_id)
                    ->update(['inventory_qty' => DB::raw('COALESCE(inventory_qty, 0) + ' . ($sign * (float) $l->qty))]);
            }
        }

        // PO status: Closed when everything is received, Partial Received when some, Open when none
        foreach ($lines->pluck('po_number')->filter()->unique() as $poNo) {
            $agg = DB::table('t_porder_detail')->where('po_number', $poNo)
                ->selectRaw('SUM(qty) AS ordered, SUM(LEAST(receive_qty, qty)) AS received')->first();
            $status = (float) $agg->received <= 0 ? $this->poStatusId('OPEN', 1)
                : ((float) $agg->received + 0.0001 >= (float) $agg->ordered ? $this->poStatusId('CLOSED', 3) : $this->poStatusId('PARTIAL', 2));
            DB::table('t_porder_master')->where('po_number', $poNo)->update(['po_status_id' => $status, 'updated_at' => now()]);
            DB::table('t_porder_detail')->where('po_number', $poNo)->update(['po_status_id' => $status]);
        }
    }

    /* attachment (S3 folder Receive_Inventory) */
    public function riUploadAttachment(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']]);
        $m = $this->base($this->addedByFor($request))->where('id', $id)->first();
        if (!$m) return response()->json(['message' => 'Not found.'], 404);
        $file  = $request->file('file');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
        Storage::disk('s3')->putFileAs('Receive_Inventory', $file, $m->id . '-' . $clean);
        DB::table(self::M)->where('id', $m->id)->update(['attachment' => $clean, 'updated_at' => now()]);
        return response()->json(['attachment' => $clean]);
    }

    public function riAttachment(Request $request, int $id)
    {
        $m = $this->base($this->addedByFor($request))->where('id', $id)->first();
        if (!$m) return response()->json(['message' => 'No attachment.'], 404);

        $file = trim((string) ($m->attachment ?? ''));
        if ($file === '') return response()->json(['message' => 'No attachment.'], 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        foreach (["Receive_Inventory/{$m->id}-{$file}", "Receive_Inventory/{$file}"] as $key) {
            if ($disk->exists($key)) return $disk->response($key, $file);
        }
        return response()->json(['message' => 'File not found on storage.'], 404);
    }

    /* =====================================================================
       STOCKS TAB (old Inventory_And_Services/Inventory.php)
       Beg. Inventory + Delivery − Sold + Adjustment = End. Inventory
         Delivery   = Receive Inventory lines (t_grn_detail, receipt not void)
         Sold       = Sales invoice lines (t_sales_invoice_detail, not void / invalid)
         Adjustment = t_inventory_adjustment
         Opening    = t_beg_balance_product + everything before "Date From"
       ===================================================================== */

    private function colsOf(string $t): array
    {
        return self::$cols[$t] ??= Schema::getColumnListing($t);
    }

    private function firstCol(string $t, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, $this->colsOf($t), true)) return $n;
        return null;
    }

    /* ---------- the three movement sources (each returns a query with: d = date, qty) ---------- */
    private function deliveries(int $by, int $item)
    {
        return DB::table('t_grn_detail as L')
            ->join('t_grn_master as M', 'M.grn_no', '=', 'L.grn_no')
            ->where('L.receive_by', $by)->where('L.item_id', $item)
            ->where('M.invoice_status_id', '<>', self::STATUS_VOID);
    }

    private function sales(int $by, int $item)
    {
        $q = DB::table('t_sales_invoice_detail as L')->where('L.added_by', $by)->where('L.item_id', $item)
            ->where(fn ($w) => $w->whereNull('L.invoice_status_id')->orWhere('L.invoice_status_id', '<>', self::STATUS_VOID));
        if (in_array('trans_type', $this->colsOf('t_sales_invoice_detail'), true)) {
            $q->where(fn ($w) => $w->whereNull('L.trans_type')->orWhereNotIn('L.trans_type', ['invalid', 'adjustment']));
        }
        return $q;
    }

    private function adjustments(int $by, int $item)
    {
        return DB::table('t_inventory_adjustment as L')->where('L.added_by', $by)->where('L.item_id', $item);
    }

    /* =====================================================================
       GET /api/inventory/stocks/lookups   items (inventory class) + adjustment reasons
       ===================================================================== */
    public function stLookups(Request $request)
    {
        $by = $this->addedByFor($request);
        $inv = $this->firstCol('m_items', ['gl_inventory_account', 'gl_inventory_acount']);

        $items = DB::table('m_items')->where('added_by', $by)
            ->when(in_array('item_class_id', $this->colsOf('m_items'), true), fn ($q) => $q->where('item_class_id', 1))
            ->orderBy('description')
            ->get(array_filter(['id', 'item_code', 'description', $inv ? "$inv as inventory_account" : null]));

        return response()->json([
            'items'   => $items,
            'reasons' => DB::table('m_inventory_adjustment_reasons')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /* =====================================================================
       GET /api/inventory/stocks/ledger?item_ids[]=1&item_ids[]=2&from=2026-10-01&to=2026-10-31&mode=DAY|MONTH
       (item_id=1 still works for one item)
       ===================================================================== */
    public function stLedger(Request $request)
    {
        $d = $request->validate([
            'item_ids'   => ['nullable', 'array', 'max:100'],
            'item_ids.*' => ['integer'],
            'item_id'    => ['nullable', 'integer'],
            'from'       => ['required', 'date'],
            'to'         => ['required', 'date', 'after_or_equal:from'],
            'mode'       => ['nullable', 'in:DAY,MONTH'],
        ]);
        $by   = $this->addedByFor($request);
        $ids  = array_values(array_unique(array_map('intval', $d['item_ids'] ?? (isset($d['item_id']) ? [$d['item_id']] : []))));
        if (!$ids) return response()->json(['message' => 'Please select at least one item.'], 422);

        $mode = $d['mode'] ?? 'DAY';
        $from = Carbon::parse($d['from'])->startOfDay();
        $to   = Carbon::parse($d['to'])->endOfDay();
        if ($mode === 'MONTH') { $from->startOfMonth(); $to->endOfMonth(); }
        if ($from->diffInDays($to) > 1100) return response()->json(['message' => 'Please pick a range of 3 years or less.'], 422);

        $names = DB::table('m_items')->where('added_by', $by)->whereIn('id', $ids)->get(['id', 'item_code', 'description'])->keyBy('id');

        $items = [];
        foreach ($ids as $item) {
            if (!isset($names[$item])) continue;
            $items[] = [
                'item_id'     => $item,
                'item_code'   => $names[$item]->item_code,
                'description' => $names[$item]->description,
            ] + $this->ledgerFor($by, $item, $from->copy(), $to->copy(), $mode);
        }

        return response()->json(['items' => $items]);
    }

    /** one item: opening balance + one row per day / month */
    private function ledgerFor(int $by, int $item, Carbon $from, Carbon $to, string $mode): array
    {
        $opening = (float) DB::table('t_beg_balance_product')->where('added_by', $by)->where('item_id', $item)->sum('quantity')
            + (float) $this->deliveries($by, $item)->where('L.grn_date', '<', $from->toDateString())->sum('L.qty')
            - (float) $this->sales($by, $item)->where('L.transaction_date', '<', $from->toDateString())->sum('L.quantity')
            + (float) $this->adjustments($by, $item)->where('L.transaction_date', '<', $from->toDateString())->sum('L.qty');

        $fmt = $mode === 'MONTH' ? '%Y-%m' : '%Y-%m-%d';
        $range = [$from->toDateString(), $to->toDateString()];

        $del = $this->deliveries($by, $item)->whereBetween('L.grn_date', $range)
            ->selectRaw("DATE_FORMAT(L.grn_date, '$fmt') AS k, SUM(L.qty) AS q")->groupBy('k')->pluck('q', 'k');
        $sold = $this->sales($by, $item)->whereBetween('L.transaction_date', $range)
            ->selectRaw("DATE_FORMAT(L.transaction_date, '$fmt') AS k, SUM(L.quantity) AS q")->groupBy('k')->pluck('q', 'k');
        $adj = $this->adjustments($by, $item)->whereBetween('L.transaction_date', $range)
            ->selectRaw("DATE_FORMAT(L.transaction_date, '$fmt') AS k, SUM(L.qty) AS q")->groupBy('k')->pluck('q', 'k');

        $rows = [];
        $bal  = $opening;
        $period = $mode === 'MONTH' ? CarbonPeriod::create($from, '1 month', $to) : CarbonPeriod::create($from, '1 day', $to);
        foreach ($period as $p) {
            $k = $mode === 'MONTH' ? $p->format('Y-m') : $p->format('Y-m-d');
            $in = (float) ($del[$k] ?? 0);
            $out = (float) ($sold[$k] ?? 0);
            $a = (float) ($adj[$k] ?? 0);
            $end = $bal + $in - $out + $a;
            $rows[] = [
                'key' => $k, 'label' => $mode === 'MONTH' ? $p->format('M Y') : $p->format('M d Y'),
                'beginning' => round($bal, 4), 'delivery' => round($in, 4), 'sold' => round($out, 4),
                'adjustment' => round($a, 4), 'ending' => round($end, 4),
            ];
            $bal = $end;
        }

        return ['rows' => $rows, 'opening' => round($opening, 4), 'closing' => round($bal, 4)];
    }

    /* =====================================================================
       GET /api/inventory/stocks/details?item_id=&key=2026-10-03|2026-10&mode=&type=delivery|sold|adjustment
       (old doGetPopupDetails)
       ===================================================================== */
    public function stDetails(Request $request)
    {
        $d = $request->validate([
            'item_id' => ['required', 'integer'], 'key' => ['required', 'string', 'max:10'],
            'mode' => ['nullable', 'in:DAY,MONTH'], 'type' => ['required', 'in:delivery,sold,adjustment'],
        ]);
        $by = $this->addedByFor($request);
        $item = (int) $d['item_id'];
        if (($d['mode'] ?? 'DAY') === 'MONTH') {
            $start = Carbon::parse($d['key'] . '-01')->toDateString();
            $end = Carbon::parse($d['key'] . '-01')->endOfMonth()->toDateString();
        } else {
            $start = $end = Carbon::parse($d['key'])->toDateString();
        }

        if ($d['type'] === 'delivery') {
            $rows = $this->deliveries($by, $item)->whereBetween('L.grn_date', [$start, $end])->orderBy('L.grn_date')
                ->get(['L.id', 'L.grn_date as date', 'L.qty', 'L.price', 'L.total', DB::raw("COALESCE(NULLIF(L.po_number, ''), L.grn_no) AS reference")]);
        } elseif ($d['type'] === 'sold') {
            $rows = $this->sales($by, $item)->whereBetween('L.transaction_date', [$start, $end])->orderBy('L.transaction_date')
                ->get(['L.id', 'L.transaction_date as date', 'L.quantity as qty', 'L.unit_price as price', 'L.amount as total', 'L.invoice_no as reference']);
        } else {
            $rows = $this->adjustments($by, $item)->leftJoin('m_inventory_adjustment_reasons as R', 'R.id', '=', 'L.reason_id')
                ->whereBetween('L.transaction_date', [$start, $end])->orderBy('L.transaction_date')
                ->get(['L.id', 'L.transaction_date as date', 'L.qty', DB::raw('0 AS price'), DB::raw('0 AS total'),
                    DB::raw("COALESCE(R.name, '') AS reference"), 'L.declared_month', 'L.reason_id', 'L.item_id']);
        }

        return response()->json($rows->map(fn ($r) => (array) $r + ['date' => substr((string) $r->date, 0, 10)]));
    }

    /* =====================================================================
       GET /api/inventory/stocks/adjustments   Variance tab list (old qry2_inventory_adjustment.php)
       ===================================================================== */
    public function stAdjustmentList(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);

        $rows = DB::table('t_inventory_adjustment as A')
            ->leftJoin('m_items as I', 'I.id', '=', 'A.item_id')
            ->leftJoin('m_inventory_adjustment_reasons as R', 'R.id', '=', 'A.reason_id')
            ->leftJoin('m_charts_of_account as C', function ($j) use ($by) {
                $j->on('C.account_code', '=', 'A.account_code')->where('C.added_by', '=', $by);
            })
            ->where('A.added_by', $by)
            ->orderByDesc('A.transaction_date')->orderByDesc('A.id')
            ->get([
                'A.id', 'A.transaction_date', 'A.declared_month', 'A.item_id', 'A.qty', 'A.reason_id', 'A.account_code',
                DB::raw("COALESCE(C.account_name, '') AS account_name"),
                DB::raw("COALESCE(I.description, '') AS item"),
                DB::raw("COALESCE(I.item_code, '') AS item_code"),
                DB::raw("COALESCE(R.name, '') AS reason"),
            ]);

        return response()->json($rows->map(fn ($r) => (array) $r + ['transaction_date' => substr((string) $r->transaction_date, 0, 10), 'qty' => (float) $r->qty]));
    }

    /* =====================================================================
       POST /api/inventory/stocks/adjustments        add
       PUT  /api/inventory/stocks/adjustments/{id}   edit
       DELETE /api/inventory/stocks/adjustments/{id}
       ===================================================================== */
    public function stSaveAdjustment(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'transaction_date' => ['required', 'date'],
            'declared_month'   => ['required', 'date_format:Y-m'],
            'item_id'          => ['required', 'integer', 'min:1'],
            'qty'              => ['required', 'numeric', 'not_in:0'],
            'reason_id'        => ['required', 'integer', 'min:1'],
        ], [
            'declared_month.required' => 'Declared Month is required',
            'item_id.min' => 'Item is required', 'reason_id.min' => 'Reason is required', 'qty.not_in' => 'Qty is required',
        ]);
        $by = $this->addedByFor($request);

        $inv = $this->firstCol('m_items', ['gl_inventory_account', 'gl_inventory_acount']);
        $account = $inv ? DB::table('m_items')->where('id', $d['item_id'])->where('added_by', $by)->value($inv) : null;
        if (!$account || $account === '0') {
            return response()->json(['message' => 'The selected item has no inventory account (GL Inventory Account).'], 422);
        }

        $row = $d + ['account_code' => $account, 'added_by' => $by, 'emp_id' => (int) ($request->user()->employee_id ?? 0), 'updated_at' => now()];

        return DB::transaction(function () use ($id, $by, $row, $d) {
            if ($id) {
                $old = DB::table('t_inventory_adjustment')->where('id', $id)->where('added_by', $by)->first();
                if (!$old) return response()->json(['message' => 'Adjustment not found.'], 404);
                $this->bumpStock($old->item_id, -(float) $old->qty);
                DB::table('t_inventory_adjustment')->where('id', $id)->update($row);
            } else {
                $id = DB::table('t_inventory_adjustment')->insertGetId($row + ['created_at' => now()]);
            }
            $this->bumpStock($d['item_id'], (float) $d['qty']);
            return response()->json(['id' => $id, 'message' => 'Record successfully saved']);
        });
    }

    public function stUpdateAdjustment(Request $request, int $id)
    {
        return $this->stSaveAdjustment($request, $id);
    }

    public function stDeleteAdjustment(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $old = DB::table('t_inventory_adjustment')->where('id', $id)->where('added_by', $by)->first();
        if (!$old) return response()->json(['message' => 'Adjustment not found.'], 404);
        DB::transaction(function () use ($old) {
            $this->bumpStock($old->item_id, -(float) $old->qty);
            DB::table('t_inventory_adjustment')->where('id', $old->id)->delete();
        });
        return response()->json(['message' => 'Adjustment deleted']);
    }

    // keep m_items.inventory_qty in step with adjustments
    private function bumpStock(int $itemId, float $qty): void
    {
        if ($qty && in_array('inventory_qty', $this->colsOf('m_items'), true)) {
            DB::table('m_items')->where('id', $itemId)->update(['inventory_qty' => DB::raw('COALESCE(inventory_qty, 0) + ' . $qty)]);
        }
    }


    /* =====================================================================
       ADJUSTMENT TAB (old Inventory_And_Services/Adjustment.php)
       A sales-type invoice saved to t_sales_invoice_master / _detail with trans_type = 'adjustment'.
       Same field names as the Sales API, so the page is the Sales invoice page.
       Lists (customers, items, accounts, ATC …) come from GET /sales/lookups.
       ===================================================================== */
    const AM = 't_sales_invoice_master';
    const AD = 't_sales_invoice_detail';
    const ADJ = 'adjustment';

    /** adjustment rows of this account only */
    private function adjBase(int $by)
    {
        return DB::table(self::AM)->where('added_by', $by)->where('trans_type', self::ADJ);
    }

    private function adjFind(int $by, string $col, $val): ?object
    {
        return $this->adjBase($by)->where($col, $val)->first();
    }

    /* GET /api/inventory/adjustment?from=2026-01&to=2026-09 */
    public function adjIndex(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);

        $list = $this->adjBase($by)
            ->whereRaw("LEFT(COALESCE(NULLIF(declared_month, ''), transaction_date), 7) BETWEEN ? AND ?", [$d['from'], $d['to']])
            ->orderByDesc('id')->get();

        $customers = VendorCustomer::whereIn('id', $list->pluck('customer_id')->filter()->unique())->pluck('name', 'id');
        $statuses  = InvoiceStatus::pluck('name', 'id');
        $audits    = DB::table('m_audit_status')->pluck('name', 'id');

        return response()->json($list->map(fn ($r) => [
            'id'               => $r->id,
            'customer_name'    => trim((string) ($customers[$r->customer_id] ?? '')) ?: 'WALK IN',
            'invoice_no'       => $r->invoice_no,
            'document_no'      => $r->document_no,
            'transaction_date' => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : null,
            'declared_month'   => $r->declared_month && preg_match('/^\d{4}-\d{2}/', $r->declared_month)
                ? Carbon::createFromFormat('Y-m-d', substr($r->declared_month, 0, 7) . '-01')->format('F Y') : $r->declared_month,
            'total'            => (float) $r->grand_total,
            'status'           => $statuses[$r->invoice_status_id] ?? '',
            'audit_status'     => $audits[$r->audit_status_id ?? 0] ?? '',
            'ticket_no'        => null,
        ])->values());
    }

    /* GET /api/inventory/adjustment/invoice/{invoiceNo} */
    public function adjShow(Request $request, string $invoiceNo)
    {
        $inv = $this->adjFind($this->addedByFor($request), 'invoice_no', $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Adjustment not found.'], 404);

        $lines = DB::table(self::AD . ' as L')
            ->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->leftJoin('m_unit_type as U', 'U.id', '=', 'L.unit_type_id')
            ->where('L.invoice_no', $inv->invoice_no)->orderBy('L.id')
            ->get(['L.*', DB::raw("COALESCE(I.item_code, '') as item_code"), DB::raw("COALESCE(I.description, '') as item_description"), DB::raw("COALESCE(U.name, '') as unit")]);

        return response()->json([
            'invoice' => (array) $inv + [
                'trans_type'        => 'posted',   // the page treats it like a normal invoice
                'declared_month_ym' => $inv->declared_month ? substr($inv->declared_month, 0, 7) : null,
                'vt_month_ym'       => $inv->{'2307_vt_declared_month'} ?? null,
                'it_month_ym'       => $inv->{'2307_it_declared_month'} ?? null,
                'status_name'       => InvoiceStatus::where('id', $inv->invoice_status_id)->value('name') ?? '',
                'audit_status_name' => DB::table('m_audit_status')->where('id', $inv->audit_status_id ?? 0)->value('name') ?? '',
            ],
            'lines'    => $lines,
            'customer' => $inv->customer_id ? VendorCustomer::find($inv->customer_id) : null,
            'ticket'   => null,
            'scan'     => null,
        ]);
    }

    /* GET /api/inventory/adjustment/invoice/{invoiceNo}/journal   (old doGetJournalList: Dr Accounts, Dr 21910, Cr Output VAT, Cr sales) */
    public function adjJournal(Request $request, string $invoiceNo)
    {
        $by = $this->addedByFor($request);
        $inv = $this->adjFind($by, 'invoice_no', $invoiceNo);
        if (!$inv || (int) $inv->invoice_status_id === self::STATUS_VOID) return response()->json(['journal' => [], 'collection' => []]);

        $vatCol = $this->firstCol('m_items', ['output_vat_account']);
        $lines = DB::table(self::AD . ' as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')->where('L.invoice_no', $invoiceNo)
            ->get(array_filter(['L.account_code', 'L.input_vat', 'L.net_of_vat', 'L.vat_exempt', 'L.non_vat', 'L.zero_rated', 'L.ex_rate', $vatCol ? "I.$vatCol as vat_account" : null]));
        $names = ChartsOfAccount::where('added_by', $by)->pluck('account_name', 'account_code');
        $ex = (float) ($inv->ex_rate ?: 1);

        $rows = [['account_code' => (string) $inv->account_code, 'account_name' => $names[$inv->account_code] ?? '', 'debit' => round((float) $inv->grand_total, 2), 'credit' => 0]];
        if ((float) $inv->{'2307_it'} != 0) $rows[] = ['account_code' => '21910', 'account_name' => 'Withholding Tax - Income Tax', 'debit' => round((float) $inv->{'2307_it'}, 2), 'credit' => 0];
        if ((float) $inv->{'2307_vt'} != 0) $rows[] = ['account_code' => '21910', 'account_name' => 'Withholding Tax - VAT', 'debit' => round((float) $inv->{'2307_vt'}, 2), 'credit' => 0];
        foreach ($lines as $l) {
            if ((float) $l->input_vat != 0) {
                $code = ($l->vat_account ?? null) ?: '0';
                $rows[] = ['account_code' => (string) $code, 'account_name' => $names[$code] ?? 'No Account Settings', 'debit' => 0, 'credit' => round((float) $l->input_vat * $ex, 2)];
            }
            $rows[] = ['account_code' => (string) ($l->account_code ?: '0'), 'account_name' => $names[$l->account_code] ?? '', 'debit' => 0,
                'credit' => round(((float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated) * (float) ($l->ex_rate ?: 1), 2)];
        }
        return response()->json(['journal' => $rows, 'collection' => []]);
    }

    /* POST /api/inventory/adjustment   new
       PUT  /api/inventory/adjustment/{id}   edit (old page saved changes directly) */
    public function adjStore(Request $request)
    {
        return $this->adjSave($request, null);
    }

    public function adjUpdate(Request $request, int $id)
    {
        return $this->adjSave($request, $id);
    }

    private function adjSave(Request $request, ?int $id)
    {
        $d = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'], 'transaction_date' => ['required', 'date'],
            'declared_month' => ['required', 'date_format:Y-m'], 'document_no' => ['required', 'string', 'max:100'],
            'document_type_id' => ['nullable', 'integer'], 'account_code' => ['required', 'string', 'max:15'],
            'currency' => ['nullable', 'string', 'max:10'], 'ex_rate' => ['nullable', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'atc_vt_id' => ['nullable', 'integer'], 'atc_it_id' => ['nullable', 'integer'],
            'vt_month' => ['nullable', 'date_format:Y-m'], 'it_month' => ['nullable', 'date_format:Y-m'],
            'discount_type_id' => ['nullable', 'integer'],
            'sub_total' => ['required', 'numeric'], '2307_vt' => ['nullable', 'numeric'], '2307_it' => ['nullable', 'numeric'],
            'discount_amount' => ['nullable', 'numeric'], 'total' => ['required', 'numeric'], 'grand_total' => ['required', 'numeric'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'integer'],
            'lines.*.unit_type_id' => ['nullable', 'integer'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['required', 'numeric', 'min:0'], 'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_type' => ['required', 'in:vatable,exempt,zero,nonvat'], 'lines.*.account_code' => ['nullable', 'string', 'max:10'],
        ], [
            'customer_id.min' => 'Please select customer', 'customer_id.required' => 'Please select customer',
            'account_code.required' => 'Please select account', 'document_no.required' => 'Please input document #',
            'declared_month.required' => 'Please input declared month', 'lines.required' => 'Please add item',
        ]);

        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $vt = ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null;
        $it = ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null;
        if ((float) ($d['2307_vt'] ?? 0) > 0 && !$vt) return response()->json(['message' => '2307 (VT/PT) Declared Month is required'], 422);
        if ((float) ($d['2307_it'] ?? 0) > 0 && !$it) return response()->json(['message' => '2307 (IT) Declared Month is required'], 422);
        if ($this->locked($this->caidFor($request), [$d['declared_month'], $vt, $it])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        $existing = $id ? $this->adjFind($by, 'id', $id) : null;
        if ($id && !$existing) return response()->json(['message' => 'Adjustment not found.'], 404);
        if (!$existing && $this->adjBase($by)->where('document_no', $d['document_no'])->where('invoice_status_id', '<>', self::STATUS_VOID)->exists()) {
            return response()->json(['message' => 'Document number already in use'], 422);
        }

        $rows = [];
        foreach ($d['lines'] as $l) {
            $gross = $this->r4($l['qty'] * $l['price']);
            $disc = $this->r4($l['discount'] ?? 0);
            $amount = $this->r4($gross - $disc);
            $vat = $net = $ex = $non = $zero = 0;
            switch ($l['vat_type']) {
                case 'exempt': $ex = $amount; break;
                case 'zero': $zero = $amount; break;
                case 'nonvat': $non = $amount; break;
                default: $vat = $this->r4($amount * 12 / 112); $net = $this->r4($amount - $vat);
            }
            $rows[] = ['item_id' => $l['item_id'], 'unit_type_id' => $l['unit_type_id'] ?? null, 'quantity' => $l['qty'], 'unit_price' => $l['price'],
                'amount' => $gross, 'discount_amount' => $disc, 'input_vat' => $vat, 'net_of_vat' => $net, 'vat_exempt' => $ex,
                'non_vat' => $non, 'zero_rated' => $zero, 'account_code' => $l['account_code'] ?? null];
        }

        return DB::transaction(function () use ($d, $by, $existing, $rows, $vt, $it) {
            $no = $existing->invoice_no ?? null;
            if (!$no) {
                do { $no = 'ADJ-' . date('ymd-his') . random_int(10000, 99999); } while (DB::table(self::AM)->where('invoice_no', $no)->exists());
            }
            $status = $existing->invoice_status_id ?? 2;   // old ClearFields(): InvoiceStatus = 2
            $master = [
                'invoice_no' => $no, 'customer_id' => $d['customer_id'], 'added_by' => $by, 'transaction_date' => $d['transaction_date'],
                'declared_month' => $d['declared_month'], 'account_code' => $d['account_code'], 'document_no' => $d['document_no'],
                'document_type_id' => $d['document_type_id'] ?? null, 'currency' => $d['currency'] ?? 'PHP', 'ex_rate' => $d['ex_rate'] ?? 1,
                'sub_total' => $this->r4($d['sub_total']), 'total' => $this->r4($d['total']), 'grand_total' => $this->r4($d['grand_total']),
                'discount_amount' => $this->r4($d['discount_amount'] ?? 0), 'discount_type_id' => $d['discount_type_id'] ?? null,
                'atc_vt_id' => $d['atc_vt_id'] ?: null, 'atc_it_id' => $d['atc_it_id'] ?: null,
                '2307_vt' => $this->r4($d['2307_vt'] ?? 0), '2307_it' => $this->r4($d['2307_it'] ?? 0),
                '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it, 'remarks' => $d['remarks'] ?? null,
                'invoice_status_id' => $status, 'trans_type' => self::ADJ, 'updated_at' => now(),
            ];
            if ($existing) {
                DB::table(self::AM)->where('id', $existing->id)->update($this->only(self::AM, $master));
                DB::table(self::AD)->where('invoice_no', $no)->delete();
                $id = $existing->id;
            } else {
                $seq = (int) DB::table(self::AM)->where('added_by', $by)->lockForUpdate()->max('seq_no') + 1;
                $id = DB::table(self::AM)->insertGetId($this->only(self::AM, $master + ['seq_no' => $seq, 'created_at' => now()]));
            }
            foreach ($rows as $r) {
                DB::table(self::AD)->insert($this->only(self::AD, $r + [
                    'invoice_no' => $no, 'currency' => $master['currency'], 'ex_rate' => $master['ex_rate'], 'added_by' => $by,
                    'declared_month' => $master['declared_month'], 'invoice_status_id' => $status, 'transaction_date' => $master['transaction_date'],
                    'document_no' => $master['document_no'], 'customer_id' => $master['customer_id'],
                    '2307_vt_declared_month' => $vt, '2307_it_declared_month' => $it, 'trans_type' => self::ADJ,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            return response()->json(['id' => $id, 'invoice_no' => $no, 'message' => 'Record successfully saved']);
        });
    }

    /* POST /api/inventory/adjustment/{id}/audit  (AUDITOR button) */
    public function adjAudit(Request $request, int $id)
    {
        $d = $request->validate(['audit_status_id' => ['required', 'integer'], 'audit_remarks' => ['nullable', 'string', 'max:255']]);
        $inv = $this->adjFind($this->addedByFor($request), 'id', $id);
        if (!$inv) return response()->json(['message' => 'Adjustment not found.'], 404);
        DB::table(self::AM)->where('id', $inv->id)->update($this->only(self::AM, ['audit_status_id' => $d['audit_status_id'], 'audit_remarks' => $d['audit_remarks'] ?? null, 'updated_at' => now()]));
        DB::table(self::AD)->where('invoice_no', $inv->invoice_no)->update($this->only(self::AD, ['audit_status_id' => $d['audit_status_id'], 'updated_at' => now()]));
        return response()->json(['ok' => true]);
    }

    /* GET /api/inventory/adjustment/invoice/{invoiceNo}/neighbors  (auditor previous / next) */
    public function adjNeighbors(Request $request, string $invoiceNo)
    {
        $by = $this->addedByFor($request);
        $cur = $this->adjFind($by, 'invoice_no', $invoiceNo);
        if (!$cur) return response()->json(['prev' => null, 'next' => null]);
        $prev = $this->adjBase($by)->where('id', '>', $cur->id)->orderBy('id')->value('invoice_no');
        $next = $this->adjBase($by)->where('id', '<', $cur->id)->orderByDesc('id')->value('invoice_no');
        return response()->json(['prev' => $prev, 'next' => $next]);
    }

    /* attachment (S3 folder Sales_Adjustment) */
    public function adjUploadAttachment(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']]);
        $inv = $this->adjFind($this->addedByFor($request), 'id', $id);
        if (!$inv) return response()->json(['message' => 'Not found.'], 404);
        $file = $request->file('file');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
        Storage::disk('s3')->putFileAs('Sales_Adjustment', $file, $inv->id . '-' . $clean);
        DB::table(self::AM)->where('id', $id)->update(['attachment' => $clean, 'updated_at' => now()]);
        return response()->json(['attachment' => $clean]);
    }
    public function adjAttachment(Request $request, int $id)
    {
        $inv = $this->adjFind($this->addedByFor($request), 'id', $id);
        if (!$inv) return response()->json(['message' => 'No attachment.'], 404);

        $file = trim((string) ($inv->attachment ?? ''));
        if ($file === '') return response()->json(['message' => 'No attachment.'], 404);
        if (preg_match('/^https?:\/\//i', $file)) return response()->json(['url' => $file]);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        foreach (["Sales_Adjustment/{$inv->id}-{$file}", "Sales_Invoices/{$inv->id}-{$file}"] as $key) {
            if ($disk->exists($key)) return $disk->response($key, $file);
        }
        return response()->json(['message' => 'File not found on storage.'], 404);
    }

    /* =====================================================================
       INTERNAL TRANSFER TAB (old Inventory_And_Services/Internal_Transfer.php)
       ===================================================================== */

    /* GET /api/inventory/transfers */
    public function itIndex(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $rows = DB::table('t_internal_transfer_master as M')
            ->leftJoin(DB::raw('(SELECT transfer_no, COUNT(*) AS lines_count, SUM(qty) AS total_qty FROM t_internal_transfer_detail GROUP BY transfer_no) D'), 'D.transfer_no', '=', 'M.transfer_no')
            ->where('M.added_by', $by)->orderByDesc('M.id')
            ->get(['M.id', 'M.transfer_no', 'M.document_no', 'M.remarks', 'M.created_at', DB::raw('COALESCE(D.lines_count, 0) AS lines_count'), DB::raw('COALESCE(D.total_qty, 0) AS total_qty')]);
        return response()->json($rows->map(fn ($r) => (array) $r + ['date' => substr((string) $r->created_at, 0, 10), 'total_qty' => (float) $r->total_qty]));
    }

    /* GET /api/inventory/transfers/{transferNo}   (old doGetTransferDetails) */
    public function itDetails(Request $request, string $transferNo)
    {
        $by = $this->addedByFor($request);
        if (!DB::table('t_internal_transfer_master')->where('added_by', $by)->where('transfer_no', $transferNo)->exists()) {
            return response()->json(['message' => 'Transfer not found.'], 404);
        }
        $rows = DB::table('t_internal_transfer_detail as A')
            ->leftJoin('m_items as B', 'B.id', '=', 'A.item_id')
            ->leftJoin('m_store_warehouse as C', 'C.id', '=', 'A.from_location_id')
            ->leftJoin('m_store_warehouse as D', 'D.id', '=', 'A.to_location_id')
            ->where('A.transfer_no', $transferNo)->orderBy('A.id')
            ->get(['A.id', 'A.item_id', DB::raw("COALESCE(B.item_code, '') AS item_code"), DB::raw("COALESCE(B.description, '') AS description"),
                DB::raw("COALESCE(C.description, '—') AS from_name"), DB::raw("COALESCE(D.description, '—') AS to_name"), 'A.qty']);
        return response()->json($rows->map(fn ($r) => (array) $r + ['qty' => (float) $r->qty]));
    }
}