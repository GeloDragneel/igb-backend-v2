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
        $t = 't_sales_invoice_detail';
        return SalesInvoiceDetail::whereIn("{$t}.added_by", $ids)
            ->where("{$t}.invoice_status_id", '<>', 5)->where("{$t}.trans_type", 'posted')
            ->whereRaw($this->effMonth("{$t}.invoice_no", "{$t}.declared_month") . ' BETWEEN ? AND ?', [$from, $to]);
    }

    private function grn(array $ids, string $from, string $to)
    {
        return DB::table('t_grn_detail as d')
            ->join('t_grn_master as g', 'g.grn_no', '=', 'd.grn_no')
            ->where('g.invoice_status_id', '<>', 5)
            ->whereIn('d.receive_by', $ids)
            ->whereRaw($this->effMonth('d.grn_no', 'd.declared_month', 'g.ref_data') . ' BETWEEN ? AND ?', [$from, $to]);
    }

    private function ap(array $ids, string $from, string $to, ?array $types = null)
    {
        $t = 't_purchase_expense_detail';
        $q = PurchaseExpenseDetail::query()
            ->whereIn("{$t}.added_by", $ids)
            ->where("{$t}.invoice_status_id", '<>', 5)
            ->whereRaw($this->effMonth("{$t}.invoice_no", "{$t}.declared_month") . ' BETWEEN ? AND ?', [$from, $to]);
        if ($this->col($t, ['record_status'])) $q->where("{$t}.record_status", 'posted');
        $mStatus = $this->col('t_purchase_expense_master', ['record_status']);
        if ($mStatus) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('t_purchase_expense_master as PM')
                ->whereColumn('PM.invoice_no', "{$t}.invoice_no")->where("PM.{$mStatus}", 'posted'));
        }
        if ($types) {
            $dType = $this->col($t, ['trans_type', 'payment_type']);
            $dType
                ? $q->whereIn("{$t}.{$dType}", $types)
                : $q->whereExists(fn ($e) => $e->selectRaw('1')->from('t_purchase_expense_master as PT')
                    ->whereColumn('PT.invoice_no', "{$t}.invoice_no")->whereIn('PT.trans_type', $types));
        }
        return $q;
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
            // Reconciled is checked again now against the CURRENT books (data changed after filing → Not reconciled)
            if ($f['filing']) $f['filing']['reconciled'] = $this->liveReconciled($request, $f['filename']);
        }
        unset($f);

        return response()->json(['files' => $out, 'missing' => false, 'path' => $dir]);
    }
    private function periodLabel(string $raw): string
    {
        // annual returns (1701 / 1702RT / 1702EX / 1604C / 1604E): FY + year-ended MM + YYYY
        if (preg_match('/^FY(\d{2})(\d{4})$/', $raw, $m)) {
            return $m[1] === '12' ? "Year {$m[2]}" : "Year ended {$m[1]}/{$m[2]}";
        }
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
            'profile'    => $p,
            'compliance' => $this->clientCompliance($account),   // returns ticked in Client → Classification & Compliance (null = not set up)
            'source'     => $source,
            'tin'     => $tin9 . $branch,
            'name'    => $h['trade_name'] ?? '',
            'address' => $h['address'] ?? '',
        ]);
    }

    /**
     * compliance codes of the client of this account: m_client.compliance_ids → m_compliance names → ['0605', '1601EQ', '2550M', …]
     * null when the tables / columns don't exist (the page then shows every return)
     */
    private function clientCompliance($account): ?array
    {
        if (!$account || !Schema::hasTable('m_client')) return null;
        $col = $this->col('m_client', ['compliance_ids', 'compliance_id']);
        $table = collect(['m_compliance', 'm_compliances'])->first(fn ($t) => Schema::hasTable($t));
        $name = $table ? $this->col($table, ['compliance', 'name', 'description']) : null;
        if (!$col || !$name) return null;

        $clientNum = DB::table('m_client_account')->where('id', $account->id)->value('client_num');
        if (!$clientNum) return [];
        $raw = (string) DB::table('m_client')->where('client_num', $clientNum)->value($col);
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw)))));
        if (!$ids) return [];

        return DB::table($table)->whereIn('id', $ids)->pluck($name)
            ->map(function ($n) {
                $n = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $n));            // "1601-EQ" → 1601EQ
                return preg_match('/\d{4}(?:RT|EX|EQ|[A-Z])?/', $n, $m) ? $m[0] : $n;   // "BIR FORM 2550Q" → 2550Q
            })
            ->filter()->unique()->values()->all();
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
            'type'   => ['required', 'in:vat_payable,bir2307,sum_atc,2550m,2550q,2551q,1601c,remit0619e,income,ewt_months,saved_months'],
            'from'   => ['nullable', 'date_format:Y-m'],
            'to'     => ['nullable', 'date_format:Y-m'],
            'atc_id' => ['nullable', 'integer'],
            'months' => ['nullable', 'string', 'max:100'],
            'form'   => ['nullable', 'regex:/^[0-9A-Za-z]{3,15}$/'],
            'year'   => ['nullable', 'digits:4'],
            'keys'   => ['nullable', 'string', 'max:500'],
        ]);

        if ($d['type'] === 'remit0619e') {
            return response()->json($this->remit0619e($request, (string) ($d['months'] ?? '')));
        }
        if ($d['type'] === 'saved_months') {
            return response()->json($this->savedMonths($request, (string) ($d['form'] ?? ''), (int) ($d['year'] ?? 0), (string) ($d['keys'] ?? '')));
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
            'income'      => $this->incomeData($ids, $from, $to),
            'ewt_months'  => ['values' => collect($this->monthsOf($from, $to))->mapWithKeys(fn ($m) => [$m => round($this->ewt2307($ids, $m, $m), 2)])->all()],
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
 
        // E-BIR: Purchases (receive inventory + AP Purchases) + Expenses — only the Purchases / Expenses lines
        $pe = ['Purchases', 'Expenses'];
        $purchNet = (float) $this->grn($ids, $from, $to)->sum('d.net_of_vat')
                  + (float) $this->ap($ids, $from, $to, $pe)->sum('net_of_vat');
        $purchExZero = (float) $this->grn($ids, $from, $to)->sum(DB::raw('COALESCE(d.vat_exempt,0)+COALESCE(d.zero_rated,0)'))
                     + (float) $this->ap($ids, $from, $to, $pe)->sum(DB::raw('COALESCE(vat_exempt,0)+COALESCE(zero_rated,0)'));
 
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

    /**
     * income tax returns (1701Q / 1701 / 1702Q / 1702RT / 1702EX): amounts from the books
     *   sales = posted sales (net of VAT) · cost = accounts 5… (Receive Inventory + Purchases) · expenses = accounts 6… and 8…
     *   cwt = creditable tax withheld by customers (2307 income tax on sales), by its 2307 declared month
     */
    private function incomeData(array $ids, string $from, string $to): array
    {
        $t = 't_sales_invoice_detail';
        $rate = $this->col($t, ['ex_rate']) ? " * COALESCE(NULLIF({$t}.ex_rate,0),1)" : '';
        $sales = (float) $this->sales($ids, $from, $to)->sum(DB::raw('(' . $this->net("{$t}.") . "){$rate}"));

        $pe = 't_purchase_expense_detail';
        $cost = (float) $this->grn($ids, $from, $to)->where('d.account_code', 'like', '5%')->sum(DB::raw($this->net('d.')))
              + (float) $this->ap($ids, $from, $to)->where("{$pe}.account_code", 'like', '5%')->sum(DB::raw($this->net("{$pe}.")));
        $expenses = (float) $this->ap($ids, $from, $to)
            ->where(fn ($q) => $q->where("{$pe}.account_code", 'like', '6%')->orWhere("{$pe}.account_code", 'like', '8%'))
            ->sum(DB::raw($this->net("{$pe}.")));

        $cwtMonth = $this->col('t_sales_invoice_master', ['2307_it_declared_month', 'declared_month']);
        $cwt = (float) SalesInvoiceMaster::whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
            ->where('2307_it', '>', 0)->whereBetween($cwtMonth, [$from, $to])->sum('2307_it');

        return ['sales' => round($sales, 2), 'cost' => round($cost, 2), 'expenses' => round($expenses, 2), 'cwt' => round($cwt, 2)];
    }

    /**
     * values of some keys from this client's saved monthly returns of one year (e.g. 1601Cv2018 → 1604-C)
     * → ['values' => ['01' => [key => value], …]]  (a month without a saved file is left out)
     */
    private function savedMonths(Request $request, string $form, int $year, string $keys): array
    {
        if (!$form || $year < 2000) return ['values' => []];
        [$tin9, $branch] = $this->tinParts($request);
        $want = array_values(array_filter(array_map('trim', explode(',', $keys))));
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $path = $this->ebirPath('savefile') . "/{$tin9}{$branch}-{$form}-{$mm}{$year}.xml";
            if (!is_file($path)) continue;
            $data = $this->parseSaveFile($path);
            $out[$mm] = array_intersect_key($data, array_flip($want));
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
        // new filings: a General Journal entry (jv_no) + payments in t_bir_return_payment
        $isGj = fn ($r) => !empty($r->jv_no ?? null);
        $gjRows = $rows->filter($isGj);
        $gjAlive = $gjRows->isEmpty() ? collect() : DB::table('t_general_journal')->where('added_by', $by)
            ->whereIn('jv_no', $gjRows->pluck('jv_no')->all())->distinct()->pluck('jv_no')->flip();
        $gjPaid = $gjRows->isEmpty() || !Schema::hasTable(self::PAYMENT) ? collect() : DB::table(self::PAYMENT)
            ->whereIn('filing_id', $gjRows->pluck('id')->all())->groupBy('filing_id')->selectRaw('filing_id, SUM(amount) AS amt')->pluck('amt', 'filing_id');
        $paid = Schema::hasTable('t_pvoucher_detail')
            ? DB::table('t_pvoucher_detail')->whereIn('ap_invoice_no', $rows->pluck('invoice_no')->filter()->all() ?: [''])
                ->groupBy('ap_invoice_no')->selectRaw('ap_invoice_no, SUM(amount) AS amt')->pluck('amt', 'ap_invoice_no')
            : collect();
        $void = DB::table('t_purchase_expense_master')->whereIn('invoice_no', $rows->pluck('invoice_no')->filter()->all() ?: [''])
            ->where('invoice_status_id', 5)->pluck('invoice_no')->flip();
        $out = [];
        foreach ($rows as $name => $r) {
            if ($isGj($r)) {
                if (!isset($gjAlive[$r->jv_no])) continue;       // journal entry deleted → not filed
                $p = round((float) ($gjPaid[$r->id] ?? 0), 2);
            } else {
                if (isset($void[$r->invoice_no])) continue;      // (old) Expenses bill voided through a ticket → not filed
                $p = round((float) ($paid[$r->invoice_no] ?? 0), 2);
            }
            $amt = round((float) $r->amount, 2);
            $out[$name] = [
                'status'     => $amt <= 0.009 || $p >= $amt - 0.009 ? 'paid' : ($p > 0 ? 'partial' : 'unpaid'),
                'invoice_no' => $r->invoice_no,
                'jv_no'      => $r->jv_no ?? null,
                'kind'       => $isGj($r) ? 'journal' : 'expenses',   // journal = General Journal + BIR payments · expenses = old Pay Bill
                'amount'     => $amt,
                'paid'       => $p,
                'balance'    => round(max(0, $amt - $p), 2),
                'filed_date' => $r->filed_date,
                'reconciled' => isset($r->reconciled) ? ($r->reconciled === null ? null : (bool) $r->reconciled) : null,
            ];
        }
        return $out;
    }

    /** the return's amounts vs the books right now: true = same · false = different · null = nothing in the books to compare */
    private function liveReconciled(Request $request, string $name): ?bool
    {
        try {
            $path = $this->ebirPath('savefile') . '/' . $name;
            if (!is_file($path)) return null;
            $parts = explode('-', basename($name, '.xml'));
            $form = $parts[1] ?? '';
            [$from, $to] = $this->periodRange($parts[2] ?? '');
            $chart = $this->chartOf($this->booksOf($request));
            $lines = $this->entryTemplate($request, $form, $from, $to, $chart, $this->parseSaveFile($path));
            [, $status] = $this->reconcile($request, $form, $from, $to, $chart, $lines);
            $live = $status === 'no_books' ? null : $status === 'reconciled';

            // keep the saved flag up to date too
            if (Schema::hasColumn(self::FILING, 'reconciled')) {
                DB::table(self::FILING)->where('added_by', $this->booksOf($request))->where('filename', $name)
                    ->update(['reconciled' => $live, 'updated_at' => now()]);
            }
            return $live;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function filingOf(Request $request, string $name): ?array
    {
        return $this->filings($this->booksOf($request), [$name])[$name] ?? null;
    }

    /** save-file period → [from Y-m, to Y-m] */
    private function periodRange(string $raw): array
    {
        $ym = fn (int $y, int $m) => sprintf('%04d-%02d', $y, $m);
        if (preg_match('/^FY(\d{2})(\d{4})$/', $raw, $m)) {                                                                                      // annual: 12 months up to year end
            $end = Carbon::create((int) $m[2], (int) $m[1], 1);
            return [$end->copy()->subMonths(11)->format('Y-m'), $end->format('Y-m')];
        }
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
        // income tax returns: DR income tax expense · CR creditable withholding tax / prepaid income tax · CR income tax payable (to pay)
        $itx = [
            '1701Q'  => ['frm1701q:', ['s3PrevCwt', 's3Cwt']],
            '1701'   => ['frm1701:', ['cCwtQ13', 'cCwtQ4', 'cComp']],
            '1702Q'  => ['frm1702q:', ['cPrevCwt', 'cCwt']],
            '1702RT' => ['frm1702rt:', ['cCwtQ13', 'cCwtQ4']],
        ];
        if (isset($itx[$form])) {
            [$X, $cwtKeys] = $itx[$form];
            $due = $num("{$X}taxDue");
            $credits = $num("{$X}credits");
            $cwt = array_sum(array_map(fn ($k) => $num($X . $k), $cwtKeys));
            $pen = $num("{$X}penalties");
            $pay = $num("{$X}payable");
            return [
                $line('dr', $acc(['/^income tax expense/i', '/provision for income tax/i', '/income tax.*current/i', '/^income tax$/i'], ['/payable/i', '/deferred/i']), 'Income Tax Expense', $due),
                ...$penalty($pen),
                $line('cr', $acc(['/creditable withholding tax/i', '/^cwt/i', '/withholding tax.*receivable/i', '/prepaid.*withholding/i'], ['/payable/i', '/vat/i']), 'Creditable Withholding Tax', $cwt),
                $line('cr', $acc(['/prepaid income tax/i', '/advance.*income tax/i', '/income tax.*prepaid/i']), 'Prepaid Income Tax', $credits - $cwt),
                $line('cr', $acc(['/income tax payable/i', '/income tax.*payable/i']), 'Income Tax Payable', $pay + $pen, true),
            ];
        }
        if ($form === '2200M') {
            $X = 'frm2200m:';
            $pen = $num("{$X}penalties");
            return [
                $line('dr', $acc(['/^excise tax/i', '/excise tax expense/i'], ['/payable/i']), 'Excise Tax', $num("{$X}taxDue")), ...$penalty($pen),
                $line('cr', $acc(['/advance.*excise/i', '/excise.*paid/i']), 'Excise tax paid previously', $num("{$X}credits")),
                $line('cr', $acc(['/excise tax payable/i', '/excise.*payable/i']), 'Excise Tax Payable', $num("{$X}payable") + $pen, true),
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
                str_contains($n, 'penalt'), $n === 'percentage tax', $n === 'income tax expense', $n === 'excise tax' => 6,
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
            'item_id'              => ['nullable', 'integer'],   // not used any more (General Journal has no items)
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

        abort_unless(Schema::hasColumn(self::FILING, 'jv_no'), 422, 'Run the BIR General Journal migration first (php artisan migrate).');

        return DB::transaction(function () use ($request, $d, $by, $lines, $bill, $name, $form, $from, $to, $label, $parts, $reconciled) {
            // reference: BIR-2550Q-122026Q4 (unique for this client)
            $base = 'BIR-' . $this->birFolder($form) . '-' . ($parts[2] ?? date('ymd'));
            $jv = $base;
            for ($n = 2; DB::table('t_general_journal')->where('added_by', $by)->where('jv_no', $jv)->exists(); $n++) $jv = "{$base}-{$n}";

            $remarks = mb_substr($d['remarks'] ?: "BIR {$form} {$label} · FILE & SUBMITTED", 0, 255);
            $this->gjInsert($request, $by, $jv, $d['date'], $d['declared_month'], $lines->map(fn ($l) => [
                'account_code' => $l['code'],
                'debit'  => $l['side'] === 'dr' ? $l['amount'] : 0,
                'credit' => $l['side'] === 'cr' ? $l['amount'] : 0,
                'remarks' => $l['bill'] ? mb_substr("{$remarks} · to pay", 0, 255) : $remarks,
            ])->all());

            DB::table(self::FILING)->insert(array_intersect_key([
                'added_by' => $by, 'filename' => $name, 'form' => $form, 'return_period' => $label, 'period_from' => $from, 'period_to' => $to,
                'invoice_no' => $jv, 'jv_no' => $jv, 'bill_account' => $bill['code'], 'amount' => $bill['amount'], 'filed_date' => $d['date'],
                'filed_by' => $request->user()->id ?? null, 'reconciled' => $reconciled, 'created_at' => now(), 'updated_at' => now(),
            ], array_flip(Schema::getColumnListing(self::FILING))));

            return response()->json(['message' => "Filed & submitted · General Journal {$jv} (" . number_format($bill['amount'], 2) . ' to pay)', 'jv_no' => $jv]);
        });
    }

    /* =====================================================================
       BIR return PAYMENTS (instead of Pay Bill)
         each payment = a General Journal entry: Dr <tax payable> · Cr <cash / bank>
         + a row in t_bir_return_payment · attachment → S3 BIR_Return/<form>/
       ===================================================================== */
    private const PAYMENT = 't_bir_return_payment';

    /** S3 folder / short code of a form: 2550Qv2024 → 2550Q, 2551Qv2018 → 2551Q */
    private function birFolder(string $form): string
    {
        foreach (['0605', '0619E', '1601EQ', '1601C', '1604C', '1604E', '1701Q', '1701', '1702Q', '1702RT', '1702EX', '2200M', '2550M', '2550Q', '2551Q'] as $f) {
            if (str_starts_with(strtoupper($form), $f)) return $f;
        }
        return preg_replace('/[^0-9A-Za-z]/', '', $form) ?: 'Other';
    }

    /** lines → t_general_journal (same columns as Task → General Journal) */
    private function gjInsert(Request $request, int $by, string $jv, string $date, string $month, array $lines): void
    {
        $hasType = Schema::hasColumn('t_general_journal', 'entry_type');
        foreach ($lines as $l) {
            if ((float) $l['debit'] <= 0 && (float) $l['credit'] <= 0) continue;
            DB::table('t_general_journal')->insert([
                'jv_no' => $jv, 'transaction_date' => $date, 'declared_month' => $month,
                'account_code' => $l['account_code'], 'remarks' => $l['remarks'] ?? null,
                'debit' => round((float) $l['debit'], 2), 'credit' => round((float) $l['credit'], 2),
                'subsidiary' => null, 'added_by' => $by,
                'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
            ] + ($hasType ? ['entry_type' => 'NORMAL'] : []));
        }
    }

    /** the filing (General Journal kind) of this file, or 404.
        A return filed the OLD way (Expenses bill, nothing paid yet) is moved to the General Journal here, automatically. */
    private function gjFiling(Request $request, string $name): object
    {
        abort_unless(Schema::hasTable(self::PAYMENT) && Schema::hasColumn(self::FILING, 'jv_no'), 422, 'Run the BIR General Journal migration first (php artisan migrate).');
        $by = $this->booksOf($request);
        $f = DB::table(self::FILING)->where('added_by', $by)->where('filename', $name)->first();
        abort_unless($f, 404, 'This return is not filed yet.');
        if (empty($f->jv_no)) $f = $this->moveToJournal($request, $by, $f);
        return $f;
    }

    /** old filing (Expenses bill AP-…) → General Journal entry; only while nothing is paid on the bill */
    private function moveToJournal(Request $request, int $by, object $f): object
    {
        $M = 't_purchase_expense_master';
        $D = 't_purchase_expense_detail';
        $bill = DB::table($M)->where('added_by', $by)->where('invoice_no', $f->invoice_no)->first();
        abort_unless($bill, 404, 'The old Expenses bill of this return was not found.');
        $paid = Schema::hasTable('t_pvoucher_detail') ? (float) DB::table('t_pvoucher_detail')->where('ap_invoice_no', $bill->invoice_no)->sum('amount') : 0;
        abort_if($paid > 0.009, 409, 'This return already has Pay Bill payments, so it stays on Pay Bill.');

        // the bill: lines = debits (positive) / other credits (negative) · the bill itself = credit on its account
        $lines = DB::table($D)->where('invoice_no', $bill->invoice_no)->get(['account_code', 'amount'])
            ->map(fn ($l) => ['account_code' => $l->account_code, 'debit' => max(0, (float) $l->amount), 'credit' => max(0, -(float) $l->amount)])
            ->filter(fn ($l) => $l['account_code'] && ($l['debit'] > 0 || $l['credit'] > 0))->values()->all();
        $lines[] = ['account_code' => $bill->account_code, 'debit' => 0, 'credit' => (float) $bill->grand_total];

        $parts = explode('-', basename($f->filename, '.xml'));
        $base = 'BIR-' . $this->birFolder((string) $f->form) . '-' . ($parts[2] ?? date('ymd'));
        $jv = $base;
        for ($n = 2; DB::table('t_general_journal')->where('added_by', $by)->where('jv_no', $jv)->exists(); $n++) $jv = "{$base}-{$n}";
        $remarks = mb_substr("BIR {$f->form} {$f->return_period} · FILE & SUBMITTED", 0, 255);

        DB::transaction(function () use ($request, $by, $f, $bill, $lines, $jv, $remarks, $M, $D) {
            $this->gjInsert($request, $by, $jv, substr((string) $bill->transaction_date, 0, 10), (string) $bill->declared_month,
                array_map(fn ($l) => $l + ['remarks' => $remarks], $lines));
            DB::table($D)->where('invoice_no', $bill->invoice_no)->delete();
            DB::table($M)->where('id', $bill->id)->delete();
            DB::table(self::FILING)->where('id', $f->id)->update(array_intersect_key(
                ['invoice_no' => $jv, 'jv_no' => $jv, 'bill_account' => $bill->account_code, 'updated_at' => now()],
                array_flip(Schema::getColumnListing(self::FILING))
            ));
        });
        return DB::table(self::FILING)->where('id', $f->id)->first();
    }

    /* GET /api/bir/filing/{name}/payments */
    public function payments(Request $request, string $name)
    {
        $f = $this->gjFiling($request, $name);
        $rows = DB::table(self::PAYMENT)->where('filing_id', $f->id)->orderByDesc('pay_date')->orderByDesc('id')->get();
        $paid = round((float) $rows->sum('amount'), 2);
        return response()->json([
            'document_no' => "BIR {$f->form} · {$f->return_period}",
            'jv_no'       => $f->jv_no,
            'form_folder' => 'BIR_Return/' . $this->birFolder((string) $f->form),
            'payable'     => $f->bill_account ?: DB::table('t_general_journal')->where('added_by', $this->booksOf($request))->where('jv_no', $f->jv_no)
                ->where('credit', '>', 0)->orderByDesc('credit')->value('account_code'),
            'grand_total' => round((float) $f->amount, 2),
            'paid'        => $paid,
            'balance'     => round(max(0, (float) $f->amount - $paid), 2),
            'accounts'    => $this->chartOf($this->booksOf($request)),
            'payments'    => $rows->map(fn ($p) => [
                'id' => $p->id, 'pvoucher_no' => $p->jv_no, 'date' => substr((string) $p->pay_date, 0, 10), 'bank' => $p->bank,
                'payment_type' => $p->payment_type, 'ref_data' => $p->ref_data, 'amount' => (float) $p->amount, 'attachment' => $p->attachment,
            ])->values(),
        ]);
    }

    /* POST /api/bir/filing/{name}/payments  (multipart) {pv_date, bank, amount, payment_type, ref_data, document_no, particulars, attachment} */
    public function payStore(Request $request, string $name)
    {
        $f = $this->gjFiling($request, $name);
        $by = $this->booksOf($request);
        $d = $request->validate([
            'pv_date'      => ['required', 'date'],
            'bank'         => ['required', 'string', 'max:20'],
            'amount'       => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['nullable', 'string', 'max:50'],
            'ref_data'     => ['nullable', 'string', 'max:255'],
            'document_no'  => ['nullable', 'string', 'max:100'],
            'particulars'  => ['nullable', 'string', 'max:255'],
            'attachment'   => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx'],
        ], ['bank.required' => 'Pick the cash / bank account the money comes from', 'amount.gt' => 'Enter the amount paid', 'attachment.max' => 'Attachment is too big (10 MB max).']);

        $amount = round((float) $d['amount'], 2);
        $balance = round((float) $f->amount - (float) DB::table(self::PAYMENT)->where('filing_id', $f->id)->sum('amount'), 2);
        abort_if($amount > $balance + 0.01, 422, 'More than the balance (' . number_format($balance, 2) . ').');
        $payable = $f->bill_account ?: DB::table('t_general_journal')->where('added_by', $by)->where('jv_no', $f->jv_no)->where('credit', '>', 0)
            ->orderByDesc('credit')->value('account_code');
        abort_unless($payable, 422, 'The tax payable account of this return was not found.');
        abort_if($payable === $d['bank'], 422, 'Pick the cash / bank account, not the tax payable account.');

        $jv = 'BIRPAY-' . date('ymd-His');
        for ($n = 2; DB::table('t_general_journal')->where('added_by', $by)->where('jv_no', $jv)->exists(); $n++) $jv = 'BIRPAY-' . date('ymd-His') . "-{$n}";

        // attachment → S3 BIR_Return/<form>/<reference>-<file name>
        $file = null;
        if ($request->hasFile('attachment')) {
            $up = $request->file('attachment');
            $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $up->getClientOriginalName());
            try {
                $path = $up->getRealPath() ?: $up->getPathname();
                \Illuminate\Support\Facades\Storage::disk('s3')->put('BIR_Return/' . $this->birFolder($f->form) . "/{$jv}-{$clean}", @file_get_contents($path) ?: $up->get());
                $file = $clean;
            } catch (\Throwable $e) {
                report($e);
                abort(422, 'Attachment upload failed: ' . $e->getMessage());
            }
        }

        $remarks = mb_substr(trim(($d['particulars'] ?? '') ?: "BIR {$f->form} {$f->return_period} · payment") . ($d['ref_data'] ? " · Ref {$d['ref_data']}" : ''), 0, 255);
        DB::transaction(function () use ($request, $by, $f, $d, $amount, $jv, $payable, $remarks, $file) {
            $this->gjInsert($request, $by, $jv, $d['pv_date'], substr($d['pv_date'], 0, 7), [
                ['account_code' => $payable, 'debit' => $amount, 'credit' => 0, 'remarks' => $remarks],
                ['account_code' => $d['bank'], 'debit' => 0, 'credit' => $amount, 'remarks' => $remarks],
            ]);
            DB::table(self::PAYMENT)->insert([
                'filing_id' => $f->id, 'added_by' => $by, 'jv_no' => $jv, 'pay_date' => $d['pv_date'], 'amount' => $amount,
                'bank' => $d['bank'], 'payment_type' => $d['payment_type'] ?? null, 'ref_data' => $d['ref_data'] ?? null,
                'document_no' => $d['document_no'] ?? null, 'attachment' => $file,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $left = round($balance - $amount, 2);
        return response()->json(['jv_no' => $jv, 'balance' => max(0, $left),
            'message' => $left <= 0.009 ? "{$jv} saved – fully paid" : "{$jv} saved – balance " . number_format($left, 2)]);
    }

    /* DELETE /api/bir/payments/{id}   removes the payment and its General Journal entry */
    public function payDelete(Request $request, int $id)
    {
        $by = $this->booksOf($request);
        $p = DB::table(self::PAYMENT)->where('id', $id)->where('added_by', $by)->first();
        abort_unless($p, 404, 'Payment not found.');
        DB::transaction(function () use ($by, $p) {
            DB::table('t_general_journal')->where('added_by', $by)->where('jv_no', $p->jv_no)->delete();
            DB::table(self::PAYMENT)->where('id', $p->id)->delete();
        });
        return response()->json(['message' => "{$p->jv_no} removed"]);
    }

    /* GET /api/bir/payments/{id}/attachment */
    public function payAttachment(Request $request, int $id)
    {
        $p = DB::table(self::PAYMENT . ' as P')->join(self::FILING . ' as F', 'F.id', '=', 'P.filing_id')
            ->where('P.id', $id)->where('P.added_by', $this->booksOf($request))->first(['P.jv_no', 'P.attachment', 'F.form']);
        abort_unless($p && $p->attachment, 404, 'No attachment.');
        $disk = \Illuminate\Support\Facades\Storage::disk('s3');
        $key = 'BIR_Return/' . $this->birFolder($p->form) . "/{$p->jv_no}-{$p->attachment}";
        abort_unless($disk->exists($key), 404, 'File not found on S3.');
        return $disk->response($key, $p->attachment);
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

   private function effMonth(string $ref, string $month, ?string $ref2 = null): string
    {
        static $ut = false;
        if ($ut === false) {
            $ut = null;
            foreach (['t_undeclared_trans', 'tbl2_undeclared_trans'] as $t) if (Schema::hasTable($t)) { $ut = $t; break; }
        }
        if (!$ut) return "LEFT({$month}, 7)";
        $match = $ref2 ? "(U.reference_no = {$ref} OR U.reference_no = {$ref2})" : "U.reference_no = {$ref}";
        return "COALESCE((SELECT LEFT(U.new_declared_month, 7) FROM {$ut} U
                  WHERE {$match} AND U.is_approved = 0 AND U.report = 'EBIR'
                    AND U.old_declared_month = LEFT({$month}, 7)
                  ORDER BY U.id DESC LIMIT 1), LEFT({$month}, 7))";
    }

}