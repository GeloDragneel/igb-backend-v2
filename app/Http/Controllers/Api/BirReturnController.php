<?php

namespace App\Http\Controllers\Api;
use Carbon\Carbon;
use App\Models\ChartsOfAccount;
use App\Models\GRNMaster;
use App\Models\PurchaseExpenseDetail;
use App\Models\PurchaseExpenseMaster;
use App\Models\SalesInvoiceDetail;
use App\Models\SalesInvoiceMaster;
use App\Models\ClientValidId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BirReturnController extends ReportController
{
    // e.g. 123456789000-2550Qv2024-122026Q4.xml
    private const FILE_RE = '/^\d{9,}[0-9A-Za-z]*-[0-9A-Za-z]+-[0-9A-Za-z]+\.xml$/';
    private array $cols = [];

    /* ======================= helpers ======================= */

    private function ebirPath(string $sub): string
    {
        return rtrim((string) config('services.ebir.path', 'C:/eBIRForms'), '/\\') . '/' . $sub;
    }

    // first column that exists (old/new column names differ between tables)
    private function col(string $table, array $candidates): ?string
    {
        $key = $table . '|' . implode(',', $candidates);
        if (!array_key_exists($key, $this->cols)) {
            $this->cols[$key] = collect($candidates)->first(fn ($c) => Schema::hasColumn($table, $c));
        }
        return $this->cols[$key];
    }

    // TIN: client's BIR valid ID → branch account → client → user
    private function findTin(Request $request, $account): string
    {
        $digits = fn ($v) => preg_replace('/\D/', '', (string) $v);

        $row = $account ? DB::table('m_client_account')->where('id', $account->id)->first() : null;
        $clientNum = trim((string) ($row->client_num ?? ''));

        // 1. m_client_valid_id where institution = BIR  (old tbl_client_valid_id)
        if ($clientNum !== '') {
            $tin = ClientValidId::bir()
                ->where('client_num', $clientNum)
                ->orderByDesc('id')
                ->pluck('id_num')
                ->map($digits)
                ->first(fn ($t) => strlen($t) >= 9);
            if ($tin) return $tin;
        }

        // 2–4. fallbacks: tin columns on the account, the client, then the user
        $cols = ['tin', 'tin_no', 'tin_number'];
        $fromRow = function ($r) use ($cols, $digits) {
            $a = is_array($r) ? $r : (array) $r;
            foreach ($cols as $c) {
                $t = $digits($a[$c] ?? '');
                if (strlen($t) >= 9) return $t;
            }
            return '';
        };

        if ($row && ($t = $fromRow($row)) !== '') return $t;

        if ($clientNum !== '' && Schema::hasTable('m_client')) {
            $client = DB::table('m_client')->where('client_num', $clientNum)->first();
            if ($client && ($t = $fromRow($client)) !== '') return $t;
        }

        $user = $request->user();
        if ($user && ($t = $fromRow($user->getAttributes())) !== '') return $t;

        return '';
    }

    // [tin9, branch3, companyHeader, account]
    private function tinParts(Request $request): array
    {
        [, $account] = $this->journalScope($request, 'current');
        $h = $this->companyHeader($account) ?? [];

        $digits = $this->findTin($request, $account);
        abort_if($digits === '', 422, "This client has no TIN yet. Add the TIN to the client's record first, then open BIR Return again.");

        $tin9 = substr($digits, 0, 9);
        $branch = strlen($digits) >= 12 ? substr($digits, 9, 3) : '000';

        // use the branch code from the account if the TIN has none
        if ($branch === '000' && $account) {
            $bc = preg_replace('/\D/', '', (string) ($account->branch_code ?? ''));
            if ($bc !== '') $branch = str_pad(substr($bc, -3), 3, '0', STR_PAD_LEFT);
        }

        $h['tin'] = $tin9 . $branch;
        return [$tin9, $branch, $h, $account];
    }


    // eBIRForms save-file → [key => value]  (same as old get_ebir_savefile.php)
    private function parseSaveFile(string $path): array
    {
        $content = (string) @file_get_contents($path);
        preg_match_all('/<div>(.*?)<\/div>/s', $content, $m);
        $data = [];
        foreach ($m[1] as $block) {
            if (preg_match('/^([a-zA-Z0-9:._]+)=/', $block, $km)) {
                $key = $km[1];
                $val = preg_replace('/^' . preg_quote($key, '/') . '=/', '', $block);
                $val = preg_replace('/' . preg_quote($key, '/') . '=$/', '', $val);
                $data[$key] = urldecode(trim($val));
            }
        }
        return $data;
    }
    private function fileFor(Request $request, string $name): string
    {
        [$tin9] = $this->tinParts($request);
        abort_unless(preg_match(self::FILE_RE, $name) && str_starts_with($name, $tin9), 404, 'File not found.');
        $path = $this->ebirPath('savefile') . '/' . $name;
        abort_unless(is_file($path), 404, 'File not found.');
        return $path;
    }

    private function net(string $p = ''): string
    {
        return "COALESCE({$p}net_of_vat,0)+COALESCE({$p}vat_exempt,0)+COALESCE({$p}non_vat,0)+COALESCE({$p}zero_rated,0)";
    }

    private function sales(array $ids, string $from, string $to)
    {
        return SalesInvoiceDetail::whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
            ->whereBetween('declared_month', [$from, $to]);
    }

    private function grn(array $ids, string $from, string $to)
    {
        return DB::table('t_grn_detail as d')
            ->join('t_grn_master as g', 'g.grn_no', '=', 'd.grn_no')
            ->whereIn('g.receive_by', $ids)
            ->whereBetween('g.declared_month', [$from, $to]);
    }

    private function ap(array $ids, string $from, string $to)
    {
        return PurchaseExpenseDetail::query()->active()
            ->whereIn('added_by', $ids)
            ->whereBetween('declared_month', [$from, $to]);
    }

    /* ======================= files ======================= */

    /* GET /api/bir/files?form=2550Qv2024 */
    public function files(Request $request)
    {
        $form = $request->validate(['form' => ['required', 'regex:/^[0-9A-Za-z]{3,15}$/']])['form'];
        [$tin9] = $this->tinParts($request);
        $dir = $this->ebirPath('savefile');

        if (!is_dir($dir)) {
            return response()->json(['files' => [], 'missing' => true, 'path' => $dir]);
        }

        $out = [];
        foreach (glob($dir . '/' . $tin9 . '*-' . $form . '-*') ?: [] as $file) {
            $parts = explode('-', basename($file, '.xml'));

            // strict: first 9 digits = TIN and exact form type
            if (substr($parts[0] ?? '', 0, 9) !== $tin9 || ($parts[1] ?? '') !== $form) continue;

            $mtime = filemtime($file);
            $out[] = [
                'filename'      => basename($file),
                'return_period' => $this->periodLabel($parts[2] ?? ''),
                'created'       => Carbon::createFromTimestamp($mtime, 'Asia/Manila')->format('m/d/Y H:i:s'),
                'mtime'         => $mtime,
            ];
        }
        usort($out, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        // FILE & SUBMITTED / PAID status of each file
        $filed = $this->filings((int) $this->addedByFor($request), array_column($out, 'filename'));
        foreach ($out as &$f) {
            $f['filing'] = $filed[$f['filename']] ?? null;
            $f['status'] = $f['filing']['status'] ?? 'not_filed';
        }
        unset($f);

        return response()->json(['files' => $out, 'missing' => false, 'path' => $dir]);
    }
    private function periodLabel(string $raw): string
    {
        // 0605: MMDDYYYYHHMMSS
        if (preg_match('/^(\d{2})(\d{2})(\d{4})(\d{2})(\d{2})(\d{2})$/', $raw, $m)) {
            return "{$m[1]}/{$m[2]}/{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        // 0619E / 1601C / 2550M: MMYYYY
        if (preg_match('/^(\d{2})(\d{4})$/', $raw, $m)) {
            return "{$m[1]}/{$m[2]}";
        }
        // 1601EQ: YYYYQn
        if (preg_match('/^(\d{4})Q(\d)$/', $raw, $m)) {
            return "Q{$m[2]} {$m[1]}";
        }
        // 2550Q / 2551Q: (M)MYYYYQn  – year-ended month + year + quarter
        if (preg_match('/^(\d{1,2})(\d{4})Q(\d)$/', $raw, $m)) {
            return "Q{$m[3]} · year ended " . str_pad($m[1], 2, '0', STR_PAD_LEFT) . "/{$m[2]}";
        }
        return $raw;
    }
    /* GET /api/bir/files/{name} – parsed values + raw content (for download) */
    public function readFile(Request $request, string $name)
    {
        $path = $this->fileFor($request, $name);
        return response()->json([
            'filename' => $name,
            'data'     => $this->parseSaveFile($path),
            'content'  => (string) file_get_contents($path),
        ]);
    }

    /* DELETE /api/bir/files/{name} */
    public function deleteFile(Request $request, string $name)
    {
        $path = $this->fileFor($request, $name);
        abort_if($this->filingOf($request, $name), 422, 'This return is already filed & submitted, so it can\'t be deleted.');
        abort_unless(@unlink($path), 500, "Couldn't delete the file.");
        return response()->json(['ok' => true]);
    }

    /* POST /api/bir/files  { filename, content } */
    public function saveFile(Request $request)
    {
        $d = $request->validate([
            'filename' => ['required', 'string', 'max:120', 'regex:' . self::FILE_RE],
            'content'  => ['required', 'string', 'max:2000000'],
        ], ['filename.regex' => 'Enter a complete TIN (numbers only) before saving.']);

        [$tin9] = $this->tinParts($request);
        abort_unless(str_starts_with($d['filename'], $tin9), 422, "The TIN on the form must be this client's TIN ({$tin9}).");

        $dir = $this->ebirPath('savefile');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) abort(500, "Can't create the folder {$dir}.");

        $path = $dir . '/' . $d['filename'];
        abort_if(is_file($path) && $this->filingOf($request, $d['filename']), 422, 'This return is already filed & submitted, so it can\'t be changed.');
        if (@file_put_contents($path, $d['content']) === false) abort(500, "Can't write to {$dir}.");

        return response()->json(['filename' => $d['filename'], 'path' => $path]);
    }

    /* ======================= profile / lookups ======================= */

    /* GET /api/bir/profile – eBIRForms profile, else the client account */
    public function profile(Request $request)
    {
        [$tin9, $branch, $h, $account] = $this->tinParts($request);

        $p = [
            'tin1' => substr($tin9, 0, 3), 'tin2' => substr($tin9, 3, 3), 'tin3' => substr($tin9, 6, 3), 'tin4' => $branch,
            'fn' => (string) ($h['trade_name'] ?? ''), 'regAddr' => (string) ($h['address'] ?? ''),
            'rdo' => '', 'zip' => '', 'lob' => '',
            'telNo' => (string) ($account->tell_num ?? ''), 'email' => (string) ($account->email_address ?? ''),
        ];

        $source = 'client';
        $file = $this->ebirPath('profile') . '/' . $tin9 . $branch . '.xml';
        if (is_file($file)) {
            $data = $this->parseSaveFile($file);
            if (!$data) {
                $json = json_decode((string) file_get_contents($file), true);
                $data = is_array($json) ? $json : [];
            }
            foreach (array_keys($p) as $k) {
                foreach ($data as $dk => $dv) {
                    $dk = strtolower((string) $dk);
                    if (($dk === strtolower($k) || str_ends_with($dk, ':' . strtolower($k))) && $dv !== '') $p[$k] = (string) $dv;
                }
            }
            $source = 'ebirforms';
        }

        return response()->json([
            'profile' => $p,
            'source'  => $source,
            'tin'     => $tin9 . $branch,
            'name'    => $h['trade_name'] ?? '',
            'address' => $h['address'] ?? '',
        ]);
    }

    /* GET /api/bir/atc-2551q */
    public function atc2551q()
    {
        return response()->json(DB::table('t_2551Q_atc')->orderBy('id')->get(['id', 'atc', 'description', 'tax_rate']));
    }

    /* ======================= amounts from the books ======================= */

    /* GET /api/bir/autofill?type=2550q&from=2026-10&to=2026-12 */
    public function autofill(Request $request)
    {
        $d = $request->validate([
            'type'   => ['required', 'in:vat_payable,bir2307,sum_atc,2550m,2550q,2551q,1601c,remit0619e'],
            'from'   => ['nullable', 'date_format:Y-m'],
            'to'     => ['nullable', 'date_format:Y-m'],
            'atc_id' => ['nullable', 'integer'],
            'months' => ['nullable', 'string', 'max:100'],
        ]);

        if ($d['type'] === 'remit0619e') {
            return response()->json($this->remit0619e($request, (string) ($d['months'] ?? '')));
        }

        [$ids] = $this->journalScope($request, 'all');   // old: MAIN = every branch
        $from = $d['from'] ?? now()->format('Y-m');
        $to   = $d['to'] ?? $from;
        if (!$ids) return response()->json(['value' => 0, 'rows' => []]);

        return response()->json(match ($d['type']) {
            'vat_payable' => ['value' => round($this->outputVat($ids, $from, $to) - $this->inputVat($ids, $from, $to), 2)],
            'bir2307'     => ['value' => round($this->ewt2307($ids, $from, $to), 2)],
            'sum_atc'     => ['rows' => $this->sumAtc($ids, $from, $to)],
            '2550m'       => $this->vatData($ids, $from, $to, false),
            '2550q'       => $this->vatData($ids, $from, $to, true),
            '2551q'       => $this->percentageData($ids, $from, $to, $d['atc_id'] ?? null),
            '1601c'       => ['total_comp' => round((float) $this->ap($ids, $from, $to)->where('account_code', '69400')->sum(DB::raw($this->net())), 2)],
        });
    }

    private function outputVat(array $ids, string $from, string $to): float
    {
        return (float) $this->sales($ids, $from, $to)->sum('input_vat');
    }

    private function inputVat(array $ids, string $from, string $to): float
    {
        return (float) $this->grn($ids, $from, $to)->sum('d.input_vat')
             + (float) $this->ap($ids, $from, $to)->sum('input_vat');
    }

    // EWT (2307 IT) on purchases/expenses + GRN, by 2307 declared month
    private function ewt2307(array $ids, string $from, string $to): float
    {
        $apMonth  = $this->col('t_purchase_expense_master', ['2307_it_declared_month', 'declared_month']);
        $grnMonth = $this->col('t_grn_master', ['2307_it_declared_month', 'declared_month']);

        return (float) PurchaseExpenseMaster::query()->active()->whereIn('added_by', $ids)
                ->where('2307_it', '<>', 0)->whereBetween($apMonth, [$from, $to])->sum('2307_it')
             + (float) GRNMaster::whereIn('receive_by', $ids)
                ->where('2307_it', '<>', 0)->whereBetween($grnMonth, [$from, $to])->sum('2307_it');
    }

    // 1601EQ ATC rows (old doGetSumATC)
    private function sumAtc(array $ids, string $from, string $to): array
    {
        $rows = [];
        $collect = function ($q, string $table) use (&$rows) {
            $base = $this->col($table, ['sub_total', 'grand_total']);
            $res = $q->groupBy('atc_it_id')
                ->selectRaw("atc_it_id AS atc, SUM(`2307_it`) AS tax, SUM(`{$base}`) AS base")
                ->toBase()->get();
            foreach ($res as $r) {
                $rows[$r->atc] ??= ['tax' => 0.0, 'base' => 0.0];
                $rows[$r->atc]['tax']  += (float) $r->tax;
                $rows[$r->atc]['base'] += (float) $r->base;
            }
        };

        $collect(
            PurchaseExpenseMaster::query()->active()->whereIn('added_by', $ids)->where('2307_it', '<>', 0)
                ->whereBetween($this->col('t_purchase_expense_master', ['2307_it_declared_month', 'declared_month']), [$from, $to]),
            't_purchase_expense_master'
        );
        $collect(
            GRNMaster::whereIn('receive_by', $ids)->where('2307_it', '<>', 0)
                ->whereBetween($this->col('t_grn_master', ['2307_it_declared_month', 'declared_month']), [$from, $to]),
            't_grn_master'
        );

        $atc = DB::table('m_atc')->whereIn('id', array_keys($rows))->get(['id', 'name', 'rate'])->keyBy('id');

        $out = [];
        foreach ($rows as $id => $r) {
            $out[] = [
                'atc'  => (string) ($atc[$id]->name ?? ''),
                'rate' => (float) ($atc[$id]->rate ?? 0),
                'base' => round($r['base'], 2),
                'tax'  => round($r['tax'], 2),
            ];
        }
        return array_slice($out, 0, 6);
    }

    // 2550M / 2550Q (old doGet2550M_All / doGet2550Q_All)
    private function vatData(array $ids, string $from, string $to, bool $quarterly): array
    {
        $ex = 'COALESCE(NULLIF(ex_rate,0),1)';
        $s = $this->sales($ids, $from, $to)->selectRaw(
            "SUM(COALESCE(net_of_vat,0)*{$ex}) AS net, SUM(COALESCE(zero_rated,0)*{$ex}) AS zero, SUM(COALESCE(vat_exempt,0)*{$ex}) AS exempt"
        )->toBase()->first();

        $purchNet = (float) $this->grn($ids, $from, $to)->sum('d.net_of_vat')
                  + (float) $this->ap($ids, $from, $to)->sum('net_of_vat');
        $purchExZero = (float) $this->grn($ids, $from, $to)->sum(DB::raw('COALESCE(d.vat_exempt,0)+COALESCE(d.zero_rated,0)'))
                     + (float) $this->ap($ids, $from, $to)->sum(DB::raw('COALESCE(vat_exempt,0)+COALESCE(zero_rated,0)'));

        $vtMonth = $this->col('t_sales_invoice_master', ['2307_vt_declared_month', 'declared_month']);
        $withheldQ = fn () => SalesInvoiceMaster::whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)
            ->where('2307_vt', '>', 0)->whereBetween($vtMonth, [$from, $to]);

        $out = [
            'net_of_vat'            => round((float) ($s->net ?? 0), 2),
            'zero_rated'            => round((float) ($s->zero ?? 0), 2),
            'vat_exempt'            => round((float) ($s->exempt ?? 0), 2),
            'purchases_net_of_vat'  => round($purchNet, 2),
            'importation_net_of_vat'=> 0,      // no importation table yet
            'purchases_exempt_zero' => round($purchExZero, 2),
            'vat_withheld'          => round((float) $withheldQ()->sum('2307_vt'), 2),
        ];
        if (!$quarterly) return $out;

        // Advance VAT payments: AP lines on "VAT Paid" accounts, up to the month before the quarter ends
        $codes  = ChartsOfAccount::whereIn('added_by', $ids)->where('account_name', 'like', '%vat paid%')->pluck('account_code')->unique();
        $toPrev = Carbon::createFromFormat('Y-m-d', "{$to}-01")->subMonth()->format('Y-m');
        $out['advance_vat'] = ($codes->isEmpty() || $toPrev < $from) ? 0 : round(
            (float) $this->ap($ids, $from, $toPrev)->whereIn('account_code', $codes)->sum(DB::raw($this->net() . '+COALESCE(input_vat,0)')), 2
        );

        // Schedule 3 – creditable VAT withheld, per month per customer
        $base = $this->col('t_sales_invoice_master', ['sub_total', 'grand_total']);
        $sched = $withheldQ()->groupBy($vtMonth, 'customer_id')
            ->selectRaw("`{$vtMonth}` AS m, customer_id, SUM(`2307_vt`) AS tax, SUM(`{$base}`) AS income")
            ->toBase()->get();
        $names = $this->partyNames($sched->pluck('customer_id'));

        $out['schedule3'] = $sched->map(function ($r) use ($names) {
            $d = Carbon::createFromFormat('Y-m-d', "{$r->m}-01");
            return [
                'm'      => $r->m,
                'from'   => $d->format('m/d/Y'),
                'to'     => $d->copy()->endOfMonth()->format('m/d/Y'),
                'name'   => (string) ($names[$r->customer_id] ?? ''),
                'income' => round((float) $r->income, 2),
                'tax'    => round((float) $r->tax, 2),
            ];
        })->sortBy([['m', 'asc'], ['name', 'asc']])->values();

        return $out;
    }

    // 2551Q (old doGet2551Q_All)
    private function percentageData(array $ids, string $from, string $to, ?int $atcId): array
    {
        $ex = 'COALESCE(NULLIF(ex_rate,0),1)';
        $gross = $this->col('t_sales_invoice_detail', ['amount'])
            ? 'COALESCE(amount,0)'
            : '(' . $this->net() . '+COALESCE(input_vat,0))';

        return [
            'amount' => round((float) $this->sales($ids, $from, $to)->sum(DB::raw("{$gross}*{$ex}")), 2),
            'rate'   => $atcId ? (float) DB::table('t_2551Q_atc')->where('id', $atcId)->value('tax_rate') : 0,
        ];
    }

    // 1601EQ items 20/21: item 18 of the saved 0619E files
    private function remit0619e(Request $request, string $months): array
    {
        [$tin9, $branch] = $this->tinParts($request);
        $out = [];
        foreach (array_filter(explode(',', $months)) as $mmYYYY) {
            if (!preg_match('/^\d{6}$/', $mmYYYY)) continue;
            $path = $this->ebirPath('savefile') . "/{$tin9}{$branch}-0619E-{$mmYYYY}.xml";
            $out[$mmYYYY] = is_file($path) ? ($this->parseSaveFile($path)['frm0619E:txtTax18'] ?? '0.00') : null;
        }
        return ['values' => $out];
    }

    /* =====================================================================
       FILE & SUBMITTED → Expenses bill · PAID → Pay Bill (t_pvoucher)   (ENTRY FOR BIR RETURN)
         t_bir_return_filing   one row per filed save file
         status per file: not_filed · unpaid · partial · paid
         a filed file can't be deleted or saved over
       ===================================================================== */
    private const FILING = 't_bir_return_filing';

    /** the books of the client being worked on (same as Sales / Expenses) */
    private function booksOf(Request $request): int
    {
        $by = (int) $this->addedByFor($request);
        abort_unless($by > 0, 422, 'No client account selected.');
        return $by;
    }

    /** filename → filing row + paid amount + status */
    private function filings(int $by, array $names): array
    {
        if (!$names || !Schema::hasTable(self::FILING)) return [];
        $rows = DB::table(self::FILING)->where('added_by', $by)->whereIn('filename', $names)->get()->keyBy('filename');
        if ($rows->isEmpty()) return [];
        $paid = Schema::hasTable('t_pvoucher_detail')
            ? DB::table('t_pvoucher_detail')->whereIn('ap_invoice_no', $rows->pluck('invoice_no')->filter()->all() ?: [''])
                ->groupBy('ap_invoice_no')->selectRaw('ap_invoice_no, SUM(amount) AS amt')->pluck('amt', 'ap_invoice_no')
            : collect();
        $void = DB::table('t_purchase_expense_master')->whereIn('invoice_no', $rows->pluck('invoice_no')->filter()->all() ?: [''])
            ->where('invoice_status_id', 5)->pluck('invoice_no')->flip();
        $out = [];
        foreach ($rows as $name => $r) {
            if (isset($void[$r->invoice_no])) continue;          // bill voided through a ticket → not filed
            $p = round((float) ($paid[$r->invoice_no] ?? 0), 2);
            $amt = round((float) $r->amount, 2);
            $out[$name] = [
                'status'     => $amt <= 0.009 || $p >= $amt - 0.009 ? 'paid' : ($p > 0 ? 'partial' : 'unpaid'),
                'invoice_no' => $r->invoice_no,
                'amount'     => $amt,
                'paid'       => $p,
                'balance'    => round(max(0, $amt - $p), 2),
                'filed_date' => $r->filed_date,
                'reconciled' => isset($r->reconciled) ? ($r->reconciled === null ? null : (bool) $r->reconciled) : null,
            ];
        }
        return $out;
    }

    private function filingOf(Request $request, string $name): ?array
    {
        return $this->filings($this->booksOf($request), [$name])[$name] ?? null;
    }

    /** save-file period → [from Y-m, to Y-m] */
    private function periodRange(string $raw): array
    {
        $ym = fn (int $y, int $m) => sprintf('%04d-%02d', $y, $m);
        if (preg_match('/^(\d{2})(\d{2})(\d{4})(\d{6})$/', $raw, $m)) return [$ym((int) $m[3], (int) $m[1]), $ym((int) $m[3], (int) $m[1])];   // 0605
        if (preg_match('/^(\d{2})(\d{4})$/', $raw, $m)) return [$ym((int) $m[2], (int) $m[1]), $ym((int) $m[2], (int) $m[1])];                  // monthly
        if (preg_match('/^(\d{4})Q(\d)$/', $raw, $m)) {                                                                                          // 1601EQ (calendar)
            $end = (int) $m[2] * 3;
            return [$ym((int) $m[1], $end - 2), $ym((int) $m[1], $end)];
        }
        if (preg_match('/^(\d{1,2})(\d{4})Q(\d)$/', $raw, $m)) {                                                                                 // 2550Q / 2551Q (year ended)
            $end = Carbon::create((int) $m[2], (int) $m[1], 1)->subMonths((4 - (int) $m[3]) * 3);
            return [$end->copy()->subMonths(2)->format('Y-m'), $end->format('Y-m')];
        }
        $now = now('Asia/Manila')->format('Y-m');
        return [$now, $now];
    }

    /** months between two Y-m (inclusive) */
    private function monthsOf(string $from, string $to): array
    {
        $out = [];
        for ($c = Carbon::createFromFormat('Y-m-d', "{$from}-01"); $c->format('Y-m') <= $to; $c->addMonth()) $out[] = $c->format('Y-m');
        return $out;
    }

    /** the client's chart of accounts */
    private function chartOf(int $by)
    {
        return ChartsOfAccount::where('added_by', $by)->orderBy('account_code')->get(['account_code', 'account_name'])
            ->map(fn ($a) => ['account_code' => (string) $a->account_code, 'account_name' => (string) $a->account_name])->values();
    }

    /** first account whose name matches (ignoring case); '' when none */
    private function accountLike($chart, array $patterns, array $not = []): string
    {
        foreach ($patterns as $re) {
            $hit = $chart->first(fn ($a) => preg_match($re, $a['account_name']) && !collect($not)->contains(fn ($n) => preg_match($n, $a['account_name'])));
            if ($hit) return $hit['account_code'];
        }
        return '';
    }

    /**
     * the entry of ENTRY FOR BIR RETURN — amounts from the saved return itself (the same items you filed);
     * books only when the file has no amounts. Everything can still be changed before saving.
     */
    private function entryTemplate(Request $request, string $form, string $from, string $to, $chart, array $file = []): array
    {
        $acc = fn (array $p, array $not = []) => $this->accountLike($chart, $p, $not);
        $line = fn (string $side, string $code, string $label, float $amt, bool $bill = false) =>
            ['side' => $side, 'account_code' => $code, 'label' => $label, 'amount' => round(max(0, $amt), 2), 'bill' => $bill];
        // the save file keeps "1,234.56" (sometimes with spaces / ₱) → number
        $num = fn (string $k) => (float) preg_replace('/[^0-9.\-]/', '', (string) ($file[$k] ?? '0'));
        $vatPayable = $acc(['/^vat payable/i', '/vat.*payable/i']);
        $ewtPayable = $acc(['/expanded.*withholding.*payable/i', '/withholding.*expanded.*payable/i', '/ewt.*payable/i']);
        $advVat = $acc(['/advance vat/i', '/vat paid/i']);
        $advEwt = $acc(['/advance.*expanded/i', '/advance.*ewt/i']);
        // surcharge + interest + compromise → one debit line (only when there are penalties)
        $penalty = fn (float $amt) => $amt > 0.004
            ? [$line('dr', $acc(['/penalt/i', '/surcharge/i', '/interest expense/i', '/fines/i']), 'Penalties (surcharge / interest / compromise)', $amt)] : [];
        $books = fn () => ($this->journalScope($request, 'all')[0] ?? []);

        if (str_starts_with($form, '2550Q')) {
            $V = 'frm2550qv2024:';
            $out = $num("{$V}totalAdjOutput") ?: $num("{$V}outputTaxDue");
            $in = $num("{$V}totalAllowInputTax");
            $wt = $num("{$V}creditableVat");
            $adv = $num("{$V}advVatPayment") + $num("{$V}vatPaidReturn") + $num("{$V}otherCreditsNo19");
            $pen = $num("{$V}penalties");
            $due = $num("{$V}excessCredits");
            if (!$out && !$in && ($ids = $books())) {
                $v = $this->vatData($ids, $from, $to, true);
                [$out, $in, $wt, $adv] = [$this->outputVat($ids, $from, $to), $this->inputVat($ids, $from, $to), (float) $v['vat_withheld'], (float) ($v['advance_vat'] ?? 0)];
                $due = $out - $in - $wt - $adv;
            }
            return [
                $line('dr', $acc(['/output vat/i']), 'Output VAT', $out), ...$penalty($pen),
                $line('cr', $acc(['/input vat/i'], ['/deferred/i']), 'Input VAT', $in),
                $line('cr', $acc(['/withholding tax.*vat/i', '/creditable.*vat/i', '/vat.*withheld/i']), 'Withholding Tax - VAT', $wt),
                $line('cr', $advVat, 'Advance VAT', $adv),
                $line('cr', $vatPayable, 'VAT Payable', $due + $pen, true),
            ];
        }
        if (str_starts_with($form, '2550M')) {
            $M = 'frm2550m:';
            $out = $num("{$M}txtTax16B");
            $in = $num("{$M}txtTax21");
            $wt = $num("{$M}txtTax23B") + $num("{$M}txtTax23C") + $num("{$M}txtTax23E");
            $adv = $num("{$M}txtTax23A") + $num("{$M}txtTax23D") + $num("{$M}txtTax23F");
            $pen = $num("{$M}txtTax25D");
            $due = $num("{$M}txtTax24");
            if (!$out && !$in && ($ids = $books())) {
                [$out, $in] = [$this->outputVat($ids, $from, $to), $this->inputVat($ids, $from, $to)];
                $wt = (float) $this->vatData($ids, $from, $to, false)['vat_withheld'];
                $due = $out - $in - $wt;
            }
            return [
                $line('dr', $acc(['/output vat/i']), 'Output VAT', $out), ...$penalty($pen),
                $line('cr', $acc(['/input vat/i'], ['/deferred/i']), 'Input VAT', $in),
                $line('cr', $acc(['/withholding tax.*vat/i', '/creditable.*vat/i', '/vat.*withheld/i']), 'Withholding Tax - VAT', $wt),
                $line('cr', $advVat, 'Advance VAT', $adv),
                $line('cr', $vatPayable, 'VAT Payable', $due + $pen, true),
            ];
        }
        if ($form === '0605') {
            $A = 'frm0605:';
            $tax = $num("{$A}txtTax19");
            $pen = $num("{$A}txtTax20D");
            if (!$tax && ($ids = $books())) $tax = $this->outputVat($ids, $from, $to) - $this->inputVat($ids, $from, $to);
            return [
                $line('dr', $advVat, 'Advance VAT', $tax), ...$penalty($pen),
                $line('cr', $vatPayable, 'VAT Payable', $tax + $pen, true),
            ];
        }
        if ($form === '0619E') {
            $E = 'frm0619E:';
            $tax = $num("{$E}txtTax16") ?: $num("{$E}txtTax14");
            $pen = $num("{$E}txtTax17D");
            if (!$tax && ($ids = $books())) $tax = $this->ewt2307($ids, $from, $to);
            return [
                $line('dr', $advEwt, 'Advance Expanded Withholding', $tax), ...$penalty($pen),
                $line('cr', $ewtPayable, 'Expanded Withholding Payable', $tax + $pen, true),
            ];
        }
        if ($form === '1601EQ') {
            $Q = 'frm1601EQ:';
            $total = $num("{$Q}txtTax19");
            $remit = $num("{$Q}txtTax24");
            $pen = $num("{$Q}txtTax29");
            $due = $num("{$Q}txtTax25");
            if (!$total && ($ids = $books())) {
                $total = $this->ewt2307($ids, $from, $to);
                $months = array_map(fn ($m) => substr($m, 5, 2) . substr($m, 0, 4), array_slice($this->monthsOf($from, $to), 0, 2));
                $remit = array_sum(array_map(fn ($v) => (float) str_replace(',', '', (string) $v), $this->remit0619e($request, implode(',', $months))['values'] ?? []));
                $due = $total - $remit;
            }
            return [
                $line('dr', $acc(['/^withholding tax$/i', '/withholding tax.*expanded/i', '/expanded withholding tax$/i'], ['/payable/i', '/advance/i']), 'Withholding Tax', $total), ...$penalty($pen),
                $line('cr', $advEwt, 'Advance Expanded Withholding', $remit),
                $line('cr', $ewtPayable, 'Expanded Withholding Payable', $due + $pen, true),
            ];
        }
        if (str_starts_with($form, '2551Q')) {
            $P = 'frm2551Qv2018:';
            $tax = $num("{$P}txt14");
            $credits = $num("{$P}txt18");
            $pen = $num("{$P}txt23");
            $due = $num("{$P}txt19");
            if (!$tax && ($ids = $books())) {
                $tax = round(($this->percentageData($ids, $from, $to, null)['amount'] ?? 0) * 0.03, 2);
                $due = $tax;
            }
            return [
                $line('dr', $acc(['/percentage tax/i'], ['/payable/i']), 'Percentage Tax', $tax), ...$penalty($pen),
                $line('cr', $acc(['/creditable.*percentage/i', '/advance.*percentage/i']), 'Creditable / paid percentage tax', $credits),
                $line('cr', $acc(['/percentage tax.*payable/i']), 'Percentage Tax Payable', $due + $pen, true),
            ];
        }
        if (str_starts_with($form, '1601C')) {
            $W = 'frm1601c:';
            $tax = $num("{$W}txtTax27");
            $prev = $num("{$W}txtTax30");
            $pen = $num("{$W}txtTax35");
            $due = $num("{$W}txtTax31");
            return [
                $line('dr', $acc(['/withholding tax.*compensation/i', '/compensation/i'], ['/payable/i']), 'Withholding Tax - Compensation', $tax), ...$penalty($pen),
                $line('cr', $acc(['/advance.*compensation/i']), 'Tax remitted previously', $prev),
                $line('cr', $acc(['/compensation.*payable/i', '/withholding.*payable/i']), 'Withholding Tax Payable - Compensation', $due + $pen, true),
            ];
        }
        return [$line('dr', '', 'Tax', 0), $line('cr', '', 'Tax Payable', 0, true)];
    }

    /**
     * return amounts vs books amounts, line by line (penalties are only on the return, so they're left out of the check)
     * → [lines with 'books' + 'match', status: reconciled | not_reconciled | no_books, difference]
     */
    private function reconcile(Request $request, string $form, string $from, string $to, $chart, array $lines): array
    {
        $books = collect($this->entryTemplate($request, $form, $from, $to, $chart, []))->keyBy('label');
        $hasBooks = $books->sum('amount') > 0 && !str_starts_with($form, '1601C');   // 1601C: no tax amount in the books
        $pen = collect($lines)->filter(fn ($l) => str_starts_with($l['label'], 'Penalties'))->sum('amount');
        $diff = 0.0;
        foreach ($lines as &$l) {
            if (str_starts_with($l['label'], 'Penalties') || !$hasBooks) { $l['books'] = null; $l['match'] = null; continue; }
            $ret = $l['bill'] ? $l['amount'] - $pen : $l['amount'];
            $b = round((float) ($books[$l['label']]['amount'] ?? 0), 2);
            $l['books'] = $b;
            $l['match'] = abs($ret - $b) < 0.01;
            if (!$l['match']) $diff += abs($ret - $b);
        }
        unset($l);
        $status = !$hasBooks ? 'no_books' : ($diff < 0.01 ? 'reconciled' : 'not_reconciled');
        return [$lines, $status, round($diff, 2)];
    }

    /* GET /api/bir/filing/{name}   the FILE & SUBMITTED entry to review (or the filing, if already filed) */
    public function filing(Request $request, string $name)
    {
        $path = $this->fileFor($request, $name);   // the file must exist and belong to this TIN
        $by = $this->booksOf($request);
        $parts = explode('-', basename($name, '.xml'));
        $form = $parts[1] ?? '';
        [$from, $to] = $this->periodRange($parts[2] ?? '');
        $chart = $this->chartOf($by);
        $data = $this->parseSaveFile($path);
        // check against the books on every line first, then leave out empty credits (no WT-VAT, no advance …) unless the books have an amount
        [$lines, $recon, $reconDiff] = $this->reconcile($request, $form, $from, $to, $chart, $this->entryTemplate($request, $form, $from, $to, $chart, $data));
        $lines = array_values(array_filter($lines, fn ($l) => $l['amount'] > 0 || $l['bill'] || $l['side'] === 'dr' || ($l['books'] ?? 0) > 0));
        // what kind of account each line needs (for "Add new" when the chart has none): 1 asset · 2 liability · 6 expense
        foreach ($lines as &$l) {
            $n = strtolower($l['label']);
            $l['kind'] = match (true) {
                str_contains($n, 'payable'), str_contains($n, 'output vat'), $n === 'withholding tax',
                str_contains($n, 'compensation') && !str_contains($n, 'previously') => 2,
                str_contains($n, 'penalt'), $n === 'percentage tax' => 6,
                default => 1,
            };
        }
        unset($l);
        // where the amounts came from: the saved return, else the books
        $taxKeys = array_filter(array_keys($data), fn ($k) => preg_match('/tax|vat|payable|txt\d/i', $k) && (float) preg_replace('/[^0-9.\-]/', '', (string) $data[$k]) != 0);

        return response()->json([
            'filename'       => $name,
            'reconcile'      => ['status' => $recon, 'difference' => $reconDiff],
            'source'         => $taxKeys ? 'file' : (array_sum(array_column($lines, 'amount')) > 0 ? 'books' : 'none'),
            'form'           => $form,
            'return_period'  => $this->periodLabel($parts[2] ?? ''),
            'period_from'    => $from,
            'period_to'      => $to,
            'declared_month' => $to,
            'date'           => now('Asia/Manila')->toDateString(),
            'filing'         => $this->filingOf($request, $name),
            'lines'          => $lines,
            'items'          => $items = $this->filingItems($by),
            'item_id'        => $this->defaultFilingItem($items),
            'accounts'       => $chart,
        ]);
    }

    /* POST /api/bir/filing/{name}   FILE & SUBMITTED → Expenses bill (unpaid) for the tax payable
       { date, declared_month, remarks, lines: [{ side: dr|cr, account_code, amount, bill }] }
       the "bill" credit is the Expenses account (what Pay Bill pays); other credits are saved as negative lines */
    public function fileReturn(Request $request, string $name)
    {
        abort_unless(Schema::hasTable(self::FILING), 422, 'Run the t_bir_return_filing migration first (php artisan migrate).');
        $this->fileFor($request, $name);
        $by = $this->booksOf($request);
        $d = $request->validate([
            'date'                 => ['required', 'date'],
            'declared_month'       => ['required', 'date_format:Y-m'],
            'remarks'              => ['nullable', 'string', 'max:255'],
            'item_id'              => ['required', 'integer', 'exists:m_items,id'],
            'lines'                => ['required', 'array', 'min:2', 'max:20'],
            'lines.*.side'         => ['required', 'in:dr,cr'],
            'lines.*.account_code' => ['required', 'string', 'max:15'],
            'lines.*.amount'       => ['required', 'numeric', 'min:0'],
            'lines.*.bill'         => ['nullable', 'boolean'],
        ], ['lines.*.account_code.required' => 'Pick an account on every line.', 'item_id.required' => 'Pick the item to use.', 'item_id.exists' => 'Pick the item to use.']);

        abort_if($this->filingOf($request, $name), 422, 'This return is already filed.');
        $caid = $this->caidFor($request);

        $lines = collect($d['lines'])->map(fn ($l) => ['side' => $l['side'], 'code' => trim($l['account_code']), 'amount' => round((float) $l['amount'], 2), 'bill' => (bool) ($l['bill'] ?? false)])
            ->filter(fn ($l) => $l['amount'] > 0)->values();
        $bills = $lines->where('bill', true);
        abort_unless($bills->count() === 1 && $bills->first()['side'] === 'cr', 422, 'Mark exactly one credit line as the bill (the tax payable).');
        $dr = round($lines->where('side', 'dr')->sum('amount'), 2);
        $cr = round($lines->where('side', 'cr')->sum('amount'), 2);
        abort_unless(abs($dr - $cr) < 0.01, 422, 'Debit (' . number_format($dr, 2) . ') and credit (' . number_format($cr, 2) . ') must be equal.');
        $known = ChartsOfAccount::where('added_by', $by)->whereIn('account_code', $lines->pluck('code')->unique()->all())->pluck('account_code')->all();
        $unknown = $lines->pluck('code')->unique()->diff($known);
        abort_if($unknown->isNotEmpty(), 422, 'Not in the chart of accounts: ' . $unknown->implode(', '));

        $bill = $bills->first();
        $parts = explode('-', basename($name, '.xml'));
        $form = $parts[1] ?? '';
        [$from, $to] = $this->periodRange($parts[2] ?? '');
        $label = $this->periodLabel($parts[2] ?? '');

        // reconciled = the return's amounts are the same as the books (checked again when filing)
        $chart = $this->chartOf($by);
        $retLines = $this->entryTemplate($request, $form, $from, $to, $chart, $this->parseSaveFile($this->fileFor($request, $name)));
        [, $recon] = $this->reconcile($request, $form, $from, $to, $chart, $retLines);
        $reconciled = $recon === 'no_books' ? null : $recon === 'reconciled';

        return DB::transaction(function () use ($request, $d, $by, $caid, $lines, $bill, $name, $form, $from, $to, $label, $reconciled) {
            $M = 't_purchase_expense_master';
            $D = 't_purchase_expense_detail';
            $mCols = Schema::getColumnListing($M);
            $dCols = Schema::getColumnListing($D);
            $only = fn (array $cols, array $row) => array_intersect_key($row, array_flip($cols));
            $account = $caid ? \App\Models\ClientAccount::find($caid) : null;
            $status = fn (string $n) => (int) (DB::table('m_invoice_status')->whereRaw('UPPER(name) LIKE ?', [$n . '%'])->value('id') ?: ($n === 'PAID' ? 1 : 2));
            $audit = Schema::hasTable('m_audit_status') ? DB::table('m_audit_status')->whereRaw("UPPER(name) LIKE 'PENDING%'")->value('id') : null;
            $vendor = Schema::hasTable('m_vendor_customer')
                ? DB::table('m_vendor_customer')->when(Schema::hasColumn('m_vendor_customer', 'added_by'), fn ($q) => $q->where('added_by', $by))
                    ->where(fn ($q) => $q->where('name', 'like', '%INTERNAL REVENUE%')->orWhere('name', 'BIR'))->value('id')
                : null;
            $statusId = \App\Support\PaymentAccounts::isCash($by, $bill['code']) ? $status('PAID') : $status('UNPAID');

            do {
                $no = 'AP-' . date('ymd-his') . random_int(10000, 99999);
            } while (DB::table($M)->where('invoice_no', $no)->exists());

            $itemId = (int) $d['item_id'];
            $docNo = 'BIR ' . $form . ' ' . $label;
            $master = [
                'vendor_id' => $vendor ?: null, 'added_by' => $by, 'transaction_date' => $d['date'], 'declared_month' => $d['declared_month'],
                'account_code' => $bill['code'], 'document_no' => mb_substr($docNo, 0, 100), 'invoice_no' => $no, 'currency' => 'PHP', 'ex_rate' => 1,
                'sub_total' => $bill['amount'], 'total' => $bill['amount'], 'grand_total' => $bill['amount'], 'discount_amount' => 0,
                '2307_vt' => 0, '2307_it' => 0,
                'remarks' => mb_substr($d['remarks'] ?: "FILE & SUBMITTED · {$name}", 0, 255),
                'is_scanned' => 0, 'invoice_status_id' => $statusId, 'audit_status_id' => $audit,
                'assigned_daid' => $account->assigned_daid ?? null, 'assigned_auid' => $account->assigned_auid ?? null,
                'seq_no' => (int) DB::table($M)->where('added_by', $by)->lockForUpdate()->max('seq_no') + 1,
                'trans_type' => 'Expenses', 'record_status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table($M)->insert($only($mCols, $master));

            // debits as lines; credits other than the bill as negative lines → lines add up to the bill
            foreach ($lines->reject(fn ($l) => $l['bill']) as $l) {
                $amt = $l['side'] === 'dr' ? $l['amount'] : -$l['amount'];
                DB::table($D)->insert($only($dCols, [
                    'invoice_no' => $no, 'item_id' => $itemId, 'quantity' => 1, 'unit_price' => $amt, 'amount' => $amt, 'discount_amount' => 0,
                    'input_vat' => 0, 'net_of_vat' => 0, 'vat_exempt' => 0, 'non_vat' => $amt, 'zero_rated' => 0,
                    'account_code' => $l['code'], 'document_no' => $master['document_no'], 'declared_month' => $d['declared_month'],
                    'currency' => 'PHP', 'ex_rate' => 1, 'added_by' => $by, 'vendor_id' => $master['vendor_id'],
                    'transaction_date' => $d['date'], 'invoice_status_id' => $statusId, 'audit_status_id' => $audit,
                    'trans_type' => 'Expenses', 'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            DB::table(self::FILING)->insert([
                'added_by' => $by, 'filename' => $name, 'form' => $form, 'return_period' => $label, 'period_from' => $from, 'period_to' => $to,
                'invoice_no' => $no, 'amount' => $bill['amount'], 'filed_date' => $d['date'],
                'filed_by' => $request->user()->id ?? null, 'created_at' => now(), 'updated_at' => now(),
            ] + (Schema::hasColumn(self::FILING, 'reconciled') ? ['reconciled' => $reconciled] : []));

            return response()->json(['message' => "Filed & submitted · Expenses {$no} (" . number_format($bill['amount'], 2) . ' to pay)', 'invoice_no' => $no]);
        });
    }

    /** the client's items (m_items) for the Expenses lines: [[id, name]] */
    private function filingItems(int $by)
    {
        $name = collect(['name', 'item_name', 'description'])->first(fn ($c) => Schema::hasColumn('m_items', $c)) ?? 'id';
        $mine = Schema::hasColumn('m_items', 'added_by');
        $rows = DB::table('m_items')->when($mine, fn ($q) => $q->where('added_by', $by))->orderBy('id')->get(['id', DB::raw("{$name} AS name")]);
        if ($rows->isEmpty() && $mine) $rows = DB::table('m_items')->orderBy('id')->get(['id', DB::raw("{$name} AS name")]);
        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => trim((string) $r->name)])->values();
    }

    /** default item: VARIOUS EXPENSES - VAT (names differ per client: "VARIOUS EXPENSE VT", "Various Expenses-VAT" …); null = let the user pick */
    private function defaultFilingItem($items): ?int
    {
        $norm = fn ($v) => strtoupper(preg_replace('/[^A-Z]/i', '', (string) $v));
        $hit = $items->first(fn ($i) => preg_match('/^VARIOUSEXPENSES?(VAT|VT)$/', $norm($i['name'])));
        return $hit['id'] ?? null;
    }

    /* POST /api/bir/accounts  { account_name, kind: 1|2|6 }   "Add new" chart of account from the File & submit window
       the new account copies an existing posting account of the same kind (type, parent …) and takes the next free code */
    public function addAccount(Request $request)
    {
        $by = $this->booksOf($request);
        $d = $request->validate([
            'account_name' => ['required', 'string', 'max:150'],
            'kind'         => ['required', 'in:1,2,6'],
        ], ['account_name.required' => 'Enter the account name.']);
        $name = trim($d['account_name']);
        $T = 'm_charts_of_account';
        $cols = Schema::getColumnListing($T);

        $have = DB::table($T)->where('added_by', $by)->whereRaw('LOWER(TRIM(account_name)) = ?', [mb_strtolower($name)])->first();
        if ($have) return response()->json(['account_code' => (string) $have->account_code, 'account_name' => (string) $have->account_name, 'message' => 'Already in the chart of accounts.']);

        // a posting (not header) account of the same kind to copy type / parent from
        $group = collect(['is_group', 'is_header', 'isGroup'])->first(fn ($c) => in_array($c, $cols, true));
        $tpl = DB::table($T)->where('added_by', $by)->where('account_code', 'like', $d['kind'] . '%')
            ->when($group, fn ($q) => $q->where(fn ($w) => $w->whereNull($group)->orWhere($group, 0)))
            ->orderByDesc('account_code')->first();
        abort_unless($tpl, 422, 'There is no ' . ['1' => 'asset', '2' => 'liability', '6' => 'expense'][$d['kind']] . ' account to copy from yet. Add it in Setup → Charts of Account.');

        // next free code after the last one of the same group (e.g. 21900 → 21910)
        $base = (int) $tpl->account_code;
        $code = $base + 10;
        while (DB::table($T)->where('added_by', $by)->where('account_code', (string) $code)->exists()) $code += 10;

        $row = (array) $tpl;
        unset($row['id']);
        $row['account_code'] = (string) $code;
        $row['account_name'] = mb_substr($name, 0, 150);
        foreach (['description', 'remarks'] as $c) if (array_key_exists($c, $row)) $row[$c] = null;
        foreach (['balance', 'beginning_balance', 'beg_balance', 'opening_balance'] as $c) if (array_key_exists($c, $row)) $row[$c] = 0;
        if (array_key_exists('created_at', $row)) $row['created_at'] = now();
        if (array_key_exists('updated_at', $row)) $row['updated_at'] = now();
        DB::table($T)->insert(array_intersect_key($row, array_flip($cols)));

        return response()->json(['account_code' => (string) $code, 'account_name' => $row['account_name'], 'message' => "{$code} – {$row['account_name']} added to Charts of Account"]);
    }
}