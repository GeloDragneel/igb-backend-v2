<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Other Reports → Inventory
 *   costing        Item Costing Report        (3 sheets: Cost Price / Sales Price / Margin Price)
 *   profitability  Inventory Profitability Report
 *   cogs           Cost of Goods Sold Journal
 *   adjustment     Inventory Adjustment Journal
 *
 * Costing = FIFO per item, from the first movement (beginning balance + every receipt / sale / adjustment),
 * so the cost of sales and the remaining value are right even when the period starts in the middle of the year.
 *   Received   = Receive Inventory lines (t_grn_detail, receipt not void)        qty × price
 *   Sold       = Sales invoice lines (t_sales_invoice_detail, not void / invalid / adjustment)
 *   Adjustment = t_inventory_adjustment (+ adds a layer at the last cost, − takes FIFO)
 *   Beginning  = t_beg_balance_product (quantity, total_cost, declared_month)
 *
 * GET /api/reports/inventory/{report}?from=2026-01-01&to=2026-12-31        → { title, sheets: [{ name, columns, rows }] }
 * GET /api/reports/inventory/{report}/excel?from=…&to=…                    → .xlsx (same layout as the old reports)
 */
class InventoryReportController extends SalesController
{
    const VOID = 5;
    const REPORTS = [
        'costing'       => ['title' => 'Item Costing Report', 'file' => 'Item_Costing_Report'],
        'profitability' => ['title' => 'Inventory Profitability Report', 'file' => 'Inventory_Profitability_Report'],
        'cogs'          => ['title' => 'Cost of Goods Sold Journal', 'file' => 'COST_OF_GOOD_SOLD_JOURNAL'],
        'adjustment'    => ['title' => 'Inventory Adjustment Journal', 'file' => 'Inventory_Adjustment_Journal'],
    ];

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

    /* =====================================================================
       Routes
       ===================================================================== */
    public function invReport(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404, 'Report not found.');
        [$from, $to] = $this->invPeriod($request);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['title' => self::REPORTS[$report]['title'], 'sheets' => []]);

        return response()->json([
            'title'  => self::REPORTS[$report]['title'],
            'from'   => $from,
            'to'     => $to,
            'sheets' => $this->invSheets((int) $by, $report, $from, $to),
        ]);
    }

    public function invExcel(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404, 'Report not found.');
        [$from, $to] = $this->invPeriod($request);
        $by = (int) $this->addedByFor($request);
        $sheets = $by ? $this->invSheets($by, $report, $from, $to) : [];

        $ss = new Spreadsheet();
        $ss->removeSheetByIndex(0);
        foreach ($sheets as $i => $s) $this->invWriteSheet($ss->createSheet($i), $s, $report);
        if (!$sheets) $ss->createSheet();
        $ss->setActiveSheetIndex(0);

        $dir = storage_path('app/print-tmp');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . DIRECTORY_SEPARATOR . 'inv' . bin2hex(random_bytes(6)) . '.xlsx';
        (new Xlsx($ss))->save($file);
        $ss->disconnectWorksheets();

        return response()->download($file, self::REPORTS[$report]['file'] . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function invPeriod(Request $request): array
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        $from = substr($d['from'], 0, 10);
        $to = substr($d['to'], 0, 10);
        if (strlen($d['from']) === 7) $from = $d['from'] . '-01';
        if (strlen($d['to']) === 7) $to = date('Y-m-t', strtotime($d['to'] . '-01'));
        if ($to < $from) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    /* =====================================================================
       FIFO engine: every movement of every inventory item, up to "to"
       ===================================================================== */
    private function invItems(int $by)
    {
        $inv = $this->firstCol('m_items', ['gl_inventory_account', 'gl_inventory_acount']);
        $cos = $this->firstCol('m_items', ['gl_cost_of_sales_account', 'gl_cost_of_sales_acount']);
        $class = $this->firstCol('m_items', ['item_class_id']);
        return DB::table('m_items')->where('added_by', $by)
            ->when($class, fn ($q) => $q->where($class, 1))                       // inventory (stock) items
            ->orderBy('item_code')
            ->get(array_values(array_filter(['id', 'item_code', 'description', 'price', 'last_unit_cost',
                $inv ? "{$inv} as inv_acct" : null, $cos ? "{$cos} as cos_acct" : null, $class ? "{$class} as class_id" : null])))
            ->keyBy('id');
    }

    /** movements per item, sorted: date, then receipts → adjustments → sales */
    private function invMoves(int $by, array $itemIds, string $to): array
    {
        if (!$itemIds) return [];
        $moves = [];

        // beginning balance
        $bt = 't_beg_balance_product';
        if (Schema::hasTable($bt)) {
            $bCost = $this->firstCol($bt, ['total_cost', 'total', 'amount']);
            $bMonth = $this->firstCol($bt, ['declared_month']);
            foreach (DB::table($bt)->where('added_by', $by)->whereIn('item_id', $itemIds)->get() as $b) {
                $q = (float) ($b->quantity ?? 0);
                if (!$q) continue;
                $date = $bMonth && $b->{$bMonth} ? substr($b->{$bMonth}, 0, 7) . '-01' : '1900-01-01';
                if ($date > $to) continue;
                $moves[] = ['t' => 'beg', 'o' => 0, 'item' => (int) $b->item_id, 'date' => $date, 'id' => (int) $b->id,
                    'qty' => $q, 'cost' => $bCost ? (float) $b->{$bCost} / $q : null, 'ref' => 'Beginning'];
            }
        }

        // receipts (Receive Inventory)
        foreach (DB::table('t_grn_detail as L')->join('t_grn_master as M', 'M.grn_no', '=', 'L.grn_no')
            ->where('L.receive_by', $by)->whereIn('L.item_id', $itemIds)
            ->where('M.invoice_status_id', '<>', self::VOID)->where('L.grn_date', '<=', $to)
            ->get(['L.id', 'L.item_id', 'L.grn_date', 'L.qty', 'L.price', 'L.grn_no']) as $r) {
            if (!(float) $r->qty) continue;
            $moves[] = ['t' => 'in', 'o' => 1, 'item' => (int) $r->item_id, 'date' => substr((string) $r->grn_date, 0, 10), 'id' => (int) $r->id,
                'qty' => (float) $r->qty, 'cost' => (float) $r->price, 'ref' => (string) $r->grn_no];
        }

        // adjustments
        if (Schema::hasTable('t_inventory_adjustment')) {
            $reasons = Schema::hasTable('m_inventory_adjustment_reasons') ? DB::table('m_inventory_adjustment_reasons')->pluck('name', 'id') : collect();
            foreach (DB::table('t_inventory_adjustment')->where('added_by', $by)->whereIn('item_id', $itemIds)
                ->where('transaction_date', '<=', $to)->get() as $r) {
                if (!(float) $r->qty) continue;
                $moves[] = ['t' => 'adj', 'o' => 2, 'item' => (int) $r->item_id, 'date' => substr((string) $r->transaction_date, 0, 10), 'id' => (int) $r->id,
                    'qty' => (float) $r->qty, 'ref' => '', 'reason' => (string) ($reasons[$r->reason_id ?? 0] ?? '')];
            }
        }

        // sales
        $sq = DB::table('t_sales_invoice_detail as L')->where('L.added_by', $by)->whereIn('L.item_id', $itemIds)
            ->where('L.transaction_date', '<=', $to)
            ->where(fn ($w) => $w->whereNull('L.invoice_status_id')->orWhere('L.invoice_status_id', '<>', self::VOID));
        if (in_array('trans_type', $this->colsOf('t_sales_invoice_detail'), true)) {
            $sq->where(fn ($w) => $w->whereNull('L.trans_type')->orWhereNotIn('L.trans_type', ['invalid', 'adjustment']));
        }
        $doc = in_array('document_no', $this->colsOf('t_sales_invoice_detail'), true) ? 'L.document_no' : 'L.invoice_no';
        foreach ($sq->get(['L.id', 'L.item_id', 'L.transaction_date', 'L.quantity', 'L.unit_price', 'L.amount', 'L.discount_amount', 'L.invoice_no', DB::raw("{$doc} AS doc")]) as $r) {
            if (!(float) $r->quantity) continue;
            $moves[] = ['t' => 'out', 'o' => 3, 'item' => (int) $r->item_id, 'date' => substr((string) $r->transaction_date, 0, 10), 'id' => (int) $r->id,
                'qty' => (float) $r->quantity, 'price' => (float) $r->unit_price,
                'amount' => (float) $r->amount - (float) ($r->discount_amount ?? 0), 'ref' => (string) ($r->doc ?: $r->invoice_no)];
        }

        usort($moves, fn ($a, $b) => [$a['item'], $a['date'], $a['o'], $a['id']] <=> [$b['item'], $b['date'], $b['o'], $b['id']]);
        $by = [];
        foreach ($moves as $m) $by[$m['item']][] = $m;
        return $by;
    }

    /**
     * Runs FIFO for every item; returns per item:
     *   open  = [qty, value] before "from"
     *   rows  = movements inside the period, each with cost (FIFO), remaining qty / value after it
     */
    private function invFifo(int $by, string $from, string $to): array
    {
        $items = $this->invItems($by);
        $moves = $this->invMoves($by, $items->keys()->map(fn ($x) => (int) $x)->all(), $to);
        $out = [];

        foreach ($items as $id => $it) {
            $layers = [];                                  // [[qty, cost], …] oldest first
            $lastCost = (float) ($it->last_unit_cost ?: $it->price ?: 0);
            $open = null;
            $rows = [];
            $remQty = fn () => array_sum(array_map(fn ($l) => $l[0], $layers));
            $remVal = fn () => array_sum(array_map(fn ($l) => $l[0] * $l[1], $layers));
            // take $q out FIFO → cost; past the last layer: at the last cost (stock goes negative)
            $take = function (float $q) use (&$layers, &$lastCost): float {
                $cost = 0.0;
                while ($q > 0.0000001 && $layers && $layers[0][0] > 0) {
                    $use = min($q, $layers[0][0]);
                    $cost += $use * $layers[0][1];
                    $layers[0][0] -= $use;
                    $q -= $use;
                    if ($layers[0][0] <= 0.0000001) array_shift($layers);
                }
                if ($q > 0.0000001) {
                    $cost += $q * $lastCost;
                    if ($layers && $layers[0][0] < 0) $layers[0][0] -= $q;
                    else array_unshift($layers, [-$q, $lastCost]);
                }
                return $cost;
            };
            $add = function (float $q, float $c) use (&$layers, &$lastCost) {
                $lastCost = $c ?: $lastCost;
                // first fill a negative (oversold) layer
                if ($layers && $layers[0][0] < 0) {
                    $fill = min($q, -$layers[0][0]);
                    $layers[0][0] += $fill;
                    $q -= $fill;
                    if (abs($layers[0][0]) < 0.0000001) array_shift($layers);
                }
                if ($q > 0.0000001) $layers[] = [$q, $c];
            };

            foreach ($moves[$id] ?? [] as $m) {
                if ($open === null && $m['date'] >= $from) $open = [$remQty(), $remVal()];
                $in = $m['date'] >= $from;
                $row = ['type' => $m['t'], 'date' => $m['date'], 'ref' => $m['ref'], 'qty' => $m['qty'], 'reason' => $m['reason'] ?? ''];

                if ($m['t'] === 'beg' || $m['t'] === 'in') {
                    $c = $m['cost'] ?? $lastCost;
                    $add($m['qty'], $c);
                    $row += ['cost' => $c, 'value' => $m['qty'] * $c];
                } elseif ($m['t'] === 'adj') {
                    if ($m['qty'] > 0) { $c = $lastCost; $add($m['qty'], $c); $row += ['cost' => $c, 'value' => $m['qty'] * $c]; }
                    else { $v = $take(-$m['qty']); $row += ['cost' => $v / max(0.0000001, -$m['qty']), 'value' => -$v]; }
                } else {
                    $v = $take($m['qty']);
                    $row += ['cost' => $v / $m['qty'], 'value' => $v, 'price' => $m['price'], 'amount' => $m['amount']];
                }
                $row['rem_qty'] = $remQty();
                $row['rem_value'] = $remVal();
                if ($in) $rows[] = $row;
            }
            $open ??= [$remQty(), $remVal()];
            if (!$rows && abs($open[0]) < 0.0000001) continue;           // nothing to show for this item
            $out[$id] = ['item' => $it, 'open' => $open, 'rows' => $rows];
        }
        return $out;
    }

    /* =====================================================================
       Sheets (screen and Excel use the same data)
       columns: [key, label, type: text|date|num|pct]
       rows:    ['kind' => group|line|total, 'cells' => [key => value]]
       ===================================================================== */
    private function invSheets(int $by, string $report, string $from, string $to): array
    {
        $fifo = $this->invFifo($by, $from, $to);
        return match ($report) {
            'costing'       => $this->invCosting($fifo),
            'profitability' => [$this->invProfit($fifo)],
            'cogs'          => [$this->invCogs($by, $fifo)],
            'adjustment'    => [$this->invAdjust($by, $fifo)],
        };
    }

    private function invCosting(array $fifo): array
    {
        $r2 = fn ($v) => round((float) $v, 2);
        $cost = ['name' => 'Cost Price Report', 'columns' => [
            ['item_code', 'Item ID', 'text'], ['description', 'Item Description', 'text'], ['date', 'Date', 'date'],
            ['qty_in', 'Qty Received', 'num'], ['unit', 'Item Cost', 'num'], ['value_in', 'Actual Cost', 'num'],
            ['asm_qty', 'Assembly Qty', 'num'], ['asm_value', 'Assembly ($)', 'num'], ['adj_qty', 'Adjust Qty', 'num'], ['adj_value', 'Adjust ($)', 'num'],
            ['qty_sold', 'Quantity Sold', 'num'], ['sold_value', 'Cost of Sales', 'num'], ['rem_qty', 'Remaining Qty', 'num'], ['rem_value', 'Remain Value', 'num'],
        ], 'rows' => []];
        $sales = ['name' => 'Sales Price Report', 'columns' => [
            ['item_code', 'Item ID', 'text'], ['description', 'Item Description', 'text'], ['date', 'Date', 'date'],
            ['qty_in', 'Qty Received', 'num'], ['unit', 'Item Price', 'num'], ['value_in', 'Actual Price', 'num'],
            ['asm_qty', 'Assembly Qty', 'num'], ['asm_value', 'Assembly Price', 'num'], ['adj_qty', 'Adjust Qty', 'num'], ['adj_value', 'Adjust Price', 'num'],
            ['qty_sold', 'Quantity Sold', 'num'], ['sold_value', 'Price of Sales', 'num'], ['rem_qty', 'Remaining Qty', 'num'], ['rem_value', 'Remain Price', 'num'],
        ], 'rows' => []];
        $margin = ['name' => 'Margin Price Report', 'columns' => [
            ['item_code', 'Item ID', 'text'], ['description', 'Item Description', 'text'], ['date', 'Date', 'date'],
            ['qty_in', 'Qty Received', 'num'], ['price', 'Item Price', 'num'], ['cost', 'Item Cost', 'num'], ['margin', 'Item Margin', 'num'], ['margin_value', 'Actual Margin', 'num'],
            ['asm_qty', 'Assembly Qty', 'num'], ['asm_price', 'Assembly Price', 'num'], ['asm_cost', 'Assembly Cost', 'num'],
            ['adj_qty', 'Adjust Qty', 'num'], ['adj_price', 'Adjust Price', 'num'], ['qty_sold', 'Quantity Sold', 'num'], ['sales_margin', 'Sales Margin', 'num'],
            ['rem_qty', 'Remaining Qty', 'num'], ['rem_margin', 'Remain Margin', 'num'],
        ], 'rows' => []];

        foreach ($fifo as $f) {
            $it = $f['item'];
            $price = (float) $it->price;
            $head = ['item_code' => (string) $it->item_code, 'description' => (string) $it->description];
            // item row: what was on hand when the period started
            $openC = $head + ($f['open'][0] ? ['rem_qty' => $r2($f['open'][0]), 'rem_value' => $r2($f['open'][1])] : []);
            $cost['rows'][] = ['kind' => 'group', 'cells' => $openC];
            $sales['rows'][] = ['kind' => 'group', 'cells' => $head + ($f['open'][0] ? ['rem_qty' => $r2($f['open'][0]), 'rem_value' => $r2($f['open'][0] * $price)] : [])];
            $margin['rows'][] = ['kind' => 'group', 'cells' => $head + ($f['open'][0] ? ['rem_qty' => $r2($f['open'][0]), 'rem_margin' => $r2($f['open'][0] * $price - $f['open'][1])] : [])];

            foreach ($f['rows'] as $r) {
                $base = $head + ['date' => $r['date'], 'rem_qty' => $r2($r['rem_qty'])];
                $c = $base + ['rem_value' => $r2($r['rem_value'])];
                $s = $base + ['rem_value' => $r2($r['rem_qty'] * $price)];
                $m = $base + ['rem_margin' => $r2($r['rem_qty'] * $price - $r['rem_value'])];
                if ($r['type'] === 'in' || $r['type'] === 'beg') {
                    $c += ['qty_in' => $r2($r['qty']), 'unit' => $r2($r['cost']), 'value_in' => $r2($r['value'])];
                    $s += ['qty_in' => $r2($r['qty']), 'unit' => $r2($price), 'value_in' => $r2($r['qty'] * $price)];
                    $m += ['qty_in' => $r2($r['qty']), 'price' => $r2($price), 'cost' => $r2($r['cost']),
                        'margin' => $r2($price - $r['cost']), 'margin_value' => $r2(($price - $r['cost']) * $r['qty'])];
                } elseif ($r['type'] === 'adj') {
                    $c += ['adj_qty' => $r2($r['qty']), 'adj_value' => $r2($r['value'])];
                    $s += ['adj_qty' => $r2($r['qty']), 'adj_value' => $r2($r['qty'] * $price)];
                    $m += ['adj_qty' => $r2($r['qty']), 'adj_price' => $r2($r['qty'] * $price)];
                } else {
                    $c += ['qty_sold' => $r2($r['qty']), 'sold_value' => $r2($r['value'])];
                    $s += ['qty_sold' => $r2($r['qty']), 'sold_value' => $r2($r['amount'])];
                    $m += ['qty_sold' => $r2($r['qty']), 'sales_margin' => $r2($r['amount'] - $r['value'])];
                }
                $cost['rows'][] = ['kind' => 'line', 'cells' => $c];
                $sales['rows'][] = ['kind' => 'line', 'cells' => $s];
                $margin['rows'][] = ['kind' => 'line', 'cells' => $m];
            }
        }
        return [$cost, $sales, $margin];
    }

    private function invProfit(array $fifo): array
    {
        $lines = [];
        foreach ($fifo as $f) {
            $u = $s = $c = 0.0;
            foreach ($f['rows'] as $r) {
                if ($r['type'] !== 'out') continue;
                $u += $r['qty'];
                $s += $r['amount'];
                $c += $r['value'];
            }
            if (!$u) continue;
            $lines[] = ['it' => $f['item'], 'u' => $u, 's' => round($s, 2), 'c' => round($c, 2)];
        }
        $gpAll = array_sum(array_map(fn ($l) => $l['s'] - $l['c'], $lines));
        $classes = Schema::hasTable('m_item_class') ? DB::table('m_item_class')->pluck('name', 'id') : collect();

        $rows = [];
        foreach ($lines as $l) {
            $gp = round($l['s'] - $l['c'], 2);
            $rows[] = ['kind' => 'line', 'cells' => [
                'item_code' => (string) $l['it']->item_code, 'description' => (string) $l['it']->description,
                'units' => round($l['u'], 2), 'sales' => $l['s'], 'cost' => $l['c'], 'gp' => $gp,
                'gp_pct' => $l['s'] != 0 ? round($gp / $l['s'] * 100, 2) : 0,
                'share' => abs($gpAll) > 0.004 ? round($gp / $gpAll * 100, 7) : 0,          // share of the total gross profit
                'class' => (string) ($classes[$l['it']->class_id ?? 0] ?? 'Stock item'),
            ]];
        }
        $sum = fn ($k) => round(array_sum(array_map(fn ($r) => $r['cells'][$k], $rows)), 2);
        if ($rows) $rows[] = ['kind' => 'total', 'cells' => ['units' => $sum('units'), 'sales' => $sum('sales'), 'cost' => $sum('cost'), 'gp' => $sum('gp'), 'share' => $sum('share')]];

        return ['name' => 'Inventory Profitability Report', 'columns' => [
            ['item_code', 'Item ID', 'text'], ['description', 'Item Description', 'text'], ['units', 'Units Sold', 'num'],
            ['sales', 'Sales($)', 'num'], ['cost', 'Cost($)', 'num'], ['gp', 'Gross Profit($)', 'num'], ['gp_pct', 'Gross Profit(%)', 'num'],
            ['share', '% of Total', 'num'], ['class', 'Item Class', 'text'],
        ], 'rows' => $rows];
    }

    private function invJournalCols(): array
    {
        return [
            ['date', 'Date', 'date'], ['account', 'GL Acct ID', 'text'], ['ref', 'Reference', 'text'], ['qty', 'Qty', 'num'],
            ['line', 'Line Description', 'text'], ['account_name', 'GL Acct Description', 'text'], ['debit', 'Debit Amount', 'num'], ['credit', 'Credit Amount', 'num'],
        ];
    }

    private function invTotals(array $rows): array
    {
        if (!$rows) return $rows;
        $rows[] = ['kind' => 'total', 'cells' => ['ref' => 'Total',
            'debit' => round(array_sum(array_map(fn ($r) => (float) ($r['cells']['debit'] ?? 0), $rows)), 2),
            'credit' => round(array_sum(array_map(fn ($r) => (float) ($r['cells']['credit'] ?? 0), $rows)), 2)]];
        return $rows;
    }

    /** Cost of Goods Sold Journal: each sale → Cr Inventory, Dr Cost of Sales (FIFO cost) */
    private function invCogs(int $by, array $fifo): array
    {
        $names = ChartsOfAccount::where('added_by', $by)->pluck('account_name', 'account_code');
        $out = [];
        foreach ($fifo as $f) {
            $it = $f['item'];
            foreach ($f['rows'] as $r) {
                if ($r['type'] !== 'out') continue;
                $v = round($r['value'], 2);
                $base = ['date' => $r['date'], 'ref' => (string) $r['ref'], 'qty' => round($r['qty'], 2), 'line' => (string) $it->description];
                $out[] = ['date' => $r['date'], 'rows' => [
                    ['kind' => 'line', 'cells' => $base + ['account' => (string) ($it->inv_acct ?? ''), 'account_name' => (string) ($names[$it->inv_acct ?? ''] ?? ''), 'credit' => $v]],
                    ['kind' => 'line', 'cells' => $base + ['account' => (string) ($it->cos_acct ?? ''), 'account_name' => (string) ($names[$it->cos_acct ?? ''] ?? ''), 'debit' => $v]],
                ]];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $rows = array_merge(...array_map(fn ($x) => $x['rows'], $out ?: [['rows' => []]]));
        return ['name' => 'Cost of Goods Sold Journal', 'columns' => $this->invJournalCols(), 'rows' => $this->invTotals($rows)];
    }

    /** Inventory Adjustment Journal: + → Dr Inventory, Cr Cost of Sales · − → Dr Cost of Sales, Cr Inventory */
    private function invAdjust(int $by, array $fifo): array
    {
        $names = ChartsOfAccount::where('added_by', $by)->pluck('account_name', 'account_code');
        $out = [];
        foreach ($fifo as $f) {
            $it = $f['item'];
            foreach ($f['rows'] as $r) {
                if ($r['type'] !== 'adj') continue;
                $v = round(abs($r['value']), 2);
                $qty = round(abs($r['qty']), 2);
                $inv = ['date' => $r['date'], 'ref' => '', 'qty' => $qty, 'line' => (string) $it->description,
                    'account' => (string) ($it->inv_acct ?? ''), 'account_name' => (string) ($names[$it->inv_acct ?? ''] ?? '')];
                $cos = ['date' => $r['date'], 'ref' => '', 'qty' => $qty, 'line' => $r['reason'] ?: (string) $it->description,
                    'account' => (string) ($it->cos_acct ?? ''), 'account_name' => (string) ($names[$it->cos_acct ?? ''] ?? '')];
                $out[] = ['date' => $r['date'], 'rows' => $r['qty'] > 0
                    ? [['kind' => 'line', 'cells' => $inv + ['debit' => $v]], ['kind' => 'line', 'cells' => $cos + ['credit' => $v]]]
                    : [['kind' => 'line', 'cells' => $cos + ['debit' => $v]], ['kind' => 'line', 'cells' => $inv + ['credit' => $v]]]];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $rows = array_merge(...array_map(fn ($x) => $x['rows'], $out ?: [['rows' => []]]));
        return ['name' => 'Inventory Adjustment Journal', 'columns' => $this->invJournalCols(), 'rows' => $this->invTotals($rows)];
    }

    /* =====================================================================
       Excel: same look as the old reports (Arial 9, bold captions with thin lines,
       m/d/yy dates, #,##0.00 numbers, blank row then Total with SUBTOTAL, row 1 frozen)
       ===================================================================== */
    private function invWriteSheet($sh, array $s, string $report): void
    {
        $sh->setTitle(substr($s['name'], 0, 31));
        $cols = $s['columns'];
        $n = count($cols);
        $last = Coordinate::stringFromColumnIndex($n);
        $L = [];
        foreach ($cols as $i => $c) $L[$c[0]] = Coordinate::stringFromColumnIndex($i + 1);
        $num = '#,##0.00;\-#,##0.00;* ??';
        $sh->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);

        // captions
        foreach ($cols as $i => $c) {
            $cell = $L[$c[0]] . '1';
            $sh->setCellValueExplicit($cell, $c[1], DataType::TYPE_STRING);
            $sh->getStyle($cell)->getAlignment()->setHorizontal($c[2] === 'num' ? 'right' : 'left');
        }
        $sh->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sh->getStyle("A1:{$last}1")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $sh->getStyle("A1:{$last}1")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $r = 2;
        $first = 2;
        foreach ($s['rows'] as $row) {
            if ($row['kind'] === 'total') {
                $end = $r;                                  // blank row before the total (like the old reports)
                $r++;
                foreach ($cols as $c) {
                    $k = $c[0];
                    $cell = $L[$k] . $r;
                    if ($c[2] === 'num' && array_key_exists($k, $row['cells'])) {
                        $sh->setCellValue($cell, "=SUBTOTAL(9, {$L[$k]}{$first}:{$L[$k]}{$end})");
                    } elseif (isset($row['cells'][$k])) {
                        $sh->setCellValueExplicit($cell, (string) $row['cells'][$k], DataType::TYPE_STRING);
                    }
                }
                $sh->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
                $r++;
                continue;
            }
            foreach ($cols as $c) {
                $k = $c[0];
                $cell = $L[$k] . $r;
                $v = $row['cells'][$k] ?? null;
                // profitability: Gross Profit and % as formulas, like the old file
                if ($report === 'profitability' && $k === 'gp') { $sh->setCellValue($cell, "={$L['sales']}{$r}-{$L['cost']}{$r}"); continue; }
                if ($report === 'profitability' && $k === 'gp_pct') { $sh->setCellValue($cell, "=IF({$L['sales']}{$r}<>0, (({$L['sales']}{$r}-{$L['cost']}{$r})/{$L['sales']}{$r})*100, 0)"); continue; }
                if ($v === null || $v === '') {
                    if ($c[2] === 'text' && $row['kind'] === 'line' && in_array($k, ['ref', 'description'], true)) $sh->setCellValueExplicit($cell, '', DataType::TYPE_STRING);
                    continue;
                }
                if ($c[2] === 'date') $sh->setCellValue($cell, XlDate::PHPToExcel(new \DateTime($v)));
                elseif ($c[2] === 'num') $sh->setCellValue($cell, (float) $v);
                else $sh->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
            }
            $r++;
        }
        $lastRow = max(2, $r);

        // formats per column
        foreach ($cols as $c) {
            $rng = $L[$c[0]] . "2:" . $L[$c[0]] . $lastRow;
            if ($c[2] === 'num') {
                $sh->getStyle($rng)->getNumberFormat()->setFormatCode($num);
                $sh->getStyle($rng)->getAlignment()->setHorizontal('right');
            } elseif ($c[2] === 'date') {
                $sh->getStyle($rng)->getNumberFormat()->setFormatCode('m/d/yy');
                $sh->getStyle($rng)->getAlignment()->setHorizontal('left');
            } else {
                $sh->getStyle($rng)->getNumberFormat()->setFormatCode('@');
                $sh->getStyle($rng)->getAlignment()->setHorizontal('left');
            }
            $len = max(mb_strlen($c[1]) + 3, 9);
            $sh->getColumnDimension($L[$c[0]])->setWidth(min(32, max($len, $c[0] === 'description' || $c[0] === 'account_name' || $c[0] === 'line' ? 22 : 12)));
        }
        $sh->freezePane('A2');
        $sh->setSelectedCell('A1');
    }
}