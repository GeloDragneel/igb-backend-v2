<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/* =========================================================================
   PRINT  (old pages/Print/ReportPHP/merge.php — OpenTBS + ReportTemplate/*.xlsx)
   Extends ReportController so every print uses the SAME numbers as the report on screen.

   GET /api/print/vat-summary?filter_type=DM&from=2026-07&to=2026-09&account_code=0&branch=all&paid1=0&paid2=0
       → EBIR-{UserID}.xlsx   (old Print on 2550-M/Q Summary: print_ebir.php + merge.php + EBIR.xlsx)

   POST /api/print/income-tax-summary            { from, to, branch, rows: [{ k, c: [data1 … data16] }] }  → { token }
   GET  /api/print/income-tax-summary/{token}    → IncomeStatement-{Username}.xlsx
       (old Income Statement Print: rows of the table on screen → tbl2_income_statement_report → merge.php + IncomeStatement.xlsx;
        the rows are kept for 10 minutes in the cache instead of a table)

   GET /api/print/esub?type=P&from=2026-07&to=2026-09&format=xlsx      (type = P | S | 1601EQ_IT | 1601EQ_VT | 1601EQ_PE)
   GET /api/print/esub?type=1604E&year=2026&format=pdf
       → PurchasesSummary / SalesSummary / Report1601EQ / Report1604E -{UserID}.xlsx, or the same as PDF (shown inline)
         (old E-Sub Report Print: PurchaseSummary.php, Sales_summary.php, Report1601EQ.php, Report1604E.php)

   Templates: put your old ReportTemplate files in  resources/report-templates/  (e.g. EBIR.xlsx).
   If the template is there, it is filled like OpenTBS did (the row with [b.data1] … [b.data15] repeats per row).
   If not, a clean sheet with the same rows is made.
   ========================================================================= */
class PrintController extends ReportController
{
    /* GET /api/print/vat-summary */
    public function vatSummaryPrint(Request $request)
    {
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DM,DR'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['required', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
            'paid1'        => ['nullable', 'numeric'],     // "1ST MONTH" typed on the page
            'paid2'        => ['nullable', 'numeric'],     // "2ND MONTH" typed on the page
            'range'        => ['nullable', 'string', 'max:60'],
        ]);

        // same numbers as the screen
        $v = $this->vatSummary($request)->getData(true);      // ReportController::vatSummary – same numbers as the screen
        $months = $v['months'];
        $MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $mLabel = fn ($ym) => $MON[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);

        $paid1 = round((float) ($d['paid1'] ?? $v['less']['first']), 2);
        $paid2 = round((float) ($d['paid2'] ?? $v['less']['second']), 2);
        $vt2307 = round((float) $v['less']['vt2307'], 2);
        $payable = round((float) $v['due']['total'] - ($paid1 + $paid2 + $vt2307), 2);

        /* ---------- the rows (old tbl2_ebir_report: Tag, SummaryData, Data_1 … Data_13) ---------- */
        $rows = [];
        $kind = [];                                     // head | row | sub | grand | less
        $put = function (array $row, string $k = 'row') use (&$rows, &$kind) { $rows[] = $row; $kind[] = $k; };
        $line = fn (string $tag, string $label, array $r) => array_merge([$tag, $label], array_map(fn ($m) => (float) ($r['values'][$m] ?? 0), $months), [(float) $r['total']]);
        $blank = array_fill(0, count($months), '');

        $put(array_merge(['Tag', 'Summary Data'], array_map($mLabel, $months), ['Total']), 'head');
        foreach ([['Sales', 'sales'], ['Purchases', 'purchases'], ['Expenses', 'expenses']] as [$tag, $key]) {
            foreach ($v[$key]['rows'] as $r) $put($line($tag, $r['label'], $r));
            $put($line($tag, 'Total', $v[$key]['total']), 'sub');
        }
        $put($line('Total Purchases/Expenses', '', $v['purch_exp']), 'sub');
        $put($line('Net Total', '', $v['net']), 'grand');
        $put($line('Output VAT', '', $v['output']));
        $put($line('Input VAT', '', $v['input']));
        $put($line('VAT Due', '', $v['due']), 'sub');
        $put(array_merge(['Less : Tax Paid', '1ST MONTH'], $blank, [$paid1]), 'less');
        $put(array_merge(['', '2ND MONTH'], $blank, [$paid2]), 'less');
        $put(array_merge(['', '2307 (VT)'], $blank, [$vt2307]), 'less');
        $put(array_merge(['TOTAL VAT PAYABLE/EXCESS', ''], $blank, [$payable]), 'grand');

        $file = 'EBIR-' . ($this->addedByFor($request) ?? 'report') . '.xlsx';     // old: EBIR-{UserID}.xlsx
        $template = $this->template('EBIR.xlsx');

        $ss = is_file($template)
            ? $this->ebirFromTemplate($template, $rows)
            : $this->ebirSheet($rows, $kind, $v['company'] ?? [], $d['range'] ?? ($months ? $mLabel($months[0]) . ' - ' . $mLabel(end($months)) : ''));

        $tmp = tempnam(sys_get_temp_dir(), 'ebir');
        (new Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();

        return response()->download($tmp, $file, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** resources/report-templates/{name} (old ../ReportTemplate/{name}) */
    private function template(string $name): string
    {
        return resource_path('report-templates/' . basename($name));
    }

    /** old OpenTBS MergeBlock('b'): repeat the row that has [b.data1] … for every row */
    private function ebirFromTemplate(string $template, array $rows): Spreadsheet
    {
        $ss = IOFactory::load($template);
        $sh = $ss->getSheet(0);                                         // OPENTBS_SELECT_SHEET, 1

        // find the block row and which column holds data1 … data15
        $blockRow = null;
        $colOf = [];
        foreach ($sh->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (preg_match('/\[b\.data(\d+)[^\]]*\]/i', (string) $cell->getValue(), $m)) {
                    $blockRow ??= $row->getRowIndex();
                    if ($row->getRowIndex() === $blockRow) $colOf[(int) $m[1]] = $cell->getColumn();
                }
            }
            if ($blockRow) break;
        }
        if (!$blockRow) return $ss;                                    // no block in the template: leave as is

        if (count($rows) > 1) $sh->insertNewRowBefore($blockRow + 1, count($rows) - 1);   // copies the block row's style
        foreach ($rows as $i => $data) {
            $r = $blockRow + $i;
            foreach ($colOf as $n => $col) {
                $val = $data[$n - 1] ?? '';
                if (is_float($val) || is_int($val)) $sh->setCellValue("{$col}{$r}", $val);
                else $sh->setCellValueExplicit("{$col}{$r}", (string) $val, DataType::TYPE_STRING);
            }
        }
        return $ss;
    }

    /** no template: same layout, built here */
    private function ebirSheet(array $rows, array $kind, array $company, string $range): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('2550-MQ Summary');
        $nCols = max(array_map('count', $rows));
        $last = Coordinate::stringFromColumnIndex($nCols);

        $r = 1;
        foreach ([$company['name'] ?? '', $company['tin'] ?? '', $company['address'] ?? '', '2550-M/Q Summary', $range ? "From {$range}" : ''] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0 || $i === 3) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $firstData = null;
        foreach ($rows as $i => $data) {
            foreach ($data as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                else $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $rr = "A{$r}:{$last}{$r}";
            if ($kind[$i] === 'head') {
                $sh->getStyle($rr)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
                ]);
                $sh->getStyle("C{$r}:{$last}{$r}")->getAlignment()->setHorizontal('right');
                $firstData = $r + 1;
            } elseif ($kind[$i] === 'sub') {
                $sh->getStyle($rr)->getFont()->setBold(true);
                $sh->getStyle($rr)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            } elseif ($kind[$i] === 'grand') {
                $sh->getStyle($rr)->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
                ]);
            } elseif ($kind[$i] === 'less') {
                $sh->getStyle("B{$r}")->getAlignment()->setHorizontal('right');
            }
            $r++;
        }
        $sh->getStyle("C{$firstData}:{$last}" . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        $sh->getStyle("A" . ($firstData - 1) . ":{$last}" . ($r - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        for ($c = 1; $c <= $nCols; $c++) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        $sh->freezePane('C' . $firstData);
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        return $ss;
    }

    /* ======================= Income Tax Summary print (old Income Statement → Print) ======================= */
    private const ITS_KINDS = ['head', 'sec', 'row', 'sum', 'bold', 'line', 'grand'];

    /* POST /api/print/income-tax-summary — the rows on screen (with adjustments), like old ajax/print_income_statement.php */
    public function incomeTaxSummaryPrepare(Request $request)
    {
        $d = $request->validate([
            'from'     => ['required', 'date_format:Y-m'],
            'to'       => ['required', 'date_format:Y-m'],
            'branch'   => ['nullable', 'string', 'max:20'],
            'range'    => ['nullable', 'string', 'max:60'],
            'rows'     => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.k' => ['nullable', 'in:' . implode(',', self::ITS_KINDS)],
            'rows.*.c' => ['required', 'array', 'max:16'],               // data1 … data16
        ]);

        $rows = array_map(fn ($r) => [
            'k' => $r['k'] ?? 'row',
            'c' => array_map(fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 250), array_values($r['c'])),
        ], $d['rows']);

        $token = Str::random(32);
        Cache::put($this->itsKey($request, $token), [
            'rows'   => $rows,
            'from'   => $d['from'],
            'to'     => $d['to'],
            'branch' => $d['branch'] ?? 'all',
            'range'  => $d['range'] ?? '',
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /* GET /api/print/income-tax-summary/{token} — old merge.php with IncomeStatement.xlsx (block b = data1 … data16) */
    public function incomeTaxSummaryPrint(Request $request, string $token)
    {
        $saved = Cache::pull($this->itsKey($request, $token));
        abort_if(!$saved, 404, 'This print has expired. Press Print again.');

        [, $account] = $this->journalScope($request, $saved['branch']);
        $h = $this->companyHeader($account) ?? [];
        $company = ['name' => $h['trade_name'] ?? '', 'tin' => $h['tin'] ?? '', 'address' => $h['address'] ?? ''];
        $range = $saved['range'] ?: $this->esPeriodText($saved['from'], $saved['to']);

        $template = $this->template('IncomeStatement.xlsx');
        if (is_file($template)) {
            $master = [
                'ClientName' => $company['name'], 'TradeName' => $company['name'], 'TIN' => $company['tin'],
                'Address' => $company['address'], 'MainBranch' => strtoupper((string) ($account->branch_type ?? '')),
                'RDateRange' => $range, 'Date' => $range, 'LastDate' => $range,
            ];
            $ss = $this->fillTemplate($template, ['m' => $master], array_column($saved['rows'], 'c'));
        } else {
            $ss = $this->itsSheet($saved['rows'], $company, $range);
        }

        $user = $request->user();
        $who = $user?->username ?? $user?->user_name ?? $this->addedByFor($request) ?? 'report';
        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "IncomeStatement-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function itsKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:its:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }

    /** no template: the statement as on screen */
    private function itsSheet(array $rows, array $company, string $range): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Income Tax Summary');
        $nCols = max(array_map(fn ($r) => count($r['c']), $rows));
        $last = Coordinate::stringFromColumnIndex($nCols);

        $r = 1;
        foreach ([$company['name'], $company['tin'] ? 'TIN : ' . $company['tin'] : '', 'Income Tax Summary', $range ? 'Date : ' . $range : ''] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0 || $i === 2) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $first = $r;
        foreach ($rows as $row) {
            foreach ($row['c'] as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                else $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $rr = "A{$r}:{$last}{$r}";
            switch ($row['k']) {
                case 'head':
                    $sh->getStyle($rr)->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
                    ]);
                    $sh->getStyle("B{$r}:{$last}{$r}")->getAlignment()->setHorizontal('right');
                    break;
                case 'sec':
                    $sh->mergeCells($rr);
                    $sh->getStyle($rr)->applyFromArray([
                        'font' => ['bold' => true, 'italic' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F5F9']],
                    ]);
                    break;
                case 'sum':
                case 'bold':
                    $sh->getStyle($rr)->getFont()->setBold(true);
                    $sh->getStyle($rr)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                    break;
                case 'grand':
                    $sh->getStyle($rr)->applyFromArray([
                        'font'    => ['bold' => true],
                        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
                        'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
                    ]);
                    break;
                default:
                    $sh->getStyle("A{$r}")->getFont()->setItalic(true);
            }
            $r++;
        }
        $end = $r - 1;
        $sh->getStyle("B{$first}:{$last}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        $sh->getStyle("B{$first}:{$last}{$end}")->getAlignment()->setHorizontal('right');
        $sh->getStyle("A{$first}:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sh->getColumnDimension('A')->setWidth(46);
        for ($c = 2; $c <= $nCols; $c++) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(15);
        $sh->freezePane('B' . ($first + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($first, $first);
        return $ss;
    }

    /* ======================= E-Sub Report print (old E_Sub_Report → File Type Excel / PDF) ======================= */
    /* GET /api/print/esub */
    public function esubPrint(Request $request)
    {
        $d = $request->validate([
            'type'   => ['required', 'in:P,S,1601EQ_IT,1601EQ_VT,1601EQ_PE,1604E'],
            'format' => ['nullable', 'in:xlsx,pdf'],
        ]);
        $format = $d['format'] ?? 'xlsx';

        // same lines and totals as the screen (ReportController::esubReport validates from/to/year)
        $v = $this->esubReport($request)->getData(true);
        $type = $d['type'];
        $isVat = in_array($type, ['P', 'S'], true);
        $is1604 = $type === '1604E';

        /* ---------- old blocks: a = captions, b = lines, m = master, n = footer ---------- */
        if ($isVat) {
            $caps = ['#', 'TIN', 'Registered Name', 'Street and Barangay', 'City/Municipality/Province/Country',
                'PHP Amount', 'Net of VAT', $type === 'S' ? 'Output VAT' : 'Input VAT', 'VAT Exempt', 'Zero Rated'];
            $keys = ['tin', 'name', 'address', 'city', 'php', 'net', 'vat', 'exempt', 'zero'];
            $sumKeys = ['php', 'net', 'vat', 'exempt', 'zero'];
        } else {
            $caps = ['#', 'TIN', 'Branch Code', 'Registered Name', 'ATC', 'Amount', 'Tax Rate', 'Tax Withheld'];
            $keys = ['tin', 'branch', 'name', 'atc', 'base', 'rate', 'tax'];
            $sumKeys = ['base', 'tax'];
        }
        $money = array_merge($sumKeys, ['rate']);

        $data = [];
        foreach ($v['rows'] as $i => $r) {
            $line = [$i + 1];
            foreach ($keys as $k) {
                $val = $r[$k] ?? '';
                $line[] = in_array($k, $money, true) ? (float) $val : (string) $val;
            }
            $data[] = $line;
        }

        $footer = array_map(fn ($k) => (float) ($v['totals'][$k] ?? 0), $sumKeys);
        $footer[] = now('Asia/Manila')->format('l, F j, Y');                  // old $formattedDate

        $company = $v['company'] ?? [];
        $tin = (string) ($company['tin'] ?? '');
        $master = [
            'ClientName' => (string) ($company['name'] ?? ''),
            'TIN'        => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{3,5})?$/', '$1-$2-$3-$4', $tin) ?: $tin,
            'MainBranch' => (int) substr($tin, 9) === 0 ? 'MAIN' : 'BRANCH',
            'Address'    => (string) ($company['address'] ?? ''),
            'LastDate'   => $is1604 ? (string) $request->input('year') : $this->esPeriodText($request->input('from'), $request->input('to') ?: $request->input('from')),
            'Title'      => (string) ($v['title'] ?? ''),
        ];

        $names = ['P' => 'PurchasesSummary', 'S' => 'SalesSummary', '1604E' => 'Report1604E'];
        $base = $names[$type] ?? 'Report1601EQ';
        $templates = ['P' => 'PurchasesSummary.xlsx', 'S' => 'SalesSummary.xlsx', '1604E' => 'Report1604E.xlsx'];
        $template = $this->template($templates[$type] ?? 'Report1601EQ2.xlsx');

        $blocks = [
            'a' => array_combine(array_map(fn ($n) => 'cap' . $n, range(1, count($caps))), $caps),
            'm' => $master,
            'n' => array_combine(array_map(fn ($n) => 'data' . $n, range(1, count($footer))), $footer),
        ];
        $ss = is_file($template)
            ? $this->fillTemplate($template, $blocks, $data)
            : $this->esubSheet($caps, $data, $footer, $master, count($sumKeys), $isVat);

        $who = $this->addedByFor($request) ?? 'report';

        if ($format === 'pdf') {
            $pdf = $this->toPdf($ss);                                        // stops with a clear message if it can't
            return response()->download($pdf, "{$base}-{$who}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "{$base}-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** "July 2026" or "July to September 2026" (old $LastDate) */
    private function esPeriodText(?string $from, ?string $to): string
    {
        if (!$from) return '';
        $f = \DateTime::createFromFormat('!Y-m', $from);
        $t = \DateTime::createFromFormat('!Y-m', $to ?: $from) ?: $f;
        if (!$f) return $from;
        if ($t < $f) [$f, $t] = [$t, $f];
        if ($f == $t) return $f->format('F Y');
        return $f->format('Y') === $t->format('Y')
            ? $f->format('F') . ' to ' . $t->format('F Y')
            : $f->format('F Y') . ' to ' . $t->format('F Y');
    }

    /**
     * Old OpenTBS merge for templates with one-row blocks (a, m, n → [a.cap1], [m.ClientName], [n.data1] …)
     * and one repeating block (b → the row with [b.data1] … repeats per line).
     */
    private function fillTemplate(string $template, array $singles, array $rows, string $repeat = 'b'): Spreadsheet
    {
        $ss = IOFactory::load($template);
        $sh = $ss->getSheet(0);                                         // OPENTBS_SELECT_SHEET, 1

        $put = function ($coord, $val) use ($sh) {
            if (is_float($val) || is_int($val)) $sh->setCellValue($coord, $val);
            else $sh->setCellValueExplicit($coord, (string) $val, DataType::TYPE_STRING);
        };

        // 1) single-row blocks, anywhere on the sheet (before rows are inserted)
        $blockRow = null;
        $colOf = [];
        foreach ($sh->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $text = $cell->getValue();
                if (!is_string($text) || !str_contains($text, '[')) continue;

                if (preg_match('/\[' . $repeat . '\.data(\d+)[^\]]*\]/i', $text, $m)) {
                    $blockRow ??= $row->getRowIndex();
                    if ($row->getRowIndex() === $blockRow) $colOf[(int) $m[1]] = $cell->getColumn();
                    continue;
                }

                // a whole-cell field keeps its number type; fields inside text are replaced as text
                if (preg_match('/^\s*\[(\w)\.(\w+)[^\]]*\]\s*$/', $text, $m) && isset($singles[$m[1]])) {
                    $put($cell->getCoordinate(), $singles[$m[1]][$m[2]] ?? '');
                    continue;
                }
                $new = preg_replace_callback('/\[(\w)\.(\w+)[^\]]*\]/', function ($m) use ($singles) {
                    if (!isset($singles[$m[1]])) return $m[0];
                    $val = $singles[$m[1]][$m[2]] ?? '';
                    return is_float($val) ? number_format($val, 2) : (string) $val;
                }, $text);
                if ($new !== $text) $put($cell->getCoordinate(), $new);
            }
        }
        if (!$blockRow) return $ss;

        // 2) the repeating block (rows below, like the footer, move down)
        if (count($rows) > 1) $sh->insertNewRowBefore($blockRow + 1, count($rows) - 1);
        if (!$rows) {
            foreach ($colOf as $col) $sh->setCellValue("{$col}{$blockRow}", null);
            return $ss;
        }
        foreach ($rows as $i => $data) {
            foreach ($colOf as $n => $col) $put($col . ($blockRow + $i), $data[$n - 1] ?? '');
        }
        return $ss;
    }

    /** no template: same layout as the old E-Sub sheets */
    private function esubSheet(array $caps, array $rows, array $footer, array $master, int $nSum, bool $isVat): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle(substr(preg_replace('/[\\\\\/?*\[\]:]/', '-', $master['Title'] ?: 'E-Sub'), 0, 31));
        $nCols = count($caps);
        $last = Coordinate::stringFromColumnIndex($nCols);
        $firstMoney = $nCols - ($isVat ? $nSum : 3) + 1;               // VAT: last 5 · 1601EQ/1604E: Amount, Rate, Tax

        $r = 1;
        foreach ([$master['ClientName'], $master['TIN'] . '  ·  ' . $master['MainBranch'], $master['Address'], $master['Title'], $master['LastDate']] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0 || $i === 3) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $head = $r;
        foreach ($caps as $c => $t) $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
        $r++;

        if (!$rows) {
            $sh->setCellValue("A{$r}", 'No transactions for this period.');
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $r++;
        }
        foreach ($rows as $data) {
            foreach ($data as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                else $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $r++;
        }

        // Total row: VAT → last 5 columns · 1601EQ/1604E → Amount and Tax Withheld
        $sh->setCellValue("B{$r}", 'Total');
        $sumCols = $isVat ? range($nCols - $nSum + 1, $nCols) : [$nCols - 2, $nCols];
        foreach ($sumCols as $i => $c) $sh->setCellValue(Coordinate::stringFromColumnIndex($c) . $r, $footer[$i]);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
        ]);

        $fm = Coordinate::stringFromColumnIndex($firstMoney);
        $sh->getStyle("{$fm}" . ($head + 1) . ":{$last}{$r}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        if (!$isVat) $sh->getStyle(Coordinate::stringFromColumnIndex($nCols - 1) . ($head + 1) . ':' . Coordinate::stringFromColumnIndex($nCols - 1) . $r)->getNumberFormat()->setFormatCode('0.00"%"');
        $sh->getStyle("{$fm}{$head}:{$last}{$r}")->getAlignment()->setHorizontal('right');
        $sh->getStyle("A{$head}:{$last}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        $r += 2;
        $sh->setCellValueExplicit("A{$r}", 'Printed on ' . end($footer), DataType::TYPE_STRING);
        $sh->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9);

        $sh->getColumnDimension('A')->setWidth(6);
        for ($c = 2; $c <= $nCols; $c++) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        $sh->freezePane('A' . ($head + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($head, $head);
        return $ss;
    }

    /** empty temp file name with an extension */
    private function tempFile(string $ext): string
    {
        $dir = storage_path('app/print-tmp');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . DIRECTORY_SEPARATOR . 'p' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    /**
     * Spreadsheet → PDF.
     * 1) LibreOffice (old: soffice --headless --convert-to pdf)
     * 2) if LibreOffice isn't there / fails: PhpSpreadsheet's PDF writer (mpdf, dompdf or tcpdf, if installed)
     * Stops with a readable 422 message when neither works.
     */
    /**
     * PDF layout for every report (called at the start of toPdf):
     *   · keeps portrait / landscape as the report has it
 *   · margins almost none (0.15" sides, 0.2" top/bottom, no header/footer space)
     *   · fonts under 10pt → 10pt (easier to read)
     *   · columns wider than their longest value are made narrower (only narrower, never wider)
     *   · then the page is zoomed so the columns fill the whole paper width
     *     (Excel "fit to width" only makes things smaller – this also makes small reports bigger)
     */
    private function pdfLayout(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh): void
    {
        $ps = $sh->getPageSetup();
        // keep the report's own orientation (landscape when it has none)
        $portrait = $ps->getOrientation() === PageSetup::ORIENTATION_PORTRAIT;
        if (!$portrait) $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sh->getPageMargins()->setLeft(0.15)->setRight(0.15)->setTop(0.2)->setBottom(0.2)->setHeader(0)->setFooter(0);
        $ps->setHorizontalCentered(true);

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sh->getHighestDataColumn());
        $lastRow = min($sh->getHighestDataRow(), 4000);                         // big reports: look at the first 4000 rows
        if ($lastCol < 1 || $lastRow < 1) return;

        // cells inside merged ranges (titles) don't decide a column's width
        $merged = [];
        foreach ($sh->getMergeCells() as $range) {
            foreach (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::extractAllCellReferencesInRange($range) as $ref) $merged[$ref] = true;
        }

        $need = array_fill(1, $lastCol, 0.0);                                    // longest value per column, in "characters at 11pt"
        for ($r = 1; $r <= $lastRow; $r++) {
            for ($c = 1; $c <= $lastCol; $c++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $ref = $col . $r;
                if (!$sh->cellExists($ref)) continue;
                $cell = $sh->getCell($ref);
                $font = $sh->getStyle($ref)->getFont();
                $size = (float) ($font->getSize() ?: 11);
                if ($size < 10) { $font->setSize(10); $size = 10; }              // bigger text
                if (isset($merged[$ref])) continue;
                try { $text = (string) $cell->getFormattedValue(); } catch (\Throwable) { $text = (string) $cell->getValue(); }
                if ($text === '') continue;
                $len = max(array_map('mb_strlen', explode("\n", $text)));
                $need[$c] = max($need[$c], $len * ($size / 11) * ($font->getBold() ? 1.08 : 1));
            }
        }

        // narrower columns where there is empty space, then the total width
        $default = $sh->getDefaultColumnDimension()->getWidth();
        $default = $default > 0 ? $default : 8.43;
        $total = 0.0;
        for ($c = 1; $c <= $lastCol; $c++) {
            $dim = $sh->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c));
            if (!$dim->getVisible()) continue;
            $w = $dim->getWidth();
            $w = $w > 0 ? $w : $default;
            if ($need[$c] > 0) {
                $fit = max(6, $need[$c] * 1.1 + 2);
                if ($fit < $w) { $w = $fit; $dim->setWidth($w); }
            }
            $total += $w;
        }
        if ($total <= 0) return;

        // zoom so the columns fill the paper width (1 width unit ≈ 5.25pt, + a little cell padding)
        // paper [long side, short side] in inches – letter when not set
        [$long, $short] = [
            PageSetup::PAPERSIZE_A4 => [11.69, 8.27], PageSetup::PAPERSIZE_LEGAL => [14.0, 8.5], PageSetup::PAPERSIZE_FOLIO => [13.0, 8.5], PageSetup::PAPERSIZE_A3 => [16.54, 11.69],
        ][$ps->getPaperSize()] ?? [11.0, 8.5];
        $paper = $portrait ? $short : $long;
        $printable = ($paper - 0.3) * 72;
        $widthPts = $total * 5.25 + $lastCol * 3;
        $scale = (int) floor($printable / $widthPts * 100 * 0.97);
        $ps->setFitToWidth(0)->setFitToHeight(0)->setFitToPage(false);
        $ps->setScale(max(10, min(160, $scale)), false);
    }

    private function toPdf(Spreadsheet $ss): string
    {
        @set_time_limit(300);
        // bigger text, (almost) no margins, columns fill the page width
        foreach ($ss->getAllSheets() as $sh) $this->pdfLayout($sh);

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $outDir = dirname($xlsx);
        $pdf = $outDir . DIRECTORY_SEPARATOR . pathinfo($xlsx, PATHINFO_FILENAME) . '.pdf';
        $tried = [];

        // ---------- 1) LibreOffice ----------
        $win = PHP_OS_FAMILY === 'Windows';
        $candidates = array_filter([
            config('services.libreoffice.path'),
            env('LIBREOFFICE_PATH'),
            ...($win ? [
                'C:\\Program Files\\LibreOffice\\program\\soffice.com',        // .com waits until the file is done
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            ] : ['/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice',
                 '/Applications/LibreOffice.app/Contents/MacOS/soffice']),
        ]);

        // one fixed profile (fast after the first run); a lock so two prints don't use it together
        $profile = storage_path('app/libreoffice-profile');
        $profileUrl = 'file:///' . ltrim(str_replace('\\', '/', $profile), '/');
        $lock = fopen(storage_path('app/libreoffice.lock'), 'c');

        foreach (array_unique($candidates) as $soffice) {
            if (!is_file($soffice)) { $tried[] = "{$soffice}: not found"; continue; }
            if ($win && str_ends_with(strtolower($soffice), '.exe') && is_file(substr($soffice, 0, -4) . '.com')) {
                $soffice = substr($soffice, 0, -4) . '.com';
            }

            $cmd = [$soffice, "-env:UserInstallation={$profileUrl}", '--headless', '--norestore', '--nologo',
                '--convert-to', 'pdf', '--outdir', $outDir, $xlsx];
            if ($lock) flock($lock, LOCK_EX);
            [$code, $out] = $this->run($cmd, 180);
            for ($i = 0; $i < 40 && !is_file($pdf); $i++) usleep(250000);   // the launcher can return a bit early
            if ($lock) flock($lock, LOCK_UN);

            if (is_file($pdf) && filesize($pdf) > 0) {
                if ($lock) fclose($lock);
                @unlink($xlsx);
                $ss->disconnectWorksheets();
                return $pdf;
            }
            $tried[] = "{$soffice}: exit {$code} " . trim(mb_substr($out, 0, 300));
        }
        if ($lock) fclose($lock);
        @unlink($xlsx);

        // ---------- 2) PhpSpreadsheet PDF writer ----------
        $writers = [
            'Mpdf\Mpdf'     => \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf::class,
            'Dompdf\Dompdf' => \PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf::class,
            'TCPDF'         => \PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf::class,
        ];
        foreach ($writers as $lib => $writer) {
            if (!class_exists($lib)) continue;
            try {
                (new $writer($ss))->writeAllSheets()->save($pdf);
                if (is_file($pdf) && filesize($pdf) > 0) { $ss->disconnectWorksheets(); return $pdf; }
            } catch (\Throwable $e) {
                $tried[] = class_basename($writer) . ': ' . $e->getMessage();
            }
        }
        $ss->disconnectWorksheets();

        logger()->warning('E-Sub PDF failed', ['tried' => $tried]);
        abort(response()->json([
            'message' => "Couldn't make the PDF. Install LibreOffice (or run: composer require mpdf/mpdf), "
                . 'or set LIBREOFFICE_PATH in .env to your soffice.exe.',
            'tried'   => $tried,
        ], 422));
    }

    /** run a command without a shell (no quoting problems on Windows); returns [exit code, output] */
    private function run(array $cmd, int $timeout): array
    {
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) return [-1, 'could not start'];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $end = time() + $timeout;
        while (true) {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) break;
            if (time() > $end) { proc_terminate($p); $out .= ' (timed out)'; break; }
            usleep(100000);
        }
        $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return [isset($st['exitcode']) && $st['exitcode'] >= 0 ? $st['exitcode'] : $code, $out];
    }
    /* ======================= Journals print (old Sales / Purchase Receipts / Cash Disbursement / General Journal → Print) ======================= */
    /* GET /api/print/journal?tab=sales&filter_type=DM&from=2026-07&to=2026-09&branch=current&format=xlsx|pdf */
    public function journalPrint(Request $request)
    {
        $d = $request->validate([
            'tab'         => ['required', 'in:sales,purchases,disbursement,general'],
            'filter_type' => ['required', 'in:DM,DR,DE'],
            'from'        => ['required', 'string', 'max:10'],
            'to'          => ['nullable', 'string', 'max:10'],
            'branch'      => ['nullable', 'string', 'max:20'],
            'format'      => ['nullable', 'in:xlsx,pdf'],
        ]);
        $format = $d['format'] ?? 'xlsx';

        // tab → [screen method, title, old template, old file name]
        $map = [
            'sales'        => ['salesJournal',     'Sales Journal',             'CashReceiptsJournal.xlsx',     'Cash_Receipt_Journal'],
            'purchases'    => ['purchasesJournal', 'Purchase Receipts Journal', 'PurchaseReceiptsJournal.xlsx', 'Purchase_Receipts_Journal'],
            'disbursement' => ['expensesJournal',  'Cash Disbursement Journal', 'CashDisbursementJournal.xlsx', 'Cash_Disbursement_Journal'],
            'general'      => ['generalJournal',   'General Journal',           'GeneralJournal.xlsx',          'General_Journal'],
        ];
        [$method, $title, $templateName, $fileBase] = $map[$d['tab']];

        // same rows as the screen
        $v = $this->{$method}($request)->getData(true);
        $rows = $v['rows'] ?? [];

        // old ReportData: one line per row, a blank line between invoices, totals at the end
        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = [
                (string) ($r['date'] ?? ''), (string) ($r['account_id'] ?? ''), (string) ($r['tran'] ?? ''),
                (string) ($r['name'] ?? ''), (string) ($r['description'] ?? ''),
                (float) ($r['debit'] ?? 0) ?: '', (float) ($r['credit'] ?? 0) ?: '',
            ];
            $next = $rows[$i + 1] ?? null;
            if (!$next || ($next['invoice_no'] ?? null) !== ($r['invoice_no'] ?? null)) $data[] = ['', '', '', '', '', '', ''];
        }
        $totDebit = round((float) ($v['totals']['debit'] ?? array_sum(array_column($rows, 'debit'))), 2);
        $totCredit = round((float) ($v['totals']['credit'] ?? array_sum(array_column($rows, 'credit'))), 2);
        $data[] = ['', '', '', '', '', $totDebit, $totCredit];

        $to = $d['to'] ?: $d['from'];
        $period = $d['filter_type'] === 'DE' ? $d['from'] : "{$d['from']} - {$to}";
        $company = $v['company'] ?? [];
        [, $account] = $this->journalScope($request, $d['branch'] ?? 'current');

        $caps = ['Date', 'Account ID', 'Tran', 'Name', 'Line Description', 'Debit Amnt', 'Credit Amnt'];
        $master = [
            'TradeName'        => (string) ($company['trade_name'] ?? ''),
            'TIN'              => (string) ($company['tin'] ?? ''),
            'MainBranch'       => strtoupper((string) ($account->branch_type ?? '')),
            'Address'          => (string) ($company['address'] ?? ''),
            'AccountingPeriod' => $period,
            'Title'            => $title,
        ];

        $template = $this->template($templateName);
        $ss = is_file($template)
            ? $this->fillTemplate($template, [
                'a' => array_combine(array_map(fn ($n) => 'cap' . $n, range(1, 7)), $caps),
                'm' => $master,
            ], $data)
            : $this->journalSheet($caps, $data, $master);

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';

        if ($format === 'pdf') {
            $pdf = $this->toPdf($ss);
            return response()->download($pdf, "{$fileBase}-{$who}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "{$fileBase}-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** no template: the journal as on screen */
    private function journalSheet(array $caps, array $data, array $master): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle(substr($master['Title'], 0, 31));

        $r = 1;
        $tin = $master['TIN'] . ($master['MainBranch'] ? '  ·  ' . $master['MainBranch'] : '');
        foreach ([$master['TradeName'], $tin, $master['Address'], $master['Title'], 'For the Period From : ' . $master['AccountingPeriod']] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:G{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0 || $i === 3) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $head = $r;
        foreach ($caps as $c => $t) $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        $sh->getStyle("A{$r}:G{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
        ]);
        $sh->getStyle("F{$r}:G{$r}")->getAlignment()->setHorizontal('right');
        $r++;

        if (count($data) === 1) {                                     // only the totals line
            $sh->setCellValue("A{$r}", 'No entries for this period.');
            $sh->mergeCells("A{$r}:G{$r}");
            $r++;
        }
        foreach ($data as $line) {
            foreach ($line as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $r++;
        }
        $end = $r - 1;

        // totals line: double underline like the old page
        $sh->getStyle("F{$end}:G{$end}")->applyFromArray([
            'font'    => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
        ]);
        $sh->getStyle("F" . ($head + 1) . ":G{$end}")->getNumberFormat()->setFormatCode('#,##0.0000');
        $sh->getStyle("F{$end}:G{$end}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sh->getStyle("F{$head}:G{$end}")->getAlignment()->setHorizontal('right');

        foreach (['A' => 12, 'B' => 14, 'C' => 16, 'D' => 34, 'E' => 40, 'F' => 16, 'G' => 16] as $col => $w) {
            $sh->getColumnDimension($col)->setWidth($w);
        }
        $sh->freezePane('A' . ($head + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($head, $head);
        return $ss;
    }
    /* ======================= Books & Ledger → Reports print (old Sales / Purchase / Expense Report → EXCEL ▾) ======================= */
    // kind → [title, Customer/Vendor caption, VAT caption, old ReportType (file name)]
    private const BOOK_REPORTS = [
        'sales'    => ['Sales Report',    'Customer', 'Output VAT', 'ReportSalesJournal'],
        'purchase' => ['Purchase Report', 'Vendor',   'Input VAT',  'ReportPurchaseJournal'],
        'expense'  => ['Expense Report',  'Vendor',   'Input VAT',  'ReportExpenseJournal'],
    ];

    /* POST /api/print/book-report — the rows on screen (after the column filters) → { token } */
    public function bookReportPrepare(Request $request)
    {
        $d = $request->validate([
            'kind'        => ['required', 'in:sales,purchase,expense'],
            'filter_type' => ['nullable', 'in:DM,DR,DE'],
            'from'        => ['required', 'string', 'max:10'],
            'to'          => ['nullable', 'string', 'max:10'],
            'branch'      => ['nullable', 'string', 'max:20'],
            'rows'        => ['present', 'array', 'max:20000'],
            'rows.*'      => ['array', 'max:15'],                  // data1 … data15 (old merge.php columns)
        ]);

        $rows = array_map(fn ($r) => array_map(
            fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 250),
            array_values($r)
        ), $d['rows']);

        $token = Str::random(32);
        Cache::put($this->bookReportKey($request, $token), [
            'kind'   => $d['kind'],
            'from'   => $d['from'],
            'to'     => $d['to'] ?: $d['from'],
            'branch' => $d['branch'] ?? 'current',
            'rows'   => $rows,
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /* GET /api/print/book-report/{token}?format=xlsx|pdf — old merge.php with SalesPurchase_Journal.xlsx */
    public function bookReportPrint(Request $request, string $token)
    {
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';
        $saved = Cache::pull($this->bookReportKey($request, $token));
        abort_if(!$saved, 404, 'This print has expired. Press Print again.');

        [$title, $party, $vatCap, $reportType] = self::BOOK_REPORTS[$saved['kind']];
        $caps = ['#', 'Date', 'TIN', $party, 'Document', 'Account Title', 'Amount', 'Currency', 'Ex Rate',
            'PHP', 'Net of VAT', $vatCap, 'VAT Exempt', 'Zero Rated', 'Non VAT'];

        // numbered lines + Total line (old PDF)
        $data = [];
        $sum = array_fill(0, 15, 0.0);
        $sumCols = [6, 9, 10, 11, 12, 13, 14];                  // Amount, PHP, Net, VAT, Exempt, Zero, Non VAT
        foreach ($saved['rows'] as $i => $r) {
            $line = array_pad(array_slice($r, 0, 15), 15, '');
            $line[0] = $i + 1;
            foreach ($sumCols as $c) $sum[$c] += (float) ($line[$c] ?: 0);
            $data[] = $line;
        }
        $total = array_fill(0, 15, '');
        $total[5] = 'Total';
        foreach ($sumCols as $c) $total[$c] = round($sum[$c], 2);
        $data[] = $total;

        [, $account] = $this->journalScope($request, $saved['branch']);
        $h = $this->companyHeader($account) ?? [];
        $master = [
            'ClientName' => (string) ($h['trade_name'] ?? ''),
            'TIN'        => (string) ($h['tin'] ?? ''),
            'Street'     => (string) ($h['address'] ?? ''),
            'ReportType' => $title,
            'DateFrom'   => $saved['from'],
            'DateTo'     => $saved['to'],
        ];

        $template = $this->template('SalesPurchase_Journal.xlsx');
        $ss = is_file($template)
            ? $this->fillTemplate($template, [
                'a' => array_combine(array_map(fn ($n) => 'cap' . $n, range(1, 15)), $caps),
                'm' => $master,
            ], $data)
            : $this->bookReportSheet($caps, $data, $master);

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';

        if ($format === 'pdf') {
            $pdf = $this->toPdf($ss);
            return response()->download($pdf, "{$reportType}-{$who}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "{$reportType}-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function bookReportKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:book:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }

    /** no template: the report as on screen */
    private function bookReportSheet(array $caps, array $data, array $master): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle($master['ReportType']);
        $last = 'O';

        $r = 1;
        foreach ([$master['ClientName'], $master['TIN'], $master['Street'], $master['ReportType'], "From : {$master['DateFrom']} To {$master['DateTo']}"] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0 || $i === 3) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $head = $r;
        foreach ($caps as $c => $t) $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
        $r++;

        if (count($data) === 1) {                                     // only the Total line
            $sh->setCellValue("A{$r}", 'No records for this period.');
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $r++;
        }
        foreach ($data as $line) {
            foreach ($line as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $r++;
        }
        $end = $r - 1;

        $sh->getStyle("A{$end}:{$last}{$end}")->applyFromArray([
            'font'    => ['bold' => true],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
        ]);
        $sh->getStyle("G" . ($head + 1) . ":{$last}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        $sh->getStyle("G{$head}:{$last}{$end}")->getAlignment()->setHorizontal('right');
        $sh->getStyle("H{$head}:H{$end}")->getAlignment()->setHorizontal('center');
        $sh->getStyle("A{$head}:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

        foreach (['A' => 6, 'B' => 12, 'C' => 15, 'D' => 30, 'E' => 16, 'F' => 26, 'H' => 9, 'I' => 9] as $col => $w) {
            $sh->getColumnDimension($col)->setWidth($w);
        }
        foreach (['G', 'J', 'K', 'L', 'M', 'N', 'O'] as $col) $sh->getColumnDimension($col)->setWidth(14);
        $sh->freezePane('A' . ($head + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($head, $head);
        return $ss;
    }
    /* ======================= Books & Ledger → Ledgers print =======================
       General Ledger: Print ▾ Download XLSX / PDF   ·   Summary / Customer / Vendor Ledger: Print (XLSX)
       Summary Ledger template SummaryLedger.xlsx: [b.data1] … [b.data14], [m.Period] = "FOR THE PERIOD 01/01/2026 - 12/31/2026" */
    // kind → [title, old template, file name, first money column (0-based)]
    private const LEDGER_PRINTS = [
        'general'  => ['General Ledger',  'GeneralLedger.xlsx',  'GeneralLedger',  6],
        'customer' => ['Customer Ledger', 'GeneralLedger.xlsx',  'CustomerLedger', 6],   // old Customer / Vendor Ledger used GeneralLedger.xlsx too
        'vendor'   => ['Vendor Ledger',   'GeneralLedger.xlsx',  'VendorLedger',   6],
        'summary'  => ['Summary Ledger',  'SummaryLedger.xlsx',  'SummaryLedger',  1],
    ];
    private const LEDGER_KINDS = ['row', 'begin', 'change', 'ending', 'gap'];

    /* POST /api/print/ledger — the rows on screen (old ajax/print_general_ledger.php) → { token } */
    public function ledgerPrepare(Request $request)
    {
        $d = $request->validate([
            'kind'     => ['required', 'in:' . implode(',', array_keys(self::LEDGER_PRINTS))],
            'period'   => ['nullable', 'string', 'max:80'],
            'party'    => ['nullable', 'string', 'max:200'],
            'branch'   => ['nullable', 'string', 'max:20'],
            'rows'     => ['present', 'array', 'max:50000'],
            'rows.*.k' => ['nullable', 'in:' . implode(',', self::LEDGER_KINDS)],
            'rows.*.c' => ['present', 'array', 'max:14'],
        ]);

        $rows = array_map(fn ($r) => [
            'k' => $r['k'] ?? 'row',
            'c' => array_map(fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 250), array_values($r['c'])),
        ], $d['rows']);

        $token = Str::random(32);
        Cache::put($this->ledgerKey($request, $token), [
            'kind'   => $d['kind'],
            'period' => $d['period'] ?? '',
            'party'  => $d['party'] ?? '',
            'branch' => $d['branch'] ?? 'current',
            'rows'   => $rows,
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /* GET /api/print/ledger/{token}?format=xlsx|pdf — old merge.php with GeneralLedger.xlsx */
    public function ledgerPrint(Request $request, string $token)
    {
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';
        $saved = Cache::pull($this->ledgerKey($request, $token));
        abort_if(!$saved, 404, 'This print has expired. Press Print again.');

        [$title, $templateName, $fileBase, $moneyFrom] = self::LEDGER_PRINTS[$saved['kind']];
        $caps = $saved['kind'] === 'summary'
            ? ['Account Title', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'Total']
            : ['Account ID', 'Account Description', 'Date', 'Reference', 'Jrnl', 'Trans Description', 'Debit Amount', 'Credit Amount', 'Balance'];

        [, $account] = $this->journalScope($request, $saved['branch']);
        $h = $this->companyHeader($account) ?? [];
        $master = [
            'ClientName' => (string) ($h['trade_name'] ?? ''),
            'TradeName'  => (string) ($h['trade_name'] ?? ''),
            'TIN'        => (string) ($h['tin'] ?? ''),
            'Address'    => (string) ($h['address'] ?? ''),
            'MainBranch' => strtoupper((string) ($account->branch_type ?? '')),
            'Title'      => $title,
            'Period'     => $saved['period'],
            'Party'      => $saved['party'],
        ];

        $template = $this->template($templateName);
        $ss = is_file($template)
            ? $this->fillTemplate($template, [
                'a' => array_combine(array_map(fn ($n) => 'cap' . $n, range(1, count($caps))), $caps),
                'm' => $master,
            ], array_column($saved['rows'], 'c'))
            : $this->ledgerSheet($caps, $saved['rows'], $master, $moneyFrom);

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';

        if ($format === 'pdf') {
            $pdf = $this->toPdf($ss);
            return response()->download($pdf, "{$fileBase}-{$who}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "{$fileBase}-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function ledgerKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:ledger:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }

    /** no template: the ledger as on screen */
    private function ledgerSheet(array $caps, array $rows, array $master, int $moneyFrom): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle($master['Title']);
        $n = count($caps);
        $last = Coordinate::stringFromColumnIndex($n);
        $fm = Coordinate::stringFromColumnIndex($moneyFrom + 1);

        $r = 1;
        $lines = array_filter([$master['TradeName'], $master['TIN'], $master['Title'],
            $master['Party'] ? (str_starts_with($master['Title'], 'Customer') ? 'Customer: ' : 'Vendor: ') . $master['Party'] : '',
            $master['Period']]);
        foreach (array_values($lines) as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0 || $t === $master['Title']) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $head = $r;
        foreach ($caps as $c => $t) $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
        ]);
        $sh->getStyle("{$fm}{$r}:{$last}{$r}")->getAlignment()->setHorizontal('right');
        $r++;

        if (!$rows) {
            $sh->setCellValue("A{$r}", 'No entries for this period.');
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $r++;
        }
        foreach ($rows as $row) {
            foreach ($row['c'] as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $rr = "A{$r}:{$last}{$r}";
            if ($row['k'] === 'begin') $sh->getStyle($rr)->getFont()->setItalic(true);
            if ($row['k'] === 'change') $sh->getStyle("{$fm}{$r}:{$last}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            if ($row['k'] === 'ending') {
                $sh->getStyle($rr)->getFont()->setBold(true);
                $sh->getStyle("{$last}{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
            }
            $r++;
        }
        $end = $r - 1;

        $sh->getStyle("{$fm}" . ($head + 1) . ":{$last}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        $sh->getStyle("{$fm}" . ($head + 1) . ":{$last}{$end}")->getAlignment()->setHorizontal('right');

        if ($moneyFrom === 1) {                                         // Summary Ledger
            $sh->getColumnDimension('A')->setWidth(38);
            for ($c = 2; $c <= $n; $c++) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(14);
            $sh->getStyle("{$last}" . ($head + 1) . ":{$last}{$end}")->getFont()->setBold(true);
            $sh->freezePane('B' . ($head + 1));
        } else {
            foreach (['A' => 11, 'B' => 30, 'C' => 12, 'D' => 18, 'E' => 7, 'F' => 40, 'G' => 15, 'H' => 15, 'I' => 15] as $col => $w) {
                $sh->getColumnDimension($col)->setWidth($w);
            }
            $sh->freezePane('A' . ($head + 1));
        }
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($head, $head);
        return $ss;
    }
/* ======================= Financial Reports → Consolidated Report (Detailed) print =======================
       old: table rows → tbl2_consolidated_report (Tag, Data_1 … Data_12) → merge.php + ConsolidatedReport.xlsx ([b.data1] … [b.data13])
       now: rows kept 10 minutes in the cache instead of a table */

    /* POST /api/print/consolidated — the table on screen → { token } */
    public function consolidatedPrepare(Request $request)
    {
        $d = $request->validate([
            'from'     => ['required', 'date_format:Y-m'],
            'to'       => ['required', 'date_format:Y-m'],
            'range'    => ['nullable', 'string', 'max:60'],
            'rows'     => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.k' => ['nullable', 'in:head,row,total'],
            'rows.*.c' => ['required', 'array', 'max:200'],
        ]);

        $rows = array_map(fn ($r) => [
            'k' => $r['k'] ?? 'row',
            'c' => array_map(fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 250), array_values($r['c'])),
        ], $d['rows']);

        $token = Str::random(32);
        Cache::put($this->consolidatedKey($request, $token), [
            'rows'  => $rows,
            'range' => $d['range'] ?: $this->esPeriodText($d['from'], $d['to']),
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /* GET /api/print/consolidated/{token} → Consolidated_Report-{Username}.xlsx */
    public function consolidatedPrint(Request $request, string $token)
    {
        $saved = Cache::pull($this->consolidatedKey($request, $token));
        abort_if(!$saved, 404, 'This print has expired. Press Print again.');

        $template = $this->template('ConsolidatedReport.xlsx');
        if (is_file($template)) {
            [, $account] = $this->journalScope($request, 'current');
            $h = $this->companyHeader($account) ?? [];
            $ss = $this->fillTemplate($template, ['m' => [
                'ClientName' => (string) ($h['trade_name'] ?? ''), 'TradeName' => 'Consolidated Report (Detailed)',
                'TIN' => (string) ($h['tin'] ?? ''), 'RDateRange' => $saved['range'],
            ]], array_column($saved['rows'], 'c'));
        } else {
            [, $account] = $this->journalScope($request, 'current');
            $h = $this->companyHeader($account) ?? [];
            $ss = $this->consolidatedSheet($saved['rows'], (string) ($h['tin'] ?? ''), $saved['range']);
        }

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';
        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "Consolidated_Report-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function consolidatedKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:cons:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }

    /** no template: the report as on screen */
    private function consolidatedSheet(array $rows, string $tin, string $range): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Consolidated Report');
        $n = max(2, max(array_map(fn ($r) => count($r['c']), $rows)));
        $last = Coordinate::stringFromColumnIndex($n);

        $r = 1;
        foreach (['Consolidated Report (Detailed)', 'TIN : ' . ($tin ?: '—'), 'Date : ' . $range] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
            $r++;
        }
        $r++;

        $first = $r;
        foreach ($rows as $row) {
            foreach ($row['c'] as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                else $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $rr = "A{$r}:{$last}{$r}";
            if ($row['k'] === 'head') {
                $sh->getStyle($rr)->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
                    'alignment' => ['wrapText' => true, 'vertical' => 'center'],
                ]);
                $sh->getStyle("B{$r}:{$last}{$r}")->getAlignment()->setHorizontal('right');
            } elseif ($row['k'] === 'total') {
                $sh->getStyle($rr)->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
                ]);
            }
            $r++;
        }
        $end = $r - 1;

        $sh->getStyle("B" . ($first + 1) . ":{$last}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        $sh->getStyle("{$last}{$first}:{$last}{$end}")->getFont()->setBold(true);
        $sh->getStyle("A{$first}:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sh->getColumnDimension('A')->setWidth(18);
        for ($c = 2; $c <= $n; $c++) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(16);
        $sh->freezePane('B' . ($first + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        return $ss;
    }

    /* ======================= Financial Reports → Trial Balance print (old Print ▾ Download XLSX / Download PDF) =======================
       old: TrialBalance.xlsx, [b.data1] Account ID · [b.data2] Account Description · [b.data3] Debit · [b.data4] Credit
       GET /api/print/trial-balance?filter_type=DM&from=2026-01&to=2026-09&account_code=&branch=current&format=xlsx|pdf */
    public function trialBalancePrint(Request $request)
    {
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';

        // same rows as the screen (ReportController::trialBalance validates the filters)
        $v = $this->trialBalance($request)->getData(true);
        $rows = $v['rows'] ?? [];

        $data = [];
        $td = 0.0;
        $tc = 0.0;
        foreach ($rows as $r) {
            $debit = round((float) ($r['debit'] ?? 0), 2);
            $credit = round((float) ($r['credit'] ?? 0), 2);
            $data[] = [(string) ($r['account_code'] ?? ''), (string) ($r['account_name'] ?? ''), $debit ?: '', $credit ?: ''];
            $td += $debit;
            $tc += $credit;
        }
        $data[] = ['', 'Total', round($td, 2), round($tc, 2)];               // old PDF total line

        $from = (string) $request->query('from');
        $to = (string) ($request->query('to') ?: $from);
        $company = $v['company'] ?? [];
        $master = [
            'TradeName' => (string) ($company['trade_name'] ?? ''),
            'TIN'       => (string) ($company['tin'] ?? ''),
            'Date'      => "{$from} to {$to}",
            'DateFrom'  => $from,
            'DateTo'    => $to,
        ];

        $template = $this->template('TrialBalance.xlsx');
        $ss = is_file($template)
            ? $this->fillTemplate($template, ['m' => $master], $data)
            : $this->trialBalanceSheet($data, $master);

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';

        if ($format === 'pdf') {
            $pdf = $this->toPdf($ss);
            return response()->download($pdf, "TrialBalance-{$who}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "TrialBalance-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** no template: the trial balance as on screen */
    private function trialBalanceSheet(array $data, array $master): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Trial Balance');

        $r = 1;
        foreach ([$master['TradeName'], 'General Ledger Trial Balance', "For the Period From {$master['Date']}"] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:D{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i < 2) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $head = $r;
        foreach (['Account ID', 'Account Description', 'Debit Amount', 'Credit Amount'] as $c => $t) {
            $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        }
        $sh->getStyle("A{$r}:D{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
        ]);
        $sh->getStyle("C{$r}:D{$r}")->getAlignment()->setHorizontal('right');
        $r++;

        if (count($data) === 1) {                                     // only the Total line
            $sh->setCellValue("A{$r}", 'No entries for this period.');
            $sh->mergeCells("A{$r}:D{$r}");
            $r++;
        }
        foreach ($data as $line) {
            foreach ($line as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $r++;
        }
        $end = $r - 1;

        $sh->getStyle("A{$end}:D{$end}")->applyFromArray([
            'font'    => ['bold' => true],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
        ]);
        $sh->getStyle("C" . ($head + 1) . ":D{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
        $sh->getStyle("C" . ($head + 1) . ":D{$end}")->getAlignment()->setHorizontal('right');
        $sh->getStyle("A{$head}:D{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        foreach (['A' => 14, 'B' => 48, 'C' => 18, 'D' => 18] as $col => $w) $sh->getColumnDimension($col)->setWidth($w);
        $sh->freezePane('A' . ($head + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($head, $head);
        return $ss;
    }
/* ======================= Financial Reports → Working Trial Balance print (Print ▾ Download XLSX / Download PDF) =======================
       GET /api/print/working-trial-balance?year=2026&branch=current&format=xlsx|pdf
       Optional template resources/report-templates/WorkingTrialBalance.xlsx: [b.data1] … [b.data15] repeats per line */
    private const WTB_SECTIONS = ['ASSET', 'LIABILITIES', 'OWNERS EQUITY', 'REVENUE', 'COS', 'EXPENSES', 'OTHER INCOME', 'OTHER EXPENSE', 'OTHER'];

    public function workingTrialBalancePrint(Request $request)
    {
        $format = $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';

        // same rows as the screen (incl. the typed Beg. Balances / Adjusting Entries)
        $v = $this->workingTrialBalance($request)->getData(true);
        $year = (string) ($v['year'] ?? $request->query('year'));

        $bySec = [];
        foreach ($v['rows'] ?? [] as $r) $bySec[$r['section'] ?? 'OTHER'][] = $r;

        // lines: k = sec | row | total | net ; c = 15 cells (Account Titles + 14 amounts)
        $lines = [];
        $T = array_fill(0, 14, 0.0);
        $isNet = 0.0;
        foreach (self::WTB_SECTIONS as $sec) {
            if (empty($bySec[$sec])) continue;
            $lines[] = ['k' => 'sec', 'c' => [$sec]];
            foreach ($bySec[$sec] as $r) {
                $begD = (float) $r['beg_debit'];  $begC = (float) $r['beg_credit'];
                $datD = (float) $r['data_debit']; $datC = (float) $r['data_credit'];
                $adjD = (float) $r['adj_debit'];  $adjC = (float) $r['adj_credit'];
                $endD = $begD + $datD;  $endC = $begC + $datC;
                $fendD = $endD + $adjD; $fendC = $endC + $adjC;
                $fclose = $fendD - $fendC;
                $is = ($r['statement'] ?? '') === 'IS' ? abs($fclose) : null;
                $bs = ($r['statement'] ?? '') === 'BS' ? abs($fclose) : null;
                if (($r['statement'] ?? '') === 'IS') $isNet += -$fclose;

                $vals = [$begD, $begC, $datD, $datC, $endD, $endC, $endD - $endC, $adjD, $adjC, $fendD, $fendC, $fclose, $is, $bs];
                foreach ($vals as $i => $x) $T[$i] += (float) $x;
                $lines[] = ['k' => 'row', 'c' => array_merge([(string) $r['account_name']], array_map(fn ($x) => $x === null ? '-' : round($x, 2), $vals))];
            }
        }
        $T[6] = $T[4] - $T[5];                                         // TOTAL closing = End D − End C
        $T[11] = $T[9] - $T[10];                                       // TOTAL final closing
        $lines[] = ['k' => 'total', 'c' => array_merge(['TOTAL'], array_map(fn ($x) => round($x, 2), $T))];
        $lines[] = ['k' => 'net', 'c' => array_merge(['Net Income / (Loss)'], array_fill(0, 12, ''), [round($isNet, 2), round(-$isNet, 2)])];

        $company = $v['company'] ?? [];
        $template = $this->template('WorkingTrialBalance.xlsx');
        $ss = is_file($template)
            ? $this->fillTemplate($template, ['m' => [
                'TradeName' => (string) ($company['trade_name'] ?? ''), 'TIN' => (string) ($company['tin'] ?? ''), 'Year' => $year,
            ]], array_column($lines, 'c'))
            : $this->wtbSheet($lines, (string) ($company['trade_name'] ?? ''), $year);

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';

        if ($format === 'pdf') {
            $pdf = $this->toPdf($ss);
            return response()->download($pdf, "WorkingTrialBalance-{$who}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "WorkingTrialBalance-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** no template: the grid as on screen (3 header rows, sections, TOTAL, Net Income) */
    private function wtbSheet(array $lines, string $tradeName, string $year): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Working Trial Balance');
        $blue = '1D4ED8';

        foreach ([$tradeName, 'Working Trial Balance', "Year {$year}"] as $i => $t) {
            $r = $i + 1;
            $sh->setCellValueExplicit("A{$r}", $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:O{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i < 2) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
        }

        // header (rows 5–7) like the screen
        $h = 5;
        $sh->setCellValue("A{$h}", 'ACCOUNT TITLES');
        $sh->mergeCells("A{$h}:A" . ($h + 2));
        $sh->setCellValue("B{$h}", $year);
        $sh->mergeCells("B{$h}:O{$h}");
        $groups = [['B', 'C', 'BEG. BALANCES'], ['D', 'E', "DATA-{$year}"], ['F', 'G', 'END. BALANCES'],
            ['I', 'J', 'ADJUSTING ENTRIES'], ['K', 'L', 'FINAL END. BALANCES']];
        foreach ($groups as [$a, $b, $t]) {
            $sh->setCellValue("{$a}" . ($h + 1), $t);
            $sh->mergeCells("{$a}" . ($h + 1) . ":{$b}" . ($h + 1));
            $sh->setCellValue("{$a}" . ($h + 2), 'DEBIT');
            $sh->setCellValue("{$b}" . ($h + 2), 'CREDIT');
        }
        foreach (['H' => "CLOSING BALANCE\nDEBIT (CREDIT)", 'M' => "FINAL CLOSING\nDEBIT (CREDIT)", 'N' => 'INCOME STATEMENT', 'O' => 'BALANCE SHEET'] as $col => $t) {
            $sh->setCellValue("{$col}" . ($h + 1), $t);
            $sh->mergeCells("{$col}" . ($h + 1) . ":{$col}" . ($h + 2));
        }
        $sh->getStyle("A{$h}:O" . ($h + 2))->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $blue]],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sh->getRowDimension($h + 1)->setRowHeight(26);

        $r = $h + 3;
        $first = $r;
        foreach ($lines as $line) {
            foreach ($line['c'] as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $rr = "A{$r}:O{$r}";
            if ($line['k'] === 'sec') {
                $sh->mergeCells($rr);
                $sh->getStyle($rr)->getFont()->setBold(true)->getColor()->setRGB($blue);
            } elseif ($line['k'] === 'row') {
                $sh->getStyle("A{$r}")->getAlignment()->setIndent(2);
            } elseif ($line['k'] === 'total') {
                $sh->getStyle($rr)->getFont()->setBold(true);
                $sh->getStyle($rr)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($blue);
            } elseif ($line['k'] === 'net') {
                $sh->getStyle($rr)->getFont()->setBold(true)->getColor()->setRGB('00AB55');
            }
            $r++;
        }
        $end = $r - 1;

        $sh->getStyle("B{$first}:O{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"-"');
        $sh->getStyle("B{$first}:O{$end}")->getAlignment()->setHorizontal('right');
        $sh->getStyle("A{$first}:O{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sh->getColumnDimension('A')->setWidth(38);
        foreach (range('B', 'O') as $col) $sh->getColumnDimension($col)->setWidth(14);
        $sh->freezePane('B' . $first);
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($h, $h + 2);
        return $ss;
    }
/* ======================= Other → Transactions print (old Print: table rows → merge.php + Transactions.xlsx, [b.data1] … [b.data34]) ======================= */

    /* POST /api/print/transactions — the lines on screen (after the column filters / quick search) → { token } */
    public function transactionsPrepare(Request $request)
    {
        $d = $request->validate([
            'type'   => ['required', 'in:purchase,sales,expense'],
            'from'   => ['required', 'date_format:Y-m'],
            'to'     => ['required', 'date_format:Y-m'],
            'branch' => ['nullable', 'string', 'max:20'],
            'head'   => ['required', 'array', 'max:40'],
            'money'  => ['nullable', 'array', 'max:40'],                // 0-based columns that are amounts
            'rows'   => ['present', 'array', 'max:50000'],
            'rows.*' => ['array', 'max:40'],
            'total'  => ['nullable', 'array', 'max:40'],
        ]);

        $clean = fn ($r) => array_map(fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 500), array_values($r));

        $token = Str::random(32);
        Cache::put($this->transactionsKey($request, $token), [
            'type'   => $d['type'],
            'from'   => $d['from'],
            'to'     => $d['to'],
            'branch' => $d['branch'] ?? 'current',
            'head'   => array_map('strval', $d['head']),
            'money'  => array_map('intval', $d['money'] ?? []),
            'rows'   => array_map($clean, $d['rows']),
            'total'  => $clean($d['total'] ?? []),
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /* GET /api/print/transactions/{token} → Transactions-{Username}.xlsx */
    public function transactionsPrint(Request $request, string $token)
    {
        $saved = Cache::pull($this->transactionsKey($request, $token));
        abort_if(!$saved, 404, 'This print has expired. Press Print again.');

        [, $account] = $this->journalScope($request, $saved['branch']);
        $h = $this->companyHeader($account) ?? [];
        $label = ['purchase' => 'Purchases', 'sales' => 'Sales', 'expense' => 'Expenses'][$saved['type']];
        $master = [
            'TradeName'     => (string) ($h['trade_name'] ?? ''),
            'TIN'           => (string) ($h['tin'] ?? ''),
            'DeclaredMonth' => $saved['from'],
            'DeclaredMonth2' => $saved['to'],
            'Type'          => $label,
        ];

        $data = $saved['rows'];
        if ($saved['total']) $data[] = $saved['total'];             // old: totals line at the end

        $template = $this->template('Transactions.xlsx');
        $ss = is_file($template)
            ? $this->fillTemplate($template, ['m' => $master], $data)
            : $this->transactionsSheet($saved['head'], $saved['money'], $saved['rows'], $saved['total'], $master);

        $who = $request->user()?->username ?? $this->addedByFor($request) ?? 'report';
        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "Transactions-{$who}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function transactionsKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:tx:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }

    /** no template: the table as on screen */
    private function transactionsSheet(array $head, array $money, array $rows, array $total, array $master): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Transactions');
        $n = max(1, count($head));
        $last = Coordinate::stringFromColumnIndex($n);

        $r = 1;
        foreach ([$master['TradeName'], 'TIN : ' . $master['TIN'], "Transactions · {$master['Type']}", "Date : {$master['DeclaredMonth']} – {$master['DeclaredMonth2']}"] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            if ($i === 0 || $i === 2) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize($i === 0 ? 14 : 12);
            $r++;
        }
        $r++;

        $hr = $r;
        foreach ($head as $c => $t) $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
        $sh->getRowDimension($r)->setRowHeight(30);
        $r++;

        foreach ($rows as $line) {
            foreach ($line as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $r++;
        }
        if ($total) {
            foreach ($total as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                'font'    => ['bold' => true],
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
            ]);
            $r++;
        }
        $end = max($hr, $r - 1);

        foreach ($money as $c) {
            $col = Coordinate::stringFromColumnIndex($c + 1);
            $sh->getStyle("{$col}" . ($hr + 1) . ":{$col}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
            $sh->getStyle("{$col}{$hr}:{$col}{$end}")->getAlignment()->setHorizontal('right');
            $sh->getColumnDimension($col)->setWidth(15);
        }
        for ($c = 1; $c <= $n; $c++) {
            if (in_array($c - 1, $money, true)) continue;
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(min(40, max(12, mb_strlen($head[$c - 1] ?? '') + 2)));
        }
        $sh->getStyle("A{$hr}:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sh->freezePane('B' . ($hr + 1));
        $sh->setAutoFilter("A{$hr}:{$last}" . max($hr, $end - ($total ? 1 : 0)));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        return $ss;
    }
/* ======================= Reports → VAT Summary (vat-clients) print =======================
       kind = list        : the client list on screen (Print ▾ XLSX / PDF)
       kind = ebir        : E-BIR table (old print_ebir.php + merge.php + EBIR.xlsx, [b.data1] …)  → EBIR-{UserID}.xlsx
       kind = undeclared  : undeclared Sales / Purchases / Expenses (old ReportToPDF → PDF preview)
       POST /api/print/vat-clients → { token }   ·   GET /api/print/vat-clients/{token} */
    public function vatClientsPrepare(Request $request)
    {
        $d = $request->validate([
            'kind'     => ['required', 'in:list,ebir,undeclared'],
            'format'   => ['nullable', 'in:xlsx,pdf'],
            'file'     => ['nullable', 'string', 'max:120'],
            'sheet'    => ['nullable', 'string', 'max:31'],
            'title'    => ['nullable', 'array', 'max:8'],          // header lines above the table
            'title.*'  => ['nullable', 'string', 'max:300'],
            'company'  => ['nullable', 'array'],                   // ebir: name, tin, address
            'range'    => ['nullable', 'string', 'max:80'],
            'head'     => ['nullable', 'array', 'max:60'],
            'money'    => ['nullable', 'array', 'max:60'],          // 0-based amount columns
            'rows'     => ['present', 'array', 'max:50000'],
            'rows.*.k' => ['nullable', 'string', 'max:10'],          // head | row | sub | grand | less | total
            'rows.*.c' => ['present', 'array', 'max:60'],
            'adjusted' => ['nullable', 'boolean'],                   // ebir: "See adjustment" view (extra Adjustment column)
        ]);

        $clean = fn ($r) => array_map(fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 500), array_values($r));
        $token = Str::random(32);
        Cache::put($this->vatClientsKey($request, $token), [
            'kind'    => $d['kind'],
            'format'  => $d['format'] ?? 'xlsx',
            'file'    => preg_replace('/[^\w.-]+/', '-', $d['file'] ?? 'VAT-Summary'),
            'sheet'   => $d['sheet'] ?? 'Report',
            'title'   => array_values(array_filter(array_map('strval', $d['title'] ?? []), 'strlen')),
            'company' => array_map('strval', $d['company'] ?? []),
            'range'   => (string) ($d['range'] ?? ''),
            'head'    => array_map('strval', $d['head'] ?? []),
            'money'   => array_map('intval', $d['money'] ?? []),
            'rows'    => array_map(fn ($r) => ['k' => $r['k'] ?? 'row', 'c' => $clean($r['c'])], $d['rows']),
            'adjusted' => (bool) ($d['adjusted'] ?? false),
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    public function vatClientsPrint(Request $request, string $token)
    {
        $s = Cache::pull($this->vatClientsKey($request, $token));
        abort_if(!$s, 404, 'This print has expired. Press Print again.');

        if ($s['kind'] === 'ebir') {
            // same as the 2550-M/Q Summary print: EBIR.xlsx template if you have it, else a clean sheet
            $template = $this->template('EBIR.xlsx');
            $ss = is_file($template) && empty($s['adjusted'])
                ? $this->ebirFromTemplate($template, array_column($s['rows'], 'c'))
                : $this->ebirSheet(array_column($s['rows'], 'c'), array_column($s['rows'], 'k'), $s['company'], $s['range']);
        } else {
            $ss = $this->vatClientsSheet($s);
        }

        if ($s['format'] === 'pdf') {
            $pdf = $this->toPdf($ss);
            return response()->download($pdf, "{$s['file']}.pdf", ['Content-Type' => 'application/pdf'], 'inline')
                ->deleteFileAfterSend(true);
        }

        $xlsx = $this->tempFile('xlsx');
        (new Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, "{$s['file']}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function vatClientsKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:vatc:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }

    /** client list / undeclared list: title lines, blue header, rows, Total line */
    private function vatClientsSheet(array $s): Spreadsheet
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle(substr(preg_replace('/[\\\\\/?*\[\]:]/', '-', $s['sheet']), 0, 31));
        $n = max(1, count($s['head']), ...array_map(fn ($r) => count($r['c']), $s['rows'] ?: [['c' => []]]));
        $last = Coordinate::stringFromColumnIndex($n);

        $r = 1;
        foreach ($s['title'] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
            $r++;
        }
        if ($s['title']) $r++;

        $hr = $r;
        foreach ($s['head'] as $c => $t) $sh->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $r, $t, DataType::TYPE_STRING);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
        $r++;

        if (!$s['rows']) {
            $sh->setCellValue("A{$r}", 'No records.');
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $r++;
        }
        foreach ($s['rows'] as $row) {
            foreach ($row['c'] as $c => $val) {
                $cell = Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, DataType::TYPE_STRING);
            }
            if (in_array($row['k'], ['total', 'grand'], true)) {
                $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
                ]);
            } elseif ($row['k'] === 'sub') {
                $sh->getStyle("A{$r}:{$last}{$r}")->getFont()->setItalic(true)->getColor()->setRGB('475569');
            }
            $r++;
        }
        $end = max($hr, $r - 1);

        foreach ($s['money'] as $c) {
            $col = Coordinate::stringFromColumnIndex($c + 1);
            $sh->getStyle("{$col}" . ($hr + 1) . ":{$col}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"-"');
            $sh->getStyle("{$col}{$hr}:{$col}{$end}")->getAlignment()->setHorizontal('right');
            $sh->getColumnDimension($col)->setWidth(14);
        }
        for ($c = 1; $c <= $n; $c++) {
            if (in_array($c - 1, $s['money'], true)) continue;
            $w = 8;
            foreach ($s['rows'] as $row) $w = max($w, min(40, mb_strlen((string) ($row['c'][$c - 1] ?? '')) + 2));
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($w);
        }
        $sh->getStyle("A{$hr}:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sh->freezePane('A' . ($hr + 1));
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($hr, $hr);
        return $ss;
    }
/* ======================= Billing → SOA dashboard prints (old Print/ReportPHP/SOA.php + CSOA.php) =======================
       GET /api/billing/soa/{transNumber}/print/{type}?service_id=     → PDF (inline, shown in the print preview)
       type   SOA        account's unpaid PF SOAs up to this period        (payments: PF col type 1)
              C_SOA      all branches of the client, grouped by trade name (old CSOA.php)
              PT_SOA     this SOA only
              EO_SOA     account's unpaid EO SOAs up to this period        (payments: EO col type 4)
              C_EO_SOA   all branches of the client, EO
              PT_EO_SOA  this EO SOA only
              C_RETURN   all branches of the client, Returns               (payments: Return col type 2)
              SOA2       one service (Options → PRINT SOA / ITEM)
       Trans types on t_transaction: 1 Return (recurring) · 2 EO · 3 PF  (old qry_service TransTypeID)
       Templates (resources/templates): SOA_v2_fixed.odt · SOA_v2_fixed2.odt (SOA2) · SOA_v2_fixed_FINAL.odt (C_SOA) */
    // TransTypeID of a service = m_service_list.trans_type_id (old qry_service_v3: tbl_service_list.TransTypeID)
    //   3 PF (payments col 1) · 2 EO (col 4) · 1 Return (col 2)
    private const SOA_PRINTS = [
        //            title                               trans type  payment col  scope
        'SOA'       => ['STATEMENT OF ACCOUNT',            3,    1, 'account'],
        'C_SOA'     => ['STATEMENT OF ACCOUNT',            3,    1, 'client'],
        'PT_SOA'    => ['STATEMENT OF ACCOUNT',            3,    1, 'this'],
        'EO_SOA'    => ['STATEMENT OF ACCOUNT (EO)',       2,    4, 'account'],
        'C_EO_SOA'  => ['STATEMENT OF ACCOUNT (EO)',       2,    4, 'client'],
        'PT_EO_SOA' => ['STATEMENT OF ACCOUNT (EO)',       2,    4, 'this'],
        'C_RETURN'  => ['STATEMENT OF ACCOUNT (RETURN)',   1,    2, 'client'],
        'SOA2'      => ['STATEMENT OF ACCOUNT',            null, 1, 'service'],
    ];

    public function billingSoaPrint(Request $request, string $transNumber, string $type)
    {
        // any failure → readable message in the print preview (instead of a bare 500)
        try {
            return $this->billingSoaBuild($request, $transNumber, $type);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'SOA print failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 422);
        }
    }

    private function billingSoaBuild(Request $request, string $transNumber, string $type)
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        $type = strtoupper($type);
        abort_unless(isset(self::SOA_PRINTS[$type]), 404, 'Unknown report.');
        [$title, $transType, $colType, $scope] = self::SOA_PRINTS[$type];

        $t = $DB::table('t_transaction')->where('trans_number', $transNumber)->first();
        abort_if(!$t, 404, 'Please select transaction');
        $serviceId = $request->integer('service_id');
        abort_if($scope === 'service' && !$serviceId, 422, 'Pick the service to print.');

        $account = $DB::table('m_client_account')->where('id', $t->ca_id)->first();
        abort_if(!$account, 404, 'Client account not found.');
        $client = $DB::table('m_client')->where('client_num', $account->client_num)->first();

        /* ---------- which SOAs (old $WHERE: CANum / ClientNum, IsPaid = 0, AccPeriodNum <= this period) ---------- */
        $periodKey = function ($p) {                               // "OCTOBER 2026" → 202610 · EO-2026 / unknown → null
            $ts = strtotime('1 ' . trim((string) $p));
            return $ts ? (int) date('Ym', $ts) : null;
        };
        $upTo = $periodKey($t->service_period);

        if (in_array($scope, ['this', 'service'], true)) {
            $trans = collect([$t]);
        } else {
            $caids = $scope === 'client'
                ? $DB::table('m_client_account')->where('client_num', $account->client_num)->pluck('id')->all()
                : [$t->ca_id];
            // old: t.CANum / ca.ClientNum, t.IsPaid = 0, AccPeriodNum <= this period (the type is checked on the services)
            $trans = $DB::table('t_transaction')->whereIn('ca_id', $caids)
                ->where(fn ($q) => $q->where('is_paid', 0)->orWhereNull('is_paid'))
                ->get()
                ->filter(function ($x) use ($periodKey, $upTo) {
                    $k = $periodKey($x->service_period);
                    return $k === null || $upTo === null || $k <= $upTo;
                })
                ->values();
        }
        $transNos = $trans->pluck('trans_number')->filter()->unique()->values()->all();

        /* ---------- services (old qry_service: CalServiceFeeNet > 0, not void / cancelled) ---------- */
        $tradeCol = $this->billingCol('m_client_account', ['trade_name', 'business_name', 'name']);
        // old qry_service_v3: CalVAT, CalWH, CalServiceFeeGross, CalDiscountAmt, CalServiceFeeNet
        $c = fn (array $names, string $fallback = '0') => ($col = $this->billingCol('t_service', $names)) ? "COALESCE(S.{$col},0)" : $fallback;
        $tot   = $c(['total_service_fee']);
        $dVat  = $c(['d_vat']);
        $dWh   = $c(['d_witholding', 'd_withholding']);
        $sc    = $c(['d_less_sc']);
        $pwd   = $c(['d_less_pwd']);
        $sp    = $c(['d_less_sp']);
        $oth   = $c(['d_less_others']);
        $pct   = $c(['less_other_percentage', 'less_others_percentage']);
        $iVat  = $c(['inherited_vat']);
        $iWh   = $c(['inherited_wh']);
        $iDisc = $c(['inherited_discount_amount', 'inherited_discount_amt']);
        $calVat = "(CASE WHEN {$dVat} = 1 THEN CASE WHEN {$iVat} <> 0 THEN {$iVat} ELSE {$tot} * 0.12 END ELSE 0 END)";
        $calWh  = "(CASE WHEN {$dWh} = 1 THEN CASE WHEN {$iWh} <> 0 THEN {$iWh} ELSE {$tot} * 0.05 END ELSE 0 END)";
        $gross  = "({$tot} + {$calVat} - {$calWh})";
        $disc   = "(CASE WHEN ({$sc} = 1 OR {$pwd} = 1 OR {$sp} = 1 OR {$oth} = 1) THEN CASE WHEN {$iDisc} <> 0 THEN {$iDisc}"
            . " WHEN {$sc} = 1 THEN {$gross} * 0.20 WHEN {$pwd} = 1 THEN {$gross} * 0.20 WHEN {$sp} = 1 THEN {$gross} * 0.10"
            . " WHEN {$oth} = 1 THEN {$gross} * ({$pct} / 100) ELSE 0 END ELSE 0 END)";
        $net    = "({$gross} - {$disc})";
        $slType = $this->billingCol('m_service_list', ['trans_type_id']);
        $services = $transNos ? $DB::table('t_service as S')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('t_transaction as TR', 'TR.trans_number', '=', 'S.trans_number')        // qry_service_v3: tbl_transaction tr
            ->leftJoin('m_client_account as A', 'A.id', '=', 'TR.ca_id')                  // ca.CAID = tr.CANum (TradeName / Branch)
            ->whereIn('S.trans_number', $transNos)
            ->when($scope === 'service', fn ($q) => $q->where('S.id', $serviceId))
            ->when($transType && $slType, fn ($q) => $q->where("L.{$slType}", $transType))      // old: TransTypeID = 3 / 2 / 1
            ->whereNotIn($DB::raw('COALESCE(S.invoice_status_id, 0)'), [5, 9])
            ->whereRaw("{$net} > 0")
            ->get([
                'S.id', 'S.service_period', 'S.qty', 'S.service_fee', 'S.surcharge', 'S.less', 'TR.ca_id',
                $DB::raw("{$calVat} AS cal_vat"), $DB::raw("{$calWh} AS cal_wh"), $DB::raw("{$disc} AS cal_disc"), $DB::raw("{$net} AS net"),
                $DB::raw(($this->billingCol('t_service', ['note']) ? 'S.note' : "''") . ' AS note'),
                $DB::raw("COALESCE(NULLIF(L.name, ''), " . ($this->billingCol('t_service', ['service']) ? 'S.service' : "''") . ", '') AS service"),
                $DB::raw(($tradeCol ? "A.{$tradeCol}" : "''") . ' AS trade_name'), 'A.branch_code',
                $DB::raw(($this->billingCol('m_client_account', ['branch_type']) ? 'A.branch_type' : "''") . ' AS branch_type'),
            ]) : collect();
        // old: AND AccPeriodNum <= this period (the service's own period)
        if (!in_array($scope, ['this', 'service'], true)) {
            $services = $services->filter(fn ($s) => ($k = $periodKey($s->service_period)) === null || $upTo === null || $k <= $upTo);
        }
        $services = $services->sortBy(fn ($s) => [$periodKey($s->service_period) ?? 0, $s->id])->values();

        /* ---------- payments (old tbl_payment, col type per report, amount > 0) ---------- */
        $payments = $transNos ? $DB::table('t_payment as P')
            ->leftJoin('t_service as S', 'S.id', '=', 'P.service_id')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('m_payment_type as PT', 'PT.id', '=', 'P.payment_type_id')
            ->leftJoin('m_payment_receipt_type as RT', 'RT.id', '=', 'P.receipt_type_id')
            ->leftJoin('m_client_account as A', 'A.id', '=', 'P.ca_id')
            ->whereIn('P.trans_number', $transNos)
            ->where('P.payment_col_type_id', $colType)
            ->where('P.amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('S.id')->orWhereNotIn($DB::raw('COALESCE(S.invoice_status_id, 0)'), [5, 9]))
            ->orderBy('P.payment_date')->orderBy('P.id')
            ->get(['P.*', $DB::raw("COALESCE(NULLIF(L.name, ''), " . ($this->billingCol('t_service', ['service']) ? 'S.service' : "''") . ", '') AS service"), $DB::raw('PT.name AS payment_type'),
                $DB::raw('RT.name AS receipt_type'), 'A.branch_code']) : collect();
        if ($scope !== 'this') {
            $payments = $payments->filter(fn ($p) => ($k = $periodKey($p->service_period ?? null)) === null || $upTo === null || $k <= $upTo)->values();
        }

        /* ---------- header (old: TaxPayer, Tradename, TIN, Address, SOANum, BillingPeriod) ---------- */
        $taxPayer = $this->billingClientName($client);
        $tradeName = $tradeCol ? (string) ($account->{$tradeCol} ?? '') : '';
        if (in_array($type, ['C_SOA', 'EO_SOA', 'C_EO_SOA'], true)) $tradeName = '';   // old SOA.php / CSOA.php
        $soaNo = $type === 'C_EO_SOA' ? '' : (string) $t->trans_number;
        $billingPeriod = (string) $t->service_period;              // old: the selected service period (POST BillingPeriod)
        $printNo = $this->billingPrintCount((string) $t->trans_number);

        $totalCharges = round((float) $services->sum('net'), 2);
        $totalPaid = round((float) $payments->sum('amount'), 2);
        $balance = round($totalCharges - $totalPaid, 2);
        $note = (string) ($services->filter(fn ($s) => trim((string) $s->note) !== '')->last()->note ?? '');

        /* ---------- old SOA.php / CSOA.php arrays → your ODT templates (resources/templates) ---------- */
        $f2 = fn ($v) => number_format((float) $v, 2);
        // Less column = Less + withholding + discount, VAT column = VAT (Option) -> each row adds up:
        // unit price x qty + surcharge + VAT - less = amount (same Total as the Billing Dashboard)
        $lessOf = fn ($s) => (float) $s->less + (float) $s->cal_wh + (float) $s->cal_disc;
        $vatOf = fn ($s) => (float) $s->cal_vat;

        $data2 = $payments->map(function ($p) use ($f2) {
            $ref = strlen((string) ($p->ar_number ?? '')) > 2 ? $p->ar_number
                : (strlen((string) ($p->ap_number ?? '')) > 2 ? $p->ap_number : (strlen((string) ($p->or_number ?? '')) > 2 ? $p->or_number : ''));
            return [
                'data1' => (string) $p->service,
                'data2' => $p->payment_date ? substr((string) $p->payment_date, 0, 10) : '',
                'data3' => (string) ($p->service_period ?? ''),
                'data4' => (string) ($p->payment_type ?? ''),
                'data5' => (string) ($p->receipt_type ?? ''),
                'data6' => (string) $ref,
                'data7' => $f2($p->amount),
                'data8' => (string) ($p->branch_code ?? ''),
            ];
        })->values()->all();

        $vars = [
            'Title' => $title, 'TaxPayer' => $taxPayer, 'Tradename' => $tradeName,
            'TIN' => $this->billingTin($account, $client), 'Address' => $this->billingAddress($account, $client),
            'SOANum' => $soaNo, 'BillingPeriod' => $billingPeriod, 'Note' => $note,
            'TP' => $f2($totalPaid), 'TS' => $f2($totalCharges), 'TC' => $f2($services->sum('service_fee')),
            'LWT' => $f2($services->sum($lessOf)), 'VAT' => $f2($services->sum($vatOf)), 'NAD' => $f2($totalCharges), 'PR' => $f2($totalPaid),
            'OB' => $f2($balance), 'BT' => $f2($balance), 'TB' => $f2($balance),
            'TB_Text' => $this->billingWords($balance) . ' (' . $f2($balance) . ')',
            'No' => $printNo ?? 1,
        ];

        $tbs = $this->billingTbs();
        $tbs->VarRef = [];                                            // [onshow.*] fields read from here (old: global variables)
        foreach ($vars as $k => $v) $tbs->VarRef[$k] = $v;

        if ($type === 'C_SOA') {
            // old CSOA.php → SOA_v2_fixed_FINAL: [mb.data0] trade name, [mb.data1] subtotal, [sb.data1..8] its services
            $SOA_Data = [];
            foreach ($services->groupBy(fn ($s) => trim(($s->trade_name ?? '') . ' - ' . ($s->branch_type ?? '') . ' ' . ($s->branch_code ?? ''))) as $trade => $rows) {
                $SOA_Data[] = [
                    'data0' => $trade,
                    'data1' => $f2($rows->sum('net')),
                    'matches' => $rows->map(fn ($s) => [
                        'data1' => (string) $s->service_period, 'data2' => (string) $s->service,
                        'data3' => $f2($s->qty), 'data4' => $f2($s->service_fee), 'data5' => $f2($s->surcharge),
                        'data6' => $f2($vatOf($s)), 'data7' => $f2($lessOf($s)), 'data8' => $f2($s->net),
                    ])->values()->all(),
                ];
            }
            $tbs->VarRef['SOA_Data'] = $SOA_Data;
            $tbs->LoadTemplate($this->billingTemplate('SOA_v2_fixed_FINAL'), OPENTBS_ALREADY_UTF8);
            $tbs->MergeBlock('mb', 'array', 'SOA_Data');
            $tbs->MergeBlock('sb', 'array', 'SOA_Data[%p1%][matches]');
        } else {
            // old SOA.php → SOA_v2_fixed (SOA2: SOA_v2_fixed2): [a.data1..8] services
            $data = $services->map(fn ($s) => [
                'data1' => (string) $s->service_period, 'data2' => (string) $s->service,
                'data3' => (string) (0 + (float) $s->qty), 'data4' => $f2($s->service_fee), 'data5' => $f2($s->surcharge),
                'data6' => $f2($vatOf($s)), 'data7' => $f2($lessOf($s)), 'data8' => $f2($s->net),
            ])->values()->all();
            $tbs->LoadTemplate($this->billingTemplate($type === 'SOA2' ? 'SOA_v2_fixed2' : 'SOA_v2_fixed'), OPENTBS_ALREADY_UTF8);
            $tbs->MergeBlock('a', $data);
        }

        // payments: the 'pay' part is removed when there are none (old code)
        $tbs->MergeBlock('pay', $data2 ? [['show' => 1]] : []);
        $tbs->MergeBlock('b', $data2);

        $odt = $this->tempFile('odt');
        $tbs->Show(OPENTBS_FILE, $odt);
        $pdf = $this->officeToPdf($odt);
        @unlink($odt);

        $name = preg_replace('/[\\\\\/:*?"<>|]+/', '-', trim("{$taxPayer} - {$t->trans_number}")) . '.pdf';
        return response()->download($pdf, $name, ['Content-Type' => 'application/pdf'], 'inline')->deleteFileAfterSend(true);
    }

    /** TinyButStrong + OpenTBS (composer require tinybutstrong/opentbs), or the old tbs_class.php / tbs_plugin_opentbs.php files */
    private function billingTbs(): \clsTinyButStrong
    {
        if (!class_exists('clsTinyButStrong') || !class_exists('clsOpenTBS')) {
            foreach ([app_path('Libraries/tbs'), app_path('Libraries'), base_path('libraries/tbs'), resource_path('templates')] as $dir) {
                if (!class_exists('clsTinyButStrong') && is_file("{$dir}/tbs_class.php")) require_once "{$dir}/tbs_class.php";
                if (!class_exists('clsOpenTBS') && is_file("{$dir}/tbs_plugin_opentbs.php")) require_once "{$dir}/tbs_plugin_opentbs.php";
            }
        }
        abort_unless(class_exists('clsTinyButStrong') && class_exists('clsOpenTBS'), 422,
            'OpenTBS is missing. Run: composer require tinybutstrong/opentbs  (or copy tbs_class.php and tbs_plugin_opentbs.php to app/Libraries/tbs).');
        $tbs = new \clsTinyButStrong();
        $tbs->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);
        $tbs->NoErr = true;                                           // a field missing in the template is not an error
        return $tbs;
    }

    /** resources/templates/{name}.odt */
    private function billingTemplate(string $name): string
    {
        foreach ([resource_path("templates/{$name}.odt"), resource_path("templates/{$name}"), resource_path("report-templates/{$name}.odt")] as $f) {
            if (is_file($f)) return $f;
        }
        abort(422, "Template {$name}.odt not found in " . resource_path('templates'));
    }

    /** any office file (ODT) → PDF with LibreOffice (same search / lock / profile as toPdf) */
    private function officeToPdf(string $file): string
    {
        @set_time_limit(300);
        $outDir = dirname($file);
        $pdf = $outDir . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.pdf';
        $win = PHP_OS_FAMILY === 'Windows';
        $candidates = array_filter([
            config('services.libreoffice.path'),
            env('LIBREOFFICE_PATH'),
            ...($win ? [
                'C:\\Program Files\\LibreOffice\\program\\soffice.com',
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            ] : ['/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice']),
        ]);
        $profileUrl = 'file:///' . ltrim(str_replace('\\', '/', storage_path('app/libreoffice-profile')), '/');
        $lock = fopen(storage_path('app/libreoffice.lock'), 'c');
        $tried = [];
        foreach (array_unique($candidates) as $soffice) {
            if (!is_file($soffice)) { $tried[] = "{$soffice}: not found"; continue; }
            if ($win && str_ends_with(strtolower($soffice), '.exe') && is_file(substr($soffice, 0, -4) . '.com')) $soffice = substr($soffice, 0, -4) . '.com';
            if ($lock) flock($lock, LOCK_EX);
            [$code, $out] = $this->run([$soffice, "-env:UserInstallation={$profileUrl}", '--headless', '--norestore', '--nologo',
                '--convert-to', 'pdf', '--outdir', $outDir, $file], 180);
            for ($i = 0; $i < 40 && !is_file($pdf); $i++) usleep(250000);
            if ($lock) flock($lock, LOCK_UN);
            if (is_file($pdf) && filesize($pdf) > 0) { if ($lock) fclose($lock); return $pdf; }
            $tried[] = "{$soffice}: exit {$code} " . trim(mb_substr($out, 0, 300));
        }
        if ($lock) fclose($lock);
        logger()->warning('SOA PDF failed', ['tried' => $tried]);
        abort(response()->json([
            'message' => "Couldn't make the PDF. Install LibreOffice or set LIBREOFFICE_PATH in .env to your soffice.exe.",
            'tried' => $tried,
        ], 422));
    }

    private function billingCol(string $table, array $names): ?string
    {
        static $cache = [];
        $cols = $cache[$table] ??= \Illuminate\Support\Facades\Schema::hasTable($table) ? \Illuminate\Support\Facades\Schema::getColumnListing($table) : [];
        foreach ($names as $n) foreach ($cols as $c) if (strcasecmp($c, $n) === 0) return $c;
        return null;
    }

    /** old: CONCAT(LastName,' ',FirstName,' ',MiddleName) — or the registered / business name */
    private function billingClientName($client): string
    {
        if (!$client) return '';
        $person = trim(implode(' ', array_filter([$client->last_name ?? '', $client->first_name ?? '', $client->middle_name ?? ''])));
        foreach (['registered_name', 'client_name', 'business_name', 'company_name', 'name'] as $c) {
            if (!empty($client->$c) && trim((string) $client->$c) !== '') return $person !== '' ? $person : trim((string) $client->$c);
        }
        return $person;
    }

    /** old: CONCAT(Street,' ',Barangay,' ',CityMun,' ',Province) */
    private function billingAddress($account, $client): string
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        $src = fn ($k) => !empty($account->$k) ? $account->$k : ($client->$k ?? null);
        $place = function (array $tables, $id, array $keys) use ($DB) {
            if (!$id) return '';
            foreach ($tables as $t) {
                if (!\Illuminate\Support\Facades\Schema::hasTable($t)) continue;
                $row = (array) ($DB::table($t)->where('id', $id)->first() ?? []);
                foreach ($keys as $k) if (isset($row[$k]) && trim((string) $row[$k]) !== '') return trim((string) $row[$k]);
                return '';
            }
            return '';
        };
        return trim(preg_replace('/\s+/', ' ', implode(' ', [
            (string) ($src('street') ?? ''),
            $place(['m_barangay', 'm_brgy', 'm_barangays'], $src('brgy_id'), ['barangay', 'brgy', 'name', 'description']),
            $place(['m_city_mun', 'm_city', 'm_city_municipality', 'm_cities'], $src('city_id'), ['city_mun', 'citymun', 'city', 'name', 'description']),
            $place(['m_province', 'm_provinces'], $src('province_id'), ['province', 'name', 'description']),
        ])));
    }

    /** old: tbl_client_valid_id.IDNum where Institution = 'BIR' (new: ClientValidId::bir()) */
    private function billingTin($account, $client): string
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        $clientNum = $account->client_num ?? $client->client_num ?? null;
        if ($clientNum && class_exists(\App\Models\ClientValidId::class)) {
            $tin = (string) \App\Models\ClientValidId::bir()->where('client_num', $clientNum)->orderByDesc('id')->value('id_num');
            if (trim($tin) !== '') return trim($tin);
        }
        if ($clientNum) {
            foreach (['m_client_valid_id', 'm_client_valid_ids'] as $t) {
                if (!\Illuminate\Support\Facades\Schema::hasTable($t)) continue;
                $inst = $this->billingCol($t, ['institution', 'institution_name']);
                $num = $this->billingCol($t, ['id_num', 'idnum', 'id_number']);
                if (!$num) break;
                $tin = (string) $DB::table($t)->where('client_num', $clientNum)
                    ->when($inst, fn ($q) => $q->where($inst, 'BIR'))->orderByDesc('id')->value($num);
                if (trim($tin) !== '') return trim($tin);
            }
        }
        return (string) ($account->tin ?? $client->tin ?? '');
    }

    /** old getOrIncrementSOAPrintCount (tbl2_generated_soa_no) — counts only if the table is there */
    private function billingPrintCount(string $ref): ?int
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        foreach (['t_generated_soa_no', 'm_generated_soa_no', 'tbl2_generated_soa_no'] as $table) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) continue;
            $refCol = $this->billingCol($table, ['reference_no', 'referenceno']);
            $genCol = $this->billingCol($table, ['generated_no', 'generatedno']);
            $noCol = $this->billingCol($table, ['no_of_print', 'noofprint']);
            if (!$refCol || !$noCol) return null;
            $row = $DB::table($table)->where($refCol, $ref)->first();
            $upd = $this->billingCol($table, ['updated_at', 'date_updated', 'dateupdated']);
            $add = $this->billingCol($table, ['created_at', 'date_added', 'dateadded']);
            if ($row) {
                $n = (int) $row->$noCol + 1;
                $DB::table($table)->where($refCol, $ref)->update(array_filter([$noCol => $n, $upd => $upd ? now() : null], fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH));
                return $n;
            }
            $DB::table($table)->insert(array_filter([$refCol => $ref, $genCol => $ref, $noCol => 1, $add => now(), $upd => now()], fn ($v, $k) => $k !== '', ARRAY_FILTER_USE_BOTH));
            return 1;
        }
        return null;
    }

    /** old convertIntegerToWords: whole pesos */
    private function billingWords(float $amount): string
    {
        $n = (int) round(abs($amount));
        if ($n === 0) return 'ZERO PESOS';
        $ones = ['ZERO', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN', 'ELEVEN', 'TWELVE',
            'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
        $tens = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
        $scales = ['', ' THOUSAND', ' MILLION', ' BILLION'];
        $hundreds = function (int $x) use ($ones, $tens) {
            $s = '';
            if ($x >= 100) { $s .= $ones[intdiv($x, 100)] . ' HUNDRED'; $x %= 100; if ($x) $s .= ' '; }
            if ($x >= 20) { $s .= $tens[intdiv($x, 10)]; $x %= 10; if ($x) $s .= ' ' . $ones[$x]; }
            elseif ($x > 0) $s .= $ones[$x];
            return $s;
        };
        $s = '';
        for ($i = 0; $n > 0; $i++, $n = intdiv($n, 1000)) {
            if ($n % 1000) $s = trim($hundreds($n % 1000) . $scales[$i] . ' ' . $s);
        }
        return ($amount < 0 ? 'NEGATIVE ' : '') . $s . ' PESOS';
    }
    public function returnArPrint(Request $request, string $transNumber)
    {
        try {
            $DB = \Illuminate\Support\Facades\DB::class;
            $t = $DB::table('t_transaction')->where('trans_number', $transNumber)->first();
            abort_if(!$t, 404, 'Please select transaction');
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))));
            abort_if(!$ids, 422, 'Please select service');

            $account = $DB::table('m_client_account')->where('id', $t->ca_id)->first();
            $client = $account ? $DB::table('m_client')->where('client_num', $account->client_num)->first() : null;

            $svcCol = $this->billingCol('t_service', ['service']) ? 'S.service' : "''";
            $rows = $DB::table('t_service as S')
                ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
                ->where('S.trans_number', $transNumber)
                ->whereIn('S.id', $ids)
                ->orderBy('S.id')
                ->get(['S.id', 'S.total_service_fee', 'S.prep_target_date',
                    $DB::raw("COALESCE(NULLIF({$svcCol}, ''), L.name, '') AS service")]);
            abort_if($rows->isEmpty(), 422, 'Please select service');

            // old: data1 = selected service period, data2 = service, data3 = TOTAL, data4 = due date
            $data = [];
            $fTotal = 0.0;
            foreach ($rows as $r) {
                $amt = (float) ($r->total_service_fee ?? 0);
                $data[] = [
                    'data1' => (string) $t->service_period,
                    'data2' => (string) $r->service,
                    'data3' => number_format($amt, 2),
                    'data4' => $r->prep_target_date ? substr((string) $r->prep_target_date, 0, 10) : '',
                ];
                $fTotal += $amt;
            }

            $tin = $this->billingTin($account, $client);
            $clientName = $this->billingClientName($client);

            $tbs = $this->billingTbs();
            // old merge.php globals used by [onshow.*] in PrintAR.odt
            $tbs->VarRef['TIN'] = $tin;
            $tbs->VarRef['ClientName'] = $clientName;
            $tbs->VarRef['ARNumber'] = (string) $t->trans_number;
            $tbs->VarRef['Total'] = number_format($fTotal, 2);
            $tbs->VarRef['FTotal'] = $fTotal;
            $tbs->LoadTemplate($this->billingTemplate('PrintAR'), OPENTBS_ALREADY_UTF8);
            $tbs->MergeBlock('b', $data);

            $odt = $this->tempFile('odt');
            $tbs->Show(OPENTBS_FILE, $odt);
            $pdf = $this->officeToPdf($odt);
            @unlink($odt);

            $name = preg_replace('/[\\\\\/:*?"<>|]+/', '-', trim("{$tin} - {$clientName}")) . '.pdf';
            return response()->download($pdf, $name, ['Content-Type' => 'application/pdf'], 'inline')->deleteFileAfterSend(true);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Print AR failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 422);
        }
    }
    /* ======================= Billing → Deposit: PRINT R1 (old merge.php → PrintR1.odt, view qry2_deposit_r1) ======================= */
    // GET /billing/deposit/r1?date=YYYY-MM-DD   deposited payments of that DEPOSIT date (the date saved by the Deposit button)
    /* ======================= Billing → Deposit: PRINT R1 (old merge.php → PrintR1.odt, view qry2_deposit_r1) ======================= */
    // GET /billing/deposit/r1?date=YYYY-MM-DD   deposited payments of that DEPOSIT date (the date saved by the Deposit button)
    public function depositR1Print(Request $request)
    {
        try {
            $DB = \Illuminate\Support\Facades\DB::class;
            $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->toDateString();

            $p = fn (array $n) => $this->billingCol('t_payment', $n);
            $slip = $p(['dep_slip_number']);
            $payDate = $p(['payment_date']);
            $depDate = $p(['prepared_date']) ?: $payDate;                     // Deposit → prepared_date = deposit date
            abort_if(!$slip || !$depDate, 422, 't_payment has no dep_slip_number / prepared_date column.');

            $trade = $this->billingCol('m_client_account', ['trade_name', 'business_name', 'name']);
            $q = $DB::table('t_payment as P')
                ->leftJoin('m_client_account as A', 'A.id', '=', 'P.ca_id')
                ->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num')
                ->whereNotNull("P.{$slip}")->where("P.{$slip}", '<>', '')          // old: DepSlipNumber IS NOT NULL
                ->whereDate("P.{$depDate}", $date)
                ->select(['P.*', 'A.branch_code', 'A.client_num as acc_client_num',
                    $DB::raw($trade ? "A.{$trade} AS trade_name" : "'' AS trade_name"),
                    $DB::raw(($this->billingCol('m_client', ['last_name']) ? 'C.last_name' : "''") . ' AS client_last_name')]);

            // lookups (old LEFT JOINs) – only if the tables exist
            $lookup = function (string $table, string $fk, string $as, array $cols) use ($q, $DB) {
                if (!\Illuminate\Support\Facades\Schema::hasTable($table) || !$this->billingCol('t_payment', [$fk])) return;
                $q->leftJoin("{$table} as {$as}", "{$as}.id", '=', "P.{$fk}");
                foreach ($cols as $alias => $names) {
                    $c = $this->billingCol($table, $names);
                    $q->addSelect($DB::raw(($c ? "{$as}.{$c}" : "''") . " AS {$alias}"));
                }
            };
            $lookup('m_payment_col_type', 'payment_col_type_id', 'COL', ['col_type_name' => ['name', 'payment_col_type']]);
            $lookup('m_payment_type', 'payment_type_id', 'PT', ['payment_type_name' => ['name', 'payment_type']]);
            $lookup('m_payment_receipt_type', 'receipt_type_id', 'RT', ['receipt_type_name' => ['name', 'receipt_type'], 'receipt_type_desc' => ['description', 'name']]);

            // old: NOT EXISTS (tbl_service.ReturnStatusID IN (3,4))
            if (($ret = $this->billingCol('t_service', ['return_status_id'])) && $p(['service_id'])) {
                $q->whereNotExists(fn ($w) => $w->from('t_service as Z')->whereColumn('Z.id', 'P.service_id')->whereIn("Z.{$ret}", [3, 4]));
            }

            $rows = $q->orderBy('P.id')->get();
            // like the old merge.php: no rows → still print the R1 (empty list)

            // same as the old PrintR1.php: grouped by collection type → header row, lines (numbered), TOTAL:, then GRAND TOTAL:
            $day = fn ($v) => $v ? substr((string) $v, 0, 10) : '';
            $blank = ['payment_id' => '', 'client_name' => '', 'payment_date' => '', 'period' => '', 'amount' => '',
                'tender' => '', 'details' => '', 'or_num' => '', 'ar_num' => '', 'remarks' => ''];
            $groups = [];
            $n = 1;
            foreach ($rows as $r) {
                $amount = (float) ($r->amount ?? 0);
                $co = (float) ($r->co_amount ?? 0);
                $net = (float) ($r->total_amount ?? 0) ?: $amount - $co;
                $label = trim((string) ($r->col_type_name ?? '')) ?: 'OTHERS';
                $groups[$label] ??= ['rows' => [], 'subtotal' => 0.0];
                $groups[$label]['subtotal'] += $net;
                // fields used by DepositR1_FINAL.odt:  [r.payment_id;block=tbs:row] [r.client_name] [r.payment_date] ...
                $groups[$label]['rows'][] = [
                    'payment_id' => $n++,
                    'client_name' => trim((string) ($r->trade_name ?? '')) !== '' ? $r->trade_name : ($r->client_last_name ?? ''),
                    'payment_date' => $day($payDate ? ($r->{$payDate} ?? null) : null),
                    'period' => $r->service_period ?? '',
                    'amount' => number_format($net, 2),
                    'tender' => $r->payment_type_name ?? '',
                    'details' => trim(($r->details1 ?? '') . ' ' . ($r->details2 ?? '')),
                    'or_num' => $r->or_number ?? '',
                    'ar_num' => $r->ar_number ?? '',
                    'remarks' => $r->remarks ?? '',
                ];
            }
            $total = 0.0;
            $data = [];
            foreach ($groups as $label => $g) {
                $data[] = ['payment_id' => $label] + $blank;                                               // group header
                array_push($data, ...$g['rows']);                                                          // lines
                $data[] = ['period' => 'TOTAL:', 'amount' => number_format($g['subtotal'], 2)] + $blank;    // subtotal
                $total += $g['subtotal'];
            }
            $data[] = ['period' => 'GRAND TOTAL:', 'amount' => number_format($total, 2)] + $blank;         // footer

            $tbs = $this->billingTbs();
            // [onshow.DepSlipNumber]: the deposit slip no(s). of that date
            $tbs->VarRef['DepSlipNumber'] = $rows->pluck($slip)->filter()->unique()->implode(', ');
            $tbs->VarRef['DepositDate'] = $date;
            $tbs->VarRef['Total'] = number_format($total, 2);
            $tbs->LoadTemplate($this->billingTemplate('DepositR1_FINAL'), OPENTBS_ALREADY_UTF8);
            $tbs->MergeBlock('r', $data);

            $odt = $this->tempFile('odt');
            $tbs->Show(OPENTBS_FILE, $odt);
            $pdf = $this->officeToPdf($odt);
            @unlink($odt);

            return response()->download($pdf, "R1-{$date}.pdf", ['Content-Type' => 'application/pdf'], 'inline')->deleteFileAfterSend(true);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Http\Exceptions\HttpResponseException | \Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Print R1 failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ], 422);
        }
    }
    /* ======================= Monitoring → Collection Monitoring: Excel ======================= */

    /* POST /api/print/collection-monitoring — all rows of the current filters (from ?export=1) → { token } */
    public function collectionExcelPrepare(Request $request)
    {
        $d = $request->validate([
            'from'   => ['required', 'date'],
            'to'     => ['required', 'date'],
            'type'   => ['nullable', 'string', 'max:20'],
            'head'   => ['required', 'array', 'max:30'],
            'rows'   => ['present', 'array', 'max:100000'],
            'rows.*' => ['array', 'max:30'],
            'total'  => ['nullable', 'array', 'max:30'],
        ]);
        $clean = fn ($r) => array_map(fn ($v) => is_int($v) || is_float($v) ? (float) $v : mb_substr((string) ($v ?? ''), 0, 500), array_values($r));

        $token = \Illuminate\Support\Str::random(32);
        \Illuminate\Support\Facades\Cache::put($this->collectionExcelKey($request, $token), [
            'from'  => $d['from'],
            'to'    => $d['to'],
            'type'  => $d['type'] ?? 'All',
            'head'  => array_map('strval', $d['head']),
            'rows'  => array_map($clean, $d['rows']),
            'total' => $clean($d['total'] ?? []),
        ], now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /* GET /api/print/collection-monitoring/{token} → CollectionMonitoring-{Username}.xlsx */
    public function collectionExcel(Request $request, string $token)
    {
        $s = \Illuminate\Support\Facades\Cache::pull($this->collectionExcelKey($request, $token));
        abort_if(!$s, 404, 'This export has expired. Press Excel again.');
        $who = $request->user()?->username ?? 'report';

        // old Print: CollectionMonitoring.xlsx, [b.data1] … [b.data13] (# … Deposited; no Status), totals line at the end
        $template = method_exists($this, 'template') ? $this->template('CollectionMonitoring.xlsx') : '';
        if (is_file($template) && method_exists($this, 'fillTemplate')) {
            $h = [];
            if (method_exists($this, 'journalScope') && method_exists($this, 'companyHeader')) {
                [, $account] = $this->journalScope($request, 'current');
                $h = $this->companyHeader($account) ?? [];
            }
            $data = array_map(fn ($r) => array_slice($r, 0, 13), $s['rows']);
            if ($s['total']) $data[] = array_slice($s['total'], 0, 13);
            $ss = $this->fillTemplate($template, ['m' => [
                'TradeName' => (string) ($h['trade_name'] ?? ''), 'TIN' => (string) ($h['tin'] ?? ''),
                'Street' => (string) ($h['address'] ?? ''), 'RDateRange' => "{$s['from']} - {$s['to']}",
            ]], $data);
            $xlsx = method_exists($this, 'tempFile') ? $this->tempFile('xlsx') : tempnam(sys_get_temp_dir(), 'col') . '.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($xlsx);
            $ss->disconnectWorksheets();
            return response()->download($xlsx, "CollectionMonitoring-{$who}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        // no template: full sheet (all columns incl. Status)
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Collection Monitoring');
        $n = max(1, count($s['head']));
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
        $amountCol = array_search('Amount received', $s['head'], true);

        $sh->setCellValue('A1', 'Collection Monitoring');
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sh->setCellValueExplicit('A2', "PF · Return · EO payments  ·  Type: {$s['type']}  ·  {$s['from']} to {$s['to']}", \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        $hr = 4;
        foreach ($s['head'] as $c => $t) $sh->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . $hr, $t, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sh->getStyle("A{$hr}:{$last}{$hr}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'alignment' => ['vertical' => 'center'],
        ]);

        $r = $hr + 1;
        $put = function (array $line, int $r) use ($sh) {
            foreach ($line as $c => $val) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . $r;
                if (is_float($val) || is_int($val)) $sh->setCellValue($cell, $val);
                elseif ($val !== '') $sh->setCellValueExplicit($cell, (string) $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        };
        foreach ($s['rows'] as $line) $put($line, $r++);
        $lastData = $r - 1;
        if ($s['total']) {
            $put($s['total'], $r);
            $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                'font'    => ['bold' => true],
                'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF2FF']],
                'borders' => ['top' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN], 'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOUBLE]],
            ]);
            $r++;
        }
        $end = max($hr, $r - 1);

        if ($amountCol !== false) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($amountCol + 1);
            $sh->getStyle("{$col}" . ($hr + 1) . ":{$col}{$end}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00)');
            $sh->getStyle("{$col}{$hr}:{$col}{$end}")->getAlignment()->setHorizontal('right');
        }
        $sh->getStyle("A{$hr}:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        for ($c = 1; $c <= $n; $c++) $sh->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        $sh->freezePane('A' . ($hr + 1));
        $sh->setAutoFilter("A{$hr}:{$last}" . max($hr, $lastData));

        $name = "CollectionMonitoring-{$who}.xlsx";
        $xlsx = method_exists($this, 'tempFile') ? $this->tempFile('xlsx') : tempnam(sys_get_temp_dir(), 'col') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($xlsx);
        $ss->disconnectWorksheets();

        return response()->download($xlsx, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function collectionExcelKey(Request $request, string $token): string
    {
        $u = $request->user();
        return 'print:col:' . ($u ? class_basename($u) . ':' . $u->getKey() : 'guest') . ':' . preg_replace('/\W/', '', $token);
    }
}