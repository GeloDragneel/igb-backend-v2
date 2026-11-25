<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost of Stock Items sold, by the client's Inventory Method (Task → Inventory → Settings).
 *
 *   FIFO      – stock-in layers are used up oldest first
 *   WAC       – moving weighted average: recomputed on every stock-in, every sale uses the current average
 *   SPECIFIC  – the item's own cost (m_items.last_unit_cost): there is no lot / serial link to a receipt to pick from
 *
 * Stock in  : beginning balance (t_beg_balance_product, earliest one per item)
 *             + Receive Inventory (t_grn_detail, not void)
 *             + AP lines of trans_type = Purchases with an item (t_purchase_expense_detail, posted)
 * Stock out : posted sales lines (t_sales_invoice_detail, not void)
 * Only m_item_class 1 = Stock Item.  Same date: stock-in before sales.
 * Selling more than what is on hand: the missing units use the last known cost (else the item's last unit cost).
 *
 *   InventoryCosting::costs($userIds, $caid)          →  [sales detail id => cost]
 *   InventoryCosting::value($userIds, $caid, $upTo)    →  value of the stock on hand after every movement dated ≤ $upTo
 *                                                         ($upTo 'Y-m-d'; 'Y-m-00' = only that month's beginning balance)
 */
class InventoryCosting
{
    public const STOCK_ITEM_CLASS = 1;
    public const METHODS = ['FIFO', 'WAC', 'SPECIFIC'];

    private static array $cache = [];
    private static array $eventCache = [];
    private static array $cols = [];

    /** the client's method (default FIFO) */
    public static function method(?int $caid): string
    {
        if (!$caid || !Schema::hasTable('m_inventory_settings')) return 'FIFO';
        $m = strtoupper((string) DB::table('m_inventory_settings')->where('caid', $caid)->value('inventory_method'));
        return in_array($m, self::METHODS, true) ? $m : 'FIFO';
    }

    /** cost of every stock-item sales line of these logins: [t_sales_invoice_detail.id => cost] */
    public static function costs(array $userIds, ?int $caid): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return [];
        $method = self::method($caid);
        $key = $method . '|' . implode(',', $userIds);
        return self::$cache[$key] ??= self::replay(self::events($userIds), $method)['costs'];
    }

    /** value of the Stock Items on hand after every movement dated on or before $upTo */
    public static function value(array $userIds, ?int $caid, string $upTo): float
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) return 0.0;
        return round(self::replay(self::events($userIds), self::method($caid), $upTo)['value'], 2);
    }

    /* ------------------------------------------------------------------ */

    private static function cols(string $table): array
    {
        return self::$cols[$table] ??= (Schema::hasTable($table) ? Schema::getColumnListing($table) : []);
    }

    private static function pick(string $table, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, self::cols($table), true)) return $n;
        return null;
    }

    /** amount without VAT: net of VAT + exempt + non-VAT + zero rated (only the columns that exist) */
    private static function amountSql(string $table, string $alias): string
    {
        $parts = [];
        foreach (['net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated'] as $c) {
            if (in_array($c, self::cols($table), true)) $parts[] = "COALESCE({$alias}.{$c},0)";
        }
        return $parts ? '(' . implode('+', $parts) . ')' : '0';
    }

    /** every stock movement of these logins, sorted: [item, date, 0 in | 1 out, seq, qty, unit cost | sales line id, item cost] */
    private static function events(array $ids): array
    {
        $key = implode(',', $ids);
        if (isset(self::$eventCache[$key])) return self::$eventCache[$key];
        $events = [];   // [item, date, order (0 in / 1 out), seq, qty, unit cost | sales line id]
        $seq = 0;

        $stockItems = fn ($q, string $col) => $q->join('m_items as I', 'I.id', '=', $col)->where('I.item_class_id', self::STOCK_ITEM_CLASS);

        // 1) beginning balance: the earliest one per item
        if (self::cols('t_beg_balance_product')) {
            $qty = self::pick('t_beg_balance_product', ['quantity', 'qty']);
            $uc  = self::pick('t_beg_balance_product', ['unit_cost', 'cost']);
            $dm  = self::pick('t_beg_balance_product', ['declared_month', 'month']);
            if ($qty && $uc) {
                $rows = $stockItems(DB::table('t_beg_balance_product as B'), 'B.item_id')
                    ->whereIn('B.added_by', $ids)
                    ->get(['B.item_id', "B.{$qty} as q", "B.{$uc} as c", DB::raw(($dm ? "B.{$dm}" : "''") . ' as dm')]);
                $first = [];
                foreach ($rows as $r) {
                    $d = substr((string) $r->dm, 0, 7);
                    if (!isset($first[$r->item_id]) || $d < $first[$r->item_id]->d) $first[$r->item_id] = (object) ['d' => $d, 'q' => (float) $r->q, 'c' => (float) $r->c];
                }
                foreach ($first as $item => $b) {
                    if ($b->q > 0) $events[] = [(int) $item, ($b->d ?: '0000-00') . '-00', 0, $seq++, $b->q, $b->c];
                }
            }
        }

        // 2) Receive Inventory
        $gq = self::pick('t_grn_detail', ['qty', 'quantity']);
        if ($gq && in_array('item_id', self::cols('t_grn_detail'), true)) {
            $q = $stockItems(DB::table('t_grn_detail as X')->join('t_grn_master as G', 'G.grn_no', '=', 'X.grn_no'), 'X.item_id')
                ->whereIn('X.receive_by', $ids);
            if (in_array('grn_status_id', self::cols('t_grn_detail'), true)) $q->where(fn ($w) => $w->whereNull('X.grn_status_id')->orWhere('X.grn_status_id', '<>', 5));
            if (in_array('invoice_status_id', self::cols('t_grn_master'), true)) $q->where('G.invoice_status_id', '<>', 5);
            $total = in_array('total', self::cols('t_grn_detail'), true) ? 'COALESCE(X.total,0)' : self::amountSql('t_grn_detail', 'X');
            foreach ($q->selectRaw("X.item_id, G.grn_date AS d, X.{$gq} AS q, {$total} AS amt")->get() as $r) {
                $qty = (float) $r->q;
                if ($qty > 0) $events[] = [(int) $r->item_id, substr((string) $r->d, 0, 10), 0, $seq++, $qty, (float) $r->amt / $qty];
            }
        }

        // 3) AP purchase lines with an item
        $pq = self::pick('t_purchase_expense_detail', ['quantity', 'qty']);
        if ($pq && in_array('item_id', self::cols('t_purchase_expense_detail'), true)) {
            $q = $stockItems(DB::table('t_purchase_expense_detail as X')->join('t_purchase_expense_master as M', 'M.invoice_no', '=', 'X.invoice_no'), 'X.item_id')
                ->whereIn('M.added_by', $ids)->where('M.invoice_status_id', '<>', 5)
                ->where('M.record_status', 'posted')->where('X.record_status', 'posted')
                ->whereRaw('LOWER(M.trans_type) = ?', ['purchases']);
            foreach ($q->selectRaw('X.item_id, M.transaction_date AS d, X.' . $pq . ' AS q, ' . self::amountSql('t_purchase_expense_detail', 'X') . ' AS amt')->get() as $r) {
                $qty = (float) $r->q;
                if ($qty > 0) $events[] = [(int) $r->item_id, substr((string) $r->d, 0, 10), 0, $seq++, $qty, (float) $r->amt / $qty];
            }
        }

        // 4) sales
        $sales = $stockItems(DB::table('t_sales_invoice_detail as X'), 'X.item_id')
            ->whereIn('X.added_by', $ids)->where('X.invoice_status_id', '<>', 5)->where('X.trans_type', 'posted')
            ->orderBy('X.id')
            ->get(['X.id', 'X.item_id', 'X.transaction_date as d', 'X.quantity as q', 'I.last_unit_cost as luc']);
        foreach ($sales as $r) {
            $events[] = [(int) $r->item_id, substr((string) $r->d, 0, 10), 1, $seq++, (float) $r->q, (int) $r->id];
        }

        // the item's own cost (Specific + fallback when nothing was received)
        $itemCost = DB::table('m_items')->whereIn('id', array_unique(array_column($events, 0)) ?: [0])->pluck('last_unit_cost', 'id')->all();
        foreach ($events as &$e) $e[6] = (float) ($itemCost[$e[0]] ?? 0);
        unset($e);

        // per item, in date order (stock-in before sales on the same date)
        usort($events, fn ($a, $b) => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]]);
        return self::$eventCache[$key] = $events;
    }

    /**
     * play the movements in order → ['costs' => [sales line id => cost], 'value' => value on hand at the end]
     * $upTo: stop after the movements dated on or before it
     */
    private static function replay(array $events, string $method, ?string $upTo = null): array
    {
        $out = [];
        $value = 0.0;
        $close = function () use (&$value, &$layers, &$onHand, &$avg, &$specQty, &$item, $method, &$lastItemCost) {
            if ($item === null) return;
            if ($method === 'FIFO') foreach ($layers as [$q, $c]) $value += $q * $c;
            elseif ($method === 'WAC') $value += max($onHand, 0) * $avg;
            else $value += max($specQty, 0) * $lastItemCost;
        };
        $item = null;
        $layers = [];            // FIFO: [[qty, cost], …]
        $onHand = 0.0;           // WAC
        $avg = 0.0;
        $specQty = 0.0;          // SPECIFIC: qty on hand
        $last = 0.0;             // last known unit cost of the item
        $lastItemCost = 0.0;
        foreach ($events as [$it, $d, $kind, $s, $qty, $x, $ic]) {
            if ($upTo !== null && $d > $upTo) continue;
            if ($it !== $item) {
                $close();
                $item = $it;
                $layers = [];
                $onHand = 0.0;
                $avg = 0.0;
                $specQty = 0.0;
                $last = $lastItemCost = (float) $ic;
            }

            if ($method === 'SPECIFIC') {           // the item's own cost
                if ($kind === 0) $specQty += $qty;
                else {
                    $specQty -= $qty;
                    $out[(int) $x] = round($qty * $ic, 2);
                }
                continue;
            }

            if ($kind === 0) {                      // stock in
                $cost = (float) $x;
                $last = $cost;
                if ($method === 'FIFO') {
                    $layers[] = [$qty, $cost];
                } else {                            // WAC
                    $base = max($onHand, 0);
                    $avg = ($base + $qty) > 0 ? ($base * $avg + $qty * $cost) / ($base + $qty) : $cost;
                    $onHand = $base + $qty;
                }
                continue;
            }

            // sale
            $cost = 0.0;
            if ($method === 'FIFO') {
                $need = $qty;
                while ($need > 1e-9 && $layers) {
                    $take = min($need, $layers[0][0]);
                    $cost += $take * $layers[0][1];
                    $layers[0][0] -= $take;
                    $need -= $take;
                    if ($layers[0][0] <= 1e-9) array_shift($layers);
                }
                if ($need > 1e-9) $cost += $need * $last;     // sold more than on hand
            } else {
                $unit = $avg > 0 ? $avg : $last;
                $cost = $qty * $unit;
                $onHand -= $qty;
            }
            $out[(int) $x] = round($cost, 2);
        }
        $close();
        return ['costs' => $out, 'value' => $value];
    }
}
