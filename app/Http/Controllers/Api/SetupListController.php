<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Setup → Discount List   (m_discount_list)
 * Setup → Store/Warehouse (m_store_warehouse)
 * added_by = user client id (old AddedBy / UserID)
 */
class SetupListController extends SalesController
{
    /* =====================================================================
       DISCOUNT LIST
       ===================================================================== */
    const DL = 'm_discount_list';

    /** how many invoices use each discount (sales + purchases/expenses) */
    private function discountUsage(int $by): array
    {
        $n = [];
        foreach (['t_sales_invoice_master', 't_purchase_expense_master'] as $t) {
            if (!Schema::hasTable($t) || !Schema::hasColumn($t, 'discount_type_id')) continue;
            foreach (DB::table($t)->where('added_by', $by)->where('discount_type_id', '>', 0)
                ->groupBy('discount_type_id')->selectRaw('discount_type_id AS id, COUNT(*) AS c')->get() as $r) {
                $n[$r->id] = ($n[$r->id] ?? 0) + (int) $r->c;
            }
        }
        return $n;
    }

    /* GET /api/discount-list */
    public function dlIndex(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $used = $this->discountUsage($by);
        return response()->json(DB::table(self::DL)->where('added_by', $by)->orderBy('description')->get(['id', 'description', 'value'])
            ->map(fn ($r) => ['id' => $r->id, 'description' => $r->description, 'value' => (float) $r->value, 'used' => $used[$r->id] ?? 0]));
    }

    private function dlRules(int $by, ?int $id = null): array
    {
        return [
            'description' => ['required', 'string', 'max:150', Rule::unique(self::DL, 'description')->where(fn ($q) => $q->where('added_by', $by))->ignore($id)],
            'value' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    private const DL_MSG = ['description.required' => 'Description is required', 'description.unique' => 'This discount already exists.', 'value.max' => 'Value is a percent (0–100).'];

    /* POST /api/discount-list */
    public function dlStore(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $d = $request->validate($this->dlRules($by), self::DL_MSG);
        $id = DB::table(self::DL)->insertGetId(['description' => trim($d['description']), 'value' => $d['value'] ?? 0, 'added_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id, 'message' => 'Discount successfully saved']);
    }

    /* PUT /api/discount-list/{id} */
    public function dlUpdate(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        if (!DB::table(self::DL)->where('added_by', $by)->where('id', $id)->exists()) return response()->json(['message' => 'Discount not found.'], 404);
        $d = $request->validate($this->dlRules($by, $id), self::DL_MSG);
        DB::table(self::DL)->where('id', $id)->update(['description' => trim($d['description']), 'value' => $d['value'] ?? 0, 'updated_at' => now()]);
        return response()->json(['message' => 'Discount successfully saved']);
    }

    /* DELETE /api/discount-list/{id}   (blocked while invoices use it) */
    public function dlDestroy(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        if (!DB::table(self::DL)->where('added_by', $by)->where('id', $id)->exists()) return response()->json(['message' => 'Discount not found.'], 404);
        $n = $this->discountUsage($by)[$id] ?? 0;
        if ($n) return response()->json(['message' => "Can't delete: used in {$n} invoice" . ($n === 1 ? '' : 's') . '.'], 422);
        DB::table(self::DL)->where('id', $id)->delete();
        return response()->json(['message' => 'Discount successfully deleted']);
    }

    /* =====================================================================
       STORE / WAREHOUSE
       ===================================================================== */
    const SW = 'm_store_warehouse';

    /* GET /api/store-warehouse?inactive=1 */
    public function swIndex(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $rows = DB::table(self::SW)->where('added_by', $by)
            ->when(!$request->boolean('inactive'), fn ($q) => $q->where('is_active', 1))
            ->orderBy('code')->orderBy('description')
            ->get(['id', 'code', 'description', 'type', 'is_active']);
        return response()->json($rows->map(fn ($r) => (array) $r + ['is_active' => (bool) $r->is_active]));
    }

    private function swRules(int $by, ?int $id = null): array
    {
        return [
            'type' => ['required', 'in:Store,Warehouse'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique(self::SW, 'code')->where(fn ($q) => $q->where('added_by', $by)->where('is_active', 1))->ignore($id)],
            'description' => ['required', 'string', 'max:150'],
        ];
    }

    private const SW_MSG = ['type.required' => 'Type is required', 'type.in' => 'Type is required', 'description.required' => 'Description is required', 'code.unique' => 'This code is already used.'];

    /* POST /api/store-warehouse */
    public function swStore(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $d = $request->validate($this->swRules($by), self::SW_MSG);
        $id = DB::table(self::SW)->insertGetId([
            'code' => $d['code'] ?? null, 'description' => trim($d['description']), 'type' => $d['type'],
            'added_by' => $by, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Store / Warehouse successfully saved']);
    }

    /* PUT /api/store-warehouse/{id} */
    public function swUpdate(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        if (!DB::table(self::SW)->where('added_by', $by)->where('id', $id)->exists()) return response()->json(['message' => 'Store / Warehouse not found.'], 404);
        $d = $request->validate($this->swRules($by, $id), self::SW_MSG);
        DB::table(self::SW)->where('id', $id)->update(['code' => $d['code'] ?? null, 'description' => trim($d['description']), 'type' => $d['type'], 'updated_at' => now()]);
        return response()->json(['message' => 'Store / Warehouse successfully saved']);
    }

    /* DELETE /api/store-warehouse/{id}   old behaviour: Active = 0 (kept for old receipts / transfers) */
    public function swDestroy(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $n = DB::table(self::SW)->where('added_by', $by)->where('id', $id)->update(['is_active' => 0, 'updated_at' => now()]);
        return $n ? response()->json(['message' => 'Store / Warehouse successfully deleted']) : response()->json(['message' => 'Store / Warehouse not found.'], 404);
    }

    /* POST /api/store-warehouse/{id}/restore */
    public function swRestore(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $n = DB::table(self::SW)->where('added_by', $by)->where('id', $id)->update(['is_active' => 1, 'updated_at' => now()]);
        return $n ? response()->json(['message' => 'Store / Warehouse restored']) : response()->json(['message' => 'Store / Warehouse not found.'], 404);
    }
}
