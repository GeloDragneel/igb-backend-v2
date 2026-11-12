<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientAccount;
use App\Models\DeclaredMonthSettings;
use App\Models\SalesInvoiceMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task → POS (full screen, own browser tab)
 *
 * A sale is saved ONLY in t_pos_sale (post_status = PENDING) inside the cashier's shift (t_pos_shift).
 *   Nothing goes to Sales (t_sales_invoice_*) or Collection (t_rvoucher_*) yet; the lines keep the
 *   accounting figures (VAT / net / account) so they can be posted later.
 * Stock = beginning + Receive Inventory + adjustments − sales lines − PENDING POS sales.
 * Only items whose Item Class is "Stock Item".
 * Void = invoice VOID (the DB trigger removes its collections; stock comes back).
 *
 * routes/api.php (auth:sanctum group):
 *   use App\Http\Controllers\Api\PosController;
 *   Route::prefix('pos')->group(function () {
 *       Route::get('bootstrap', [PosController::class, 'bootstrap']);
 *       Route::get('items', [PosController::class, 'items']);
 *       Route::post('settings', [PosController::class, 'saveSettings']);
 *       Route::post('shift/open', [PosController::class, 'openShift']);
 *       Route::post('shift/close', [PosController::class, 'closeShift']);
 *       Route::get('shift/{id}/summary', [PosController::class, 'shiftSummary'])->whereNumber('id');
 *       Route::post('checkout', [PosController::class, 'checkout']);
 *       Route::get('sales', [PosController::class, 'sales']);
 *       Route::get('sales/{id}', [PosController::class, 'sale'])->whereNumber('id');
 *       Route::post('sales/{id}/void', [PosController::class, 'voidSale'])->whereNumber('id');
 *   });
 */
class PosController extends SalesController
{
    const VOID = 5;
    const METHODS = ['cash' => 'Cash', 'gcash' => 'GCash', 'card' => 'Card'];
    const EMP_TYPES = ['charge' => 'Employee Charge', 'allowance' => 'Employee Allowance'];   // employee as customer
    const ALL_METHODS = self::METHODS + self::EMP_TYPES;

    private static array $cols = [];

    private function colsOf(string $t): array
    {
        return self::$cols[$t] ??= (Schema::hasTable($t) ? Schema::getColumnListing($t) : []);
    }

    private function firstCol(string $t, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, $this->colsOf($t), true)) return $n;
        return null;
    }

    /** only the columns the table really has */
    private function onlyCols(string $t, array $row): array
    {
        return array_intersect_key($row, array_flip($this->colsOf($t)));
    }

    private function by(Request $request): int
    {
        $by = $this->addedByFor($request);
        abort_if(!$by, 422, 'No client account selected.');
        return (int) $by;
    }

    private function cashier(Request $request): array
    {
        $u = $request->user();
        $name = trim((string) ($u->name ?? $u->full_name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? '')));
        return [(int) ($u->id ?? 0), $name ?: 'Cashier'];
    }

    private function statusIdOf(string $name): ?int
    {
        $id = DB::table('m_invoice_status')->whereRaw('UPPER(name) LIKE ?', [strtoupper($name) . '%'])->value('id');
        return $id ? (int) $id : null;
    }

    private function nextNo(string $table, string $col, string $prefix): string
    {
        $p = $prefix . '-' . date('ymd') . '-';
        $n = DB::table($table)->where($col, 'like', $p . '%')->count() + 1;
        do { $no = $p . str_pad((string) $n++, 4, '0', STR_PAD_LEFT); } while (DB::table($table)->where($col, $no)->exists());
        return $no;
    }

    /* =====================================================================
       Settings (+ defaults guessed from the chart of accounts)
       ===================================================================== */
    private function settings(int $by, ?int $caid): array
    {
        $s = Schema::hasTable('t_pos_settings') ? DB::table('t_pos_settings')->where('added_by', $by)->first() : null;
        $acc = $caid ? DB::table('m_client_account')->where('id', $caid)->first() : null;
        $coa = $this->chart($by);
        $find = function (array $patterns) use ($coa) {
            foreach ($patterns as $re) foreach ($coa as $a) if (preg_match($re, $a['name'])) return $a['code'];
            return null;
        };
        return [
            'store_name' => $s->store_name ?? ($acc->trade_name ?? ''),
            'address' => $s->address ?? trim((string) ($acc->street ?? '')),
            'tin' => $s->tin ?? trim(implode('-', array_filter([$acc->tin ?? null, $acc->branch_code ?? null]))),
            'footer' => $s->footer ?? 'Thank you, come again!',
            'paper' => (int) ($s->paper ?? 80),
            'ar_account' => $s->ar_account ?? $find(['/accounts?\s+receivable(?!.*(other|employee|officer))/i', '/receivable/i']),
            'cash_account' => $s->cash_account ?? $find(['/cash\s+on\s+hand/i', '/^cash$/i', '/cash(?!.*bank)/i']),
            'gcash_account' => $s->gcash_account ?? $find(['/gcash/i', '/e-?wallet/i', '/cash\s+in\s+bank/i']),
            'card_account' => $s->card_account ?? $find(['/card/i', '/cash\s+in\s+bank/i', '/bank/i']),
            'allow_negative' => (bool) ($s->allow_negative ?? false),
            'saved' => (bool) $s,
        ];
    }

    private function chart(int $by): array
    {
        return \App\Models\ChartsOfAccount::where('added_by', $by)->orderBy('account_code')
            ->get(['account_code', 'account_name'])
            ->map(fn ($a) => ['code' => (string) $a->account_code, 'name' => (string) $a->account_name])->all();
    }

    /* POST /api/pos/settings */
    public function saveSettings(Request $request)
    {
        $by = $this->by($request);
        $d = $request->validate([
            'store_name' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'], 'footer' => ['nullable', 'string', 'max:500'],
            'paper' => ['required', 'in:58,80'],
            'ar_account' => ['required', 'string', 'max:15'], 'cash_account' => ['required', 'string', 'max:15'],
            'gcash_account' => ['nullable', 'string', 'max:15'], 'card_account' => ['nullable', 'string', 'max:15'],
            'allow_negative' => ['boolean'],
        ], ['ar_account.required' => 'Choose the Accounts Receivable account.', 'cash_account.required' => 'Choose the Cash account.']);

        DB::table('t_pos_settings')->updateOrInsert(['added_by' => $by], $d + ['updated_at' => now(), 'created_at' => now()]);
        return response()->json(['settings' => $this->settings($by, $this->caidFor($request)), 'message' => 'POS settings saved.']);
    }

    /* =====================================================================
       GET /api/pos/bootstrap   settings · accounts · customers · open shift
       ===================================================================== */
    public function bootstrap(Request $request)
    {
        $by = $this->by($request);
        [$uid] = $this->cashier($request);
        $shift = DB::table('t_pos_shift')->where('added_by', $by)->where('status', 'OPEN')
            ->orderByRaw('cashier_id = ? DESC', [$uid])->orderByDesc('id')->first();

        $customers = DB::table('m_vendor_customer')->where('user_type', 'Customer')->where('entry_by', $by)
            ->orderBy('name')->get(['id', 'name', 'tin'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'tin' => $c->tin])->values();

        return response()->json([
            'settings' => $this->settings($by, $this->caidFor($request)),
            'accounts' => $this->chart($by),
            'customers' => $customers,
            'employees' => $this->employees($this->caidFor($request)),
            'shift' => $shift ? $this->shiftOut($shift) : null,
            'cashier' => $this->cashier($request)[1],
        ]);
    }

    /** active Regular employees of every branch of this client (HR: m_employee.company_caid ∈ the client's business accounts, same client_num) */
    private function employees(?int $caid, ?int $id = null)
    {
        if (!$caid || !Schema::hasTable('m_employee') || !$this->firstCol('m_employee', ['company_caid'])) return collect();
        $num = DB::table('m_client_account')->where('id', $caid)->value('client_num');
        $branches = $num
            ? DB::table('m_client_account')->where('client_num', $num)->pluck('trade_name', 'id')
            : collect([$caid => DB::table('m_client_account')->where('id', $caid)->value('trade_name')]);
        // only active + Regular (m_employment_status.name = "Regular")
        $regular = Schema::hasTable('m_employment_status')
            ? DB::table('m_employment_status')->whereRaw("UPPER(TRIM(name)) = 'REGULAR'")->pluck('id')->map(fn ($x) => (int) $x)->all()
            : [];

        return DB::table('m_employee')->whereIn('company_caid', $branches->keys()->all())
            ->when($id, fn ($q) => $q->where('id', $id))
            ->where('is_active', 1)
            ->when($this->firstCol('m_employee', ['employment_stat_id']), fn ($q) => $q->whereIn('employment_stat_id', $regular ?: [0]))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'last_name', 'first_name', 'middle_name', 'company_caid'])
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'name' => trim(preg_replace('/\s+/', ' ', trim((string) $e->last_name) . ', ' . trim((string) $e->first_name)
                    . (trim((string) $e->middle_name) !== '' ? ' ' . mb_substr(trim((string) $e->middle_name), 0, 1) . '.' : '')), ', '),
                'branch' => (string) ($branches[$e->company_caid] ?? ''),
                'same_branch' => (int) $e->company_caid === (int) $caid,
            ])
            ->sortBy(fn ($e) => ($e['same_branch'] ? '0' : '1') . $e['name'])->values();
    }

    /* =====================================================================
       GET /api/pos/items   Stock Items + price + stock on hand
       ===================================================================== */
    public function items(Request $request)
    {
        $by = $this->by($request);
        $classIds = $this->stockClassIds();
        $sales = $this->firstCol('m_items', ['gl_sales_account', 'gl_sales_acount']);
        $unit = $this->firstCol('m_items', ['unit_type_id', 'default_unit_type_id']);
        $tax = $this->firstCol('m_items', ['tax_type_id']);
        $upc = $this->firstCol('m_items', ['upc_sku', 'upc']);
        $img = $this->firstCol('m_items', ['image', 'image_name', 'filename', 'photo']);

        $items = DB::table('m_items')->where('added_by', $by)
            ->when($classIds, fn ($q) => $q->whereIn('item_class_id', $classIds))
            ->orderBy('description')
            ->get(array_values(array_filter(['id', 'item_code', 'description', 'price',
                $sales ? "{$sales} as sales_acct" : null, $unit ? "{$unit} as unit_type_id" : null,
                $tax ? "{$tax} as tax_type_id" : null, $upc ? "{$upc} as upc" : null, $img ? "{$img} as image" : null])));

        $stock = $this->stock($by, $items->pluck('id')->map(fn ($x) => (int) $x)->all());
        $taxNames = $this->taxNames();

        return response()->json($items->map(fn ($i) => [
            'id' => (int) $i->id,
            'code' => (string) $i->item_code,
            'name' => (string) $i->description,
            'upc' => (string) ($i->upc ?? ''),
            'price' => round((float) $i->price, 2),
            'stock' => round((float) ($stock[$i->id] ?? 0), 4),
            'vat_type' => $this->vatType($taxNames[$i->tax_type_id ?? 0] ?? ''),
            'tax_type' => $taxNames[$i->tax_type_id ?? 0] ?? '',
            'has_image' => $img ? !empty($i->image) : null,     // null = unknown → the app tries
        ])->values());
    }

    /** m_item_class ids named "Stock Item" (not Master / Serialized / Non-stock) */
    private function stockClassIds(): array
    {
        if (!Schema::hasTable('m_item_class')) return [1];
        $ids = DB::table('m_item_class')->whereRaw("UPPER(TRIM(name)) = 'STOCK ITEM'")->pluck('id')->map(fn ($x) => (int) $x)->all();
        return $ids ?: [1];
    }

    private function taxNames()
    {
        foreach (['m_tax_type', 'lib_tax_type', 'm_tax_types', 'm_item_tax_type'] as $t) {
            if (Schema::hasTable($t)) {
                $n = $this->firstCol($t, ['name', 'tax_type', 'description']);
                if ($n) return DB::table($t)->pluck($n, 'id');
            }
        }
        return collect();
    }

    private function vatType(string $tax): string
    {
        if (preg_match('/exempt/i', $tax)) return 'exempt';
        if (preg_match('/zero/i', $tax)) return 'zero';
        if (preg_match('/non[\s-]*vat/i', $tax)) return 'nonvat';
        return 'vatable';
    }

    /** on hand per item: beginning + received + adjustments − sold (not void / invalid) */
    private function stock(int $by, array $ids): array
    {
        if (!$ids) return [];
        $q = array_fill_keys($ids, 0.0);
        $add = function ($rows, $sign = 1) use (&$q) { foreach ($rows as $id => $n) if (isset($q[$id])) $q[$id] += $sign * (float) $n; };

        if (Schema::hasTable('t_beg_balance_product')) {
            $add(DB::table('t_beg_balance_product')->where('added_by', $by)->whereIn('item_id', $ids)
                ->groupBy('item_id')->selectRaw('item_id, SUM(quantity) AS n')->pluck('n', 'item_id'));
        }
        $add(DB::table('t_grn_detail as L')->join('t_grn_master as M', 'M.grn_no', '=', 'L.grn_no')
            ->where('L.receive_by', $by)->whereIn('L.item_id', $ids)->where('M.invoice_status_id', '<>', self::VOID)
            ->groupBy('L.item_id')->selectRaw('L.item_id, SUM(L.qty) AS n')->pluck('n', 'item_id'));
        if (Schema::hasTable('t_inventory_adjustment')) {
            $add(DB::table('t_inventory_adjustment')->where('added_by', $by)->whereIn('item_id', $ids)
                ->groupBy('item_id')->selectRaw('item_id, SUM(qty) AS n')->pluck('n', 'item_id'));
        }
        $sq = DB::table('t_sales_invoice_detail as L')->where('L.added_by', $by)->whereIn('L.item_id', $ids)
            ->where(fn ($w) => $w->whereNull('L.invoice_status_id')->orWhere('L.invoice_status_id', '<>', self::VOID));
        if (in_array('trans_type', $this->colsOf('t_sales_invoice_detail'), true)) {
            $sq->where(fn ($w) => $w->whereNull('L.trans_type')->orWhereNotIn('L.trans_type', ['invalid', 'adjustment']));
        }
        $add($sq->groupBy('L.item_id')->selectRaw('L.item_id, SUM(L.quantity) AS n')->pluck('n', 'item_id'), -1);

        // POS sales not posted to Sales yet
        if (Schema::hasTable('t_pos_sale')) {
            $pending = [];
            DB::table('t_pos_sale')->where('added_by', $by)->where('status', 'PAID')
                ->where(fn ($w) => $w->where('post_status', 'PENDING')->orWhereNull('post_status'))->whereNull('invoice_no')
                ->orderBy('id')->chunk(500, function ($rows) use (&$pending) {
                    foreach ($rows as $r) foreach (json_decode($r->lines, true) ?: [] as $l) {
                        $pending[(int) $l['item_id']] = ($pending[(int) $l['item_id']] ?? 0) + (float) $l['qty'];
                    }
                });
            $add($pending, -1);
        }
        return $q;
    }

    /* =====================================================================
       Shift
       ===================================================================== */
    private function shiftOut(object $s): array
    {
        return [
            'id' => (int) $s->id, 'shift_no' => $s->shift_no, 'cashier' => $s->cashier_name,
            'opening_cash' => round((float) $s->opening_cash, 2), 'opened_at' => (string) $s->opened_at,
            'closed_at' => $s->closed_at ? (string) $s->closed_at : null, 'status' => $s->status,
            'expected_cash' => $s->expected_cash !== null ? round((float) $s->expected_cash, 2) : null,
            'counted_cash' => $s->counted_cash !== null ? round((float) $s->counted_cash, 2) : null,
            'over_short' => $s->over_short !== null ? round((float) $s->over_short, 2) : null,
            'notes' => $s->notes,
        ];
    }

    /* POST /api/pos/shift/open  { opening_cash } */
    public function openShift(Request $request)
    {
        $by = $this->by($request);
        $d = $request->validate(['opening_cash' => ['required', 'numeric', 'min:0', 'max:99999999']]);
        [$uid, $name] = $this->cashier($request);
        $open = DB::table('t_pos_shift')->where('added_by', $by)->where('cashier_id', $uid)->where('status', 'OPEN')->first();
        if ($open) return response()->json(['shift' => $this->shiftOut($open), 'message' => 'Your shift is already open.']);

        $id = DB::table('t_pos_shift')->insertGetId([
            'added_by' => $by, 'shift_no' => $this->nextNo('t_pos_shift', 'shift_no', 'SH'),
            'cashier_id' => $uid, 'cashier_name' => $name, 'opening_cash' => round((float) $d['opening_cash'], 2),
            'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['shift' => $this->shiftOut(DB::table('t_pos_shift')->find($id)), 'message' => 'Shift opened.']);
    }

    /* GET /api/pos/shift/{id}/summary */
    public function shiftSummary(Request $request, int $id)
    {
        $by = $this->by($request);
        $s = DB::table('t_pos_shift')->where('added_by', $by)->where('id', $id)->first();
        abort_if(!$s, 404, 'Shift not found.');
        return response()->json($this->summary($s));
    }

    private function summary(object $s): array
    {
        $sales = DB::table('t_pos_sale')->where('shift_id', $s->id)->get();
        $paid = $sales->where('status', 'PAID');
        $byMethod = array_fill_keys(array_keys(self::ALL_METHODS), 0.0);
        foreach ($paid as $x) {
            foreach (json_decode($x->payments, true) ?: [] as $p) {
                $byMethod[$p['method']] = ($byMethod[$p['method']] ?? 0) + (float) $p['amount'];
            }
        }
        $expected = round((float) $s->opening_cash + $byMethod['cash'], 2);
        return [
            'shift' => $this->shiftOut($s),
            'sales_count' => $paid->count(),
            'void_count' => $sales->where('status', 'VOID')->count(),
            'void_total' => round((float) $sales->where('status', 'VOID')->sum('total'), 2),
            'gross' => round((float) $paid->sum('sub_total'), 2),
            'discount' => round((float) $paid->sum('discount'), 2),
            'net' => round((float) $paid->sum('total'), 2),
            'vat' => round((float) $paid->sum('vat'), 2),
            'items' => (int) $paid->sum('items'),
            'by_method' => array_map(fn ($v) => round($v, 2), $byMethod),
            'expected_cash' => $expected,
        ];
    }

    /* POST /api/pos/shift/close  { counted_cash, notes } */
    public function closeShift(Request $request)
    {
        $by = $this->by($request);
        $d = $request->validate(['shift_id' => ['required', 'integer'], 'counted_cash' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $s = DB::table('t_pos_shift')->where('added_by', $by)->where('id', $d['shift_id'])->where('status', 'OPEN')->first();
        abort_if(!$s, 422, 'This shift is already closed.');
        $sum = $this->summary($s);
        DB::table('t_pos_shift')->where('id', $s->id)->update([
            'status' => 'CLOSED', 'closed_at' => now(), 'expected_cash' => $sum['expected_cash'],
            'counted_cash' => round((float) $d['counted_cash'], 2),
            'over_short' => round((float) $d['counted_cash'] - $sum['expected_cash'], 2),
            'notes' => $d['notes'] ?? null, 'updated_at' => now(),
        ]);
        return response()->json($this->summary(DB::table('t_pos_shift')->find($s->id)) + ['message' => 'Shift closed.']);
    }

    /* =====================================================================
       POST /api/pos/checkout
       { shift_id, customer_id?, lines:[{item_id, qty, price, discount}], payments:[{method, amount, reference}] }
       payments.amount = what the customer handed over (cash may be more → change)
       ===================================================================== */
    public function checkout(Request $request)
    {
        $by = $this->by($request);
        $caid = $this->caidFor($request);
        $d = $request->validate([
            'shift_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'employee_type' => ['nullable', 'required_with:employee_id', 'in:charge,allowance'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required_without:employee_id', 'array', 'max:6'],
            'payments.*.method' => ['required', 'in:' . implode(',', array_keys(self::METHODS))],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
        ], ['lines.required' => 'The cart is empty.', 'payments.required_without' => 'Add a payment.', 'employee_type.required_with' => 'Choose Charge or Allowance.']);

        // employee as customer: Charge (deducted from the employee later) or Allowance (company benefit) → no cash
        $emp = null;
        if (!empty($d['employee_id'])) {
            $emp = $this->employees($caid, (int) $d['employee_id'])->first();
            abort_if(!$emp, 422, 'Employee not found for this client.');
            $d['customer_id'] = null;
        }

        $shift = DB::table('t_pos_shift')->where('added_by', $by)->where('id', $d['shift_id'])->where('status', 'OPEN')->first();
        abort_if(!$shift, 422, 'Open a shift first.');
        $set = $this->settings($by, $caid);

        // items (Stock Items of this client)
        $sales = $this->firstCol('m_items', ['gl_sales_account', 'gl_sales_acount']);
        $unit = $this->firstCol('m_items', ['unit_type_id', 'default_unit_type_id']);
        $tax = $this->firstCol('m_items', ['tax_type_id']);
        $ids = collect($d['lines'])->pluck('item_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
        $items = DB::table('m_items')->where('added_by', $by)->whereIn('id', $ids)
            ->get(array_values(array_filter(['id', 'item_code', 'description',
                $sales ? "{$sales} as sales_acct" : null, $unit ? "{$unit} as unit_type_id" : null, $tax ? "{$tax} as tax_type_id" : null])))
            ->keyBy('id');
        abort_if($items->count() !== count($ids), 422, 'An item in the cart was not found. Refresh the items.');
        $taxNames = $this->taxNames();

        // ---------- lines (same math as Sales) ----------
        $rows = [];
        $receipt = [];
        $gross = $disc = $net = $vat = 0.0;
        $qtyOf = [];
        foreach ($d['lines'] as $l) {
            $it = $items[(int) $l['item_id']];
            $q = round((float) $l['qty'], 4);
            $price = round((float) $l['price'], 4);
            $g = $this->r4($q * $price);
            $ds = min($g, $this->r4($l['discount'] ?? 0));
            $amount = $this->r4($g - $ds);
            $type = $this->vatType($taxNames[$it->tax_type_id ?? 0] ?? '');
            $v = $n = $ex = $non = $zero = 0;
            switch ($type) {
                case 'exempt': $ex = $amount; break;
                case 'zero': $zero = $amount; break;
                case 'nonvat': $non = $amount; break;
                default: $v = $this->r4($amount * 12 / 112); $n = $this->r4($amount - $v);
            }
            $gross += $g; $disc += $ds; $net += $amount; $vat += $v;
            $qtyOf[$it->id] = ($qtyOf[$it->id] ?? 0) + $q;
            $rows[] = [
                'item_id' => (int) $it->id, 'unit_type_id' => $it->unit_type_id ?? null, 'quantity' => $q, 'unit_price' => $price,
                'amount' => $g, 'discount_amount' => $ds, 'input_vat' => $v, 'net_of_vat' => $n, 'vat_exempt' => $ex,
                'non_vat' => $non, 'zero_rated' => $zero, 'account_code' => $it->sales_acct ?? null,
            ];
            $receipt[] = ['item_id' => (int) $it->id, 'code' => $it->item_code, 'name' => $it->description, 'qty' => $q,
                'price' => round($price, 2), 'discount' => round($ds, 2), 'amount' => round($amount, 2), 'vat_type' => $type,
                // for posting to Sales later
                'unit_type_id' => $it->unit_type_id ?? null, 'account_code' => $it->sales_acct ?? null, 'gross' => $g,
                'input_vat' => $v, 'net_of_vat' => $n, 'vat_exempt' => $ex, 'non_vat' => $non, 'zero_rated' => $zero];
        }
        $net = round($net, 2);

        // ---------- stock ----------
        if (!$set['allow_negative']) {
            $onHand = $this->stock($by, array_keys($qtyOf));
            foreach ($qtyOf as $id => $q) {
                if ($q - (float) ($onHand[$id] ?? 0) > 0.00001) {
                    abort(422, "Not enough stock: {$items[$id]->description} (on hand " . rtrim(rtrim(number_format((float) ($onHand[$id] ?? 0), 4, '.', ''), '0'), '.') . ').');
                }
            }
        }

        // ---------- payments: non-cash first, cash covers the rest, change from cash only ----------
        if ($emp) $d['payments'] = [['method' => $d['employee_type'], 'amount' => $net, 'reference' => '']];
        $pays = collect($d['payments'])->map(fn ($p) => ['method' => $p['method'], 'tendered' => round((float) $p['amount'], 2), 'reference' => trim((string) ($p['reference'] ?? ''))]);
        $tendered = round($pays->sum('tendered'), 2);
        abort_if($tendered + 0.001 < $net, 422, 'Payment is short by ' . number_format($net - $tendered, 2) . '.');
        $nonCash = round($pays->where('method', '!=', 'cash')->sum('tendered'), 2);
        abort_if(!$emp && $pays->whereIn('method', array_keys(self::EMP_TYPES))->count(), 422, 'Charge / Allowance is for employees only.');
        abort_if($nonCash - $net > 0.001, 422, 'GCash / Card is more than the total. Only cash can have change.');
        $change = round($tendered - $net, 2);
        $left = $net;
        $applied = [];
        foreach ($pays->sortBy(fn ($p) => $p['method'] === 'cash' ? 1 : 0) as $p) {
            $amt = round(min($p['tendered'], $left), 2);
            $left = round($left - $amt, 2);
            if ($amt > 0) $applied[] = $p + ['amount' => $amt];
        }

        [$uid, $cashierName] = $this->cashier($request);

        return DB::transaction(function () use ($by, $d, $emp, $receipt, $gross, $disc, $net, $vat, $applied, $tendered, $change, $shift, $uid, $cashierName) {
            $receiptNo = $this->nextNo('t_pos_sale', 'receipt_no', 'POS');
            $paysOut = array_map(fn ($p) => ['method' => $p['method'], 'label' => self::ALL_METHODS[$p['method']], 'amount' => $p['amount'],
                'tendered' => $p['tendered'], 'reference' => $p['reference']], $applied);

            // ---------- POS sale only (PENDING: not in Sales / Collection yet) ----------
            $id = DB::table('t_pos_sale')->insertGetId([
                'added_by' => $by, 'shift_id' => $shift->id, 'receipt_no' => $receiptNo, 'invoice_no' => null,
                'customer_id' => $d['customer_id'] ?? null,
                'employee_id' => $emp['id'] ?? null, 'employee_name' => $emp['name'] ?? null,
                'sale_type' => $emp ? strtoupper($d['employee_type']) : 'REGULAR',
                'items' => (int) round(array_sum(array_column($receipt, 'qty'))),
                'sub_total' => round($gross, 2), 'discount' => round($disc, 2), 'total' => $net, 'vat' => round($vat, 2),
                'tendered' => $tendered, 'change_due' => $change, 'payments' => json_encode($paysOut), 'lines' => json_encode($receipt),
                'status' => 'PAID', 'post_status' => 'PENDING',
                'cashier_id' => $uid, 'cashier_name' => $cashierName, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['sale' => $this->saleOut(DB::table('t_pos_sale')->find($id)), 'message' => 'Paid.']);
        });
    }

    /* =====================================================================
       Sales list / receipt / void
       ===================================================================== */
    private function saleOut(object $s): array
    {
        $cust = $s->customer_id ? DB::table('m_vendor_customer')->where('id', $s->customer_id)->first(['name', 'tin', 'address']) : null;
        return [
            'id' => (int) $s->id, 'receipt_no' => $s->receipt_no, 'invoice_no' => $s->invoice_no, 'shift_id' => $s->shift_id ? (int) $s->shift_id : null,
            'customer' => !empty($s->employee_id) ? ($s->employee_name ?: 'Employee') : ($cust->name ?? 'Walk-in'),
            'customer_tin' => $cust->tin ?? null, 'customer_address' => $cust->address ?? null,
            'employee_id' => !empty($s->employee_id) ? (int) $s->employee_id : null,
            'sale_type' => $s->sale_type ?? 'REGULAR',      // REGULAR · CHARGE · ALLOWANCE
            'items' => (int) $s->items, 'sub_total' => round((float) $s->sub_total, 2), 'discount' => round((float) $s->discount, 2),
            'total' => round((float) $s->total, 2), 'vat' => round((float) $s->vat, 2),
            'tendered' => round((float) $s->tendered, 2), 'change' => round((float) $s->change_due, 2),
            'payments' => json_decode($s->payments, true) ?: [], 'lines' => json_decode($s->lines, true) ?: [],
            'status' => $s->status, 'post_status' => $s->post_status ?? null, 'void_reason' => $s->void_reason, 'cashier' => $s->cashier_name, 'date' => (string) $s->created_at,
        ];
    }

    /* GET /api/pos/sales?shift_id=  (default: open shift's)  or ?date=YYYY-MM-DD */
    public function sales(Request $request)
    {
        $by = $this->by($request);
        $d = $request->validate(['shift_id' => ['nullable', 'integer'], 'date' => ['nullable', 'date']]);
        $rows = DB::table('t_pos_sale')->where('added_by', $by)
            ->when(!empty($d['shift_id']), fn ($q) => $q->where('shift_id', $d['shift_id']))
            ->when(empty($d['shift_id']), fn ($q) => $q->whereDate('created_at', $d['date'] ?? now()->toDateString()))
            ->orderByDesc('id')->limit(500)->get();
        return response()->json($rows->map(fn ($s) => $this->saleOut($s))->values());
    }

    /* GET /api/pos/sales/{id} */
    public function sale(Request $request, int $id)
    {
        $s = DB::table('t_pos_sale')->where('added_by', $this->by($request))->where('id', $id)->first();
        abort_if(!$s, 404, 'Sale not found.');
        return response()->json($this->saleOut($s));
    }

    /* POST /api/pos/sales/{id}/void  { reason }   pending sale → VOID (stock returns); a posted one also voids its invoice */
    public function voidSale(Request $request, int $id)
    {
        $by = $this->by($request);
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Type the reason.']);
        $s = DB::table('t_pos_sale')->where('added_by', $by)->where('id', $id)->first();
        abort_if(!$s, 404, 'Sale not found.');
        abort_if($s->status === 'VOID', 422, 'Already void.');
        $caid = $this->caidFor($request);
        $inv = $s->invoice_no ? SalesInvoiceMaster::where('added_by', $by)->where('invoice_no', $s->invoice_no)->first() : null;   // pending → no invoice yet
        abort_if($inv && $caid && DeclaredMonthSettings::where('ca_id', $caid)->where('declared_month', $inv->declared_month)->exists(), 422, 'Declared Month is disabled');
        $voidId = $this->statusIdOf('VOID') ?: self::VOID;

        DB::transaction(function () use ($s, $inv, $voidId, $by, $d) {
            if ($inv) {
                $inv->forceFill(['invoice_status_id' => $voidId])->save();
                DB::table('t_sales_invoice_detail')->where('invoice_no', $inv->invoice_no)->update(['invoice_status_id' => $voidId, 'updated_at' => now()]);
                // in case the trigger is not installed: remove this sale's collections
                $rvNos = collect(json_decode($s->payments, true) ?: [])->pluck('rvoucher_no')->filter()->all();
                if ($rvNos) {
                    DB::table('t_rvoucher_detail')->where('added_by', $by)->whereIn('rvoucher_no', $rvNos)->delete();
                    DB::table('t_rvoucher_master')->where('added_by', $by)->whereIn('rvoucher_no', $rvNos)->delete();
                }
            }
            DB::table('t_pos_sale')->where('id', $s->id)->update(['status' => 'VOID', 'void_reason' => trim($d['reason']), 'updated_at' => now()]);
        });
        return response()->json(['sale' => $this->saleOut(DB::table('t_pos_sale')->find($s->id)), 'message' => "{$s->receipt_no} voided."]);
    }
}