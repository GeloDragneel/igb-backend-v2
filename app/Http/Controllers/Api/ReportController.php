<?php

namespace App\Http\Controllers\Api;
use ZipArchive;
use Carbon\Carbon;
use App\Models\ChartsOfAccount;
use App\Models\ClientAccount;
use App\Models\RVoucherDetail;
use App\Models\RVoucherMaster;
use App\Models\SalesInvoiceDetail;
use App\Models\SalesInvoiceMaster;
use App\Models\UserClient;
use App\Models\VendorCustomer;
use App\Models\PurchaseExpenseMaster;
use App\Models\PurchaseExpenseDetail;
use App\Models\GRNMaster;
use App\Models\GRNDetail;
use App\Models\GeneralJournal;
use App\Models\Items;
use App\Models\PurchaseOrderMaster;
use App\Models\PurchaseOrderDetail;
use App\Models\WtbManual;
use App\Models\BegBalanceAccount;
use App\Models\BegBalanceProduct;
use App\Models\ClientValidId;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Concerns\EsubValidation;
class ReportController extends SalesController
{
    use EsubValidation;
    /** General Journal (manual JVs + Fixed Asset depreciation posted from Fixed Asset Management) */
    private const GJ_TABLE = 't_general_journal';
    private const CLOSING_PREFIX = 'CE-';      // Closing Entry JVs (CE-2026): shown in the General Ledger only
    /*
     * General Journal entry types (t_general_journal.entry_type)
     *   NORMAL    → General Ledger, Trial Balance, Working Trial Balance (DATA columns) and every other report
     *   ADJUSTING → General Ledger, Trial Balance, Working Trial Balance (ADJUSTING ENTRIES columns) and every other report
     *               (Fixed Asset depreciation entries are always ADJUSTING)
     *   CLOSING   → General Ledger only (CE-YYYY)
     */
    private const GJ_NORMAL = 'NORMAL', GJ_ADJUSTING = 'ADJUSTING', GJ_CLOSING = 'CLOSING';

    /** jv_no => true for Fixed Asset depreciation entries of these users */
    private function gjDepreciationJvs(array $ids): array
    {
        static $cache = [];
        $key = implode(',', $ids);
        if (isset($cache[$key])) return $cache[$key];
        if (!$ids || !Schema::hasTable('t_fixed_asset_depreciation')) return $cache[$key] = [];
        return $cache[$key] = array_fill_keys(
            DB::table('t_fixed_asset_depreciation')->whereIn('added_by', $ids)->whereNotNull('jv_no')->distinct()->pluck('jv_no')->map(fn ($v) => (string) $v)->all(),
            true
        );
    }

    /** NORMAL | ADJUSTING | CLOSING of one journal line */
    private function gjTypeOf(object $g, array $depJvs): string
    {
        $jv = strtoupper((string) $g->jv_no);
        if (str_starts_with($jv, self::CLOSING_PREFIX)) return self::GJ_CLOSING;
        if (isset($depJvs[(string) $g->jv_no])) return self::GJ_ADJUSTING;
        $t = strtoupper(trim((string) ($g->entry_type ?? '')));
        return in_array($t, [self::GJ_ADJUSTING, self::GJ_CLOSING], true) ? $t : self::GJ_NORMAL;
    }

    /* ---------- which client users (added_by) to include ---------- */
    protected function journalScope(Request $request, string $branch): array
    {
        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);
        $account = $caid ? ClientAccount::find($caid) : null;

        $isMain   = $account && strtoupper((string) $account->branch_type) === 'MAIN';
        $branches = $isMain
            ? ClientAccount::where('client_num', $account->client_num)->orderBy('branch_code')
                ->get(['id', 'trade_name', 'branch_code'])
            : collect();

        // old: MAIN + "0" = all branches, MAIN + CAID = that branch, else = own user
        if ($isMain && $branch === 'all') {
            $ids = UserClient::whereIn('caid', $branches->pluck('id'))->pluck('id')->all();
        } elseif ($isMain && ctype_digit($branch) && $branches->contains('id', (int) $branch)) {
            $ids = UserClient::where('caid', (int) $branch)->pluck('id')->all();
        } else {
            $ids = [$addedBy];
        }

        return [array_values(array_filter($ids)), $account, $branches];
    }
    /* Report header: trade name, TIN, address (relations → text) */
    protected function companyHeader($account): ?array
    {
        if (!$account) return null;

        // first non-empty column from a list of possible names
        $pick = function (array $cols) use ($account) {
            foreach ($cols as $c) {
                $v = $account->getAttribute($c);
                if (is_scalar($v) && trim((string) $v) !== '') return trim((string) $v);
            }
            return '';
        };

        // relation object → its text (description / name / province_name …)
        $text = function ($v) {
            if (is_object($v)) {
                foreach (['description', 'name', 'city_name', 'citymun_desc', 'province_name', 'barangay_name'] as $f) {
                    if (!empty($v->{$f})) return (string) $v->{$f};
                }
                return '';
            }
            return is_scalar($v) ? trim((string) $v) : '';
        };

        $tin  = $pick(['tin', 'tin_no', 'tin_number']);
        $code = $pick(['branch_code']);

        // no TIN on the account → the client's BIR valid ID (m_client_valid_id, institution BIR) – same as E-Sub / BIR returns
        if (preg_replace('/\D/', '', $tin) === '') {
            $num = $pick(['client_num']);
            if ($num !== '') {
                $tin = (string) (ClientValidId::bir()->where('client_num', $num)->orderByDesc('id')
                    ->pluck('id_num')
                    ->first(fn ($t) => strlen(preg_replace('/\D/', '', (string) $t)) >= 9) ?? '');
            }
        }
        // 623-998-316 + branch 0000 → 623-998-316-0000 (a TIN that already has its branch part is left as it is)
        $digits = preg_replace('/\D/', '', $tin);
        if ($digits !== '' && strlen($digits) <= 9 && $code !== '') $tin = trim($tin) . '-' . $code;
        elseif ($digits === '') $tin = '';

        $address = implode(' ', array_filter([
            $pick(['street', 'address']),
            $text($account->barangay),
            $text($account->city ?? $account->cityMun ?? $account->city_mun ?? null),
            $text($account->province),
            $pick(['zip_code', 'zipcode', 'zip']),
        ]));

        return [
            'trade_name' => $pick(['trade_name', 'tradename', 'business_name', 'client_name']),
            'tin'        => $tin,
            'address'    => $address,
        ];
    }
    /* GET /api/journals/sales?filter_type=DM&from=2026-01&to=2026-09&branch=all */
    public function salesJournal(Request $request){
        $d = $request->validate([
            'filter_type' => ['required', 'in:DR,DM,DE'],
            'from' => ['required', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        $type = $d['filter_type'];
        $from = $d['from'];
        $to = $d['to'] ?? $from;

        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $byDate = function($q, string $dateCol) use ($type, $from, $to) {
            if ($type === 'DR') $q->whereBetween($dateCol, [$from, $to]);
            if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to]);
            if ($type === 'DE') $q->whereDate('created_at', $from);
        };

        $shownDate = function($row, string $dateCol) use ($type) {
            if ($type === 'DR') return optional($row->{$dateCol})->format('Y-m-d') ?? (string) $row->{$dateCol};
            if ($type === 'DM') return (string) $row->declared_month;
            return optional($row->created_at)->format('Y-m-d');
        };

        $masters = SalesInvoiceMaster::whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)
            ->where('grand_total', '<>', 0)
            ->where('trans_type', 'posted')
            ->where(fn($q) => $byDate($q, 'transaction_date'))
            ->get(['id', 'added_by', 'invoice_no', 'document_no', 'customer_id', 'account_code', 'transaction_date', 'declared_month', 'created_at', 'grand_total', '2307_it', '2307_vt']);

        $lines = SalesInvoiceDetail::whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)
            ->where('trans_type', 'posted')
            ->where(fn($q) => $byDate($q, 'transaction_date'))
            ->get(['id', 'added_by', 'invoice_no', 'document_no', 'customer_id', 'account_code', 'transaction_date', 'declared_month', 'created_at', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated', 'ex_rate']);

        $rvs = RVoucherMaster::whereIn('added_by', $ids)
            ->where('sub_total', '>', 0)
            ->where(fn($q) => $byDate($q, 'rv_date'))
            ->get(['id', 'added_by', 'rvoucher_no', 'invoice_no', 'document_no', 'customer_id', 'bank', 'rv_date', 'declared_month', 'created_at', 'sub_total', '2307_it', '2307_vt']);

        $rvLines = RVoucherDetail::whereIn('added_by', $ids)
            ->where('sub_total', '>', 0)
            ->where(fn($q) => $byDate($q, 'rv_date'))
            ->get(['id', 'added_by', 'rvoucher_no', 'invoice_no', 'customer_id', 'account_code', 'rv_date', 'declared_month', 'created_at', 'sub_total']);

        $rvDocNo = $rvs->pluck('document_no', 'rvoucher_no');
        if ($rvLines->pluck('rvoucher_no')->diff($rvDocNo->keys())->isNotEmpty()) {
            $rvDocNo = RVoucherMaster::whereIn('rvoucher_no', $rvLines->pluck('rvoucher_no')->unique())->pluck('document_no', 'rvoucher_no');
        }

        $customerIds = $masters->pluck('customer_id')->merge($lines->pluck('customer_id'))->merge($rvs->pluck('customer_id'))->merge($rvLines->pluck('customer_id'))->filter()->unique();
        $customers = VendorCustomer::whereIn('id', $customerIds)->pluck('name', 'id');

        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $acct = fn($addedBy, $code) => (string) ($accounts[$addedBy . '|' . $code] ?? '');
        $cust = fn($id) => (string) ($customers[$id] ?? 'WALK IN');

        $rows = [];

        $push = function(int $rank, $src, string $date, $code, string $desc, float $dr, float $cr, ?string $docNo = null) use (&$rows, $cust) {
            $rows[] = [
                'date' => $date,
                'invoice_no' => (string) $src->invoice_no,
                'account_id' => (string) $code,
                'tran' => (string) ($docNo ?? $src->document_no),
                'name' => $cust($src->customer_id),
                'description' => $desc,
                'debit' => round($dr, 4),
                'credit' => round($cr, 4),
                '_rank' => $rank,
                '_id' => $src->id,
            ];
        };

        foreach ($masters as $m) {
            $date = $shownDate($m, 'transaction_date');
            if ((float) $m->{'2307_it'} != 0) $push(1, $m, $date, 21910, 'Withholding Tax - IT', (float) $m->{'2307_it'}, 0);
            if ((float) $m->{'2307_vt'} != 0) $push(1, $m, $date, 21910, 'Withholding Tax - VT', (float) $m->{'2307_vt'}, 0);
            $push(1, $m, $date, $m->account_code, $acct($m->added_by, $m->account_code), (float) $m->grand_total, 0);
        }

        foreach ($lines as $l) {
            $date = $shownDate($l, 'transaction_date');
            $rate = (float) ($l->ex_rate ?: 1);
            $total = ((float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated) * $rate;
            if ((float) $l->input_vat != 0) $push(2, $l, $date, $l->account_code, 'Output VAT', 0, (float) $l->input_vat * $rate);
            if ($total != 0) $push(2, $l, $date, $l->account_code, $acct($l->added_by, $l->account_code), 0, $total);
        }

        foreach ($rvs as $r) {
            $date = $shownDate($r, 'rv_date');
            if ((float) $r->{'2307_it'} != 0) $push(3, $r, $date, 21910, 'Withholding Tax Receivable', (float) $r->{'2307_it'}, 0);
            if ((float) $r->{'2307_vt'} != 0) $push(3, $r, $date, 21910, 'Withholding Tax Receivable', (float) $r->{'2307_vt'}, 0);
            $push(3, $r, $date, $r->bank, $acct($r->added_by, $r->bank), (float) $r->sub_total, 0);
        }

        foreach ($rvLines as $r) {
            $push(4, $r, $shownDate($r, 'rv_date'), $r->account_code, $acct($r->added_by, $r->account_code), 0, (float) $r->sub_total, (string) ($rvDocNo[$r->rvoucher_no] ?? ''));
        }

        usort($rows, fn($a, $b) => [$a['date'], $a['invoice_no'], $a['_rank'], $a['_id']] <=> [$b['date'], $b['invoice_no'], $b['_rank'], $b['_id']]);

        $rows = array_map(function($r) {
            unset($r['_rank'], $r['_id']);
            return $r;
        }, $rows);

        return response()->json([
            'company' => $this->companyHeader($account),
            'branches' => $branches->map(fn($b) => [
                'id' => $b->id,
                'label' => "{$b->branch_code} - {$b->trade_name}"
            ])->values(),
            'rows' => $rows,
            'totals' => [
                'debit' => round(array_sum(array_column($rows, 'debit')), 4),
                'credit' => round(array_sum(array_column($rows, 'credit')), 4),
            ],
        ]);
    }
    public function purchasesJournal(Request $request){
        $d = $request->validate([
            'filter_type' => ['required', 'in:DR,DM,DE'],
            'from' => ['required', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        $type = $d['filter_type'];
        $from = $d['from'];
        $to = $d['to'] ?? $from;

        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $byDate = function($q, string $dateCol) use ($type, $from, $to) {
            if ($type === 'DR') $q->whereBetween($dateCol, [$from, $to]);
            if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to]);
            if ($type === 'DE') $q->whereDate('created_at', $from);
        };

        $shownDate = function($row, string $dateCol) use ($type) {
            if ($type === 'DR') return optional($row->{$dateCol})->format('Y-m-d') ?? (string) $row->{$dateCol};
            if ($type === 'DM') return (string) $row->declared_month;
            return optional($row->created_at)->format('Y-m-d');
        };

        $apMasters = PurchaseExpenseMaster::whereIn('added_by', $ids)
            ->active()->where('record_status', 'posted')
            ->purchases()
            ->where(fn($q) => $byDate($q, 'transaction_date'))
            ->get(['id', 'added_by', 'invoice_no', 'document_no', 'vendor_id', 'account_code', 'transaction_date', 'declared_month', 'created_at', 'grand_total', '2307_it', '2307_vt']);

        $apLines = PurchaseExpenseDetail::whereIn('added_by', $ids)
            ->active()->where('record_status', 'posted')
            ->purchases()
            ->where(fn($q) => $byDate($q, 'transaction_date'))
            ->get(['id', 'added_by', 'invoice_no', 'document_no', 'vendor_id', 'account_code', 'transaction_date', 'declared_month', 'created_at', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated']);

        $vendorIds = $apMasters->pluck('vendor_id')->merge($apLines->pluck('vendor_id'))->filter()->unique();
        $vendors = VendorCustomer::whereIn('id', $vendorIds)->pluck('name', 'id');

        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $acct = fn($by, $code) => (string) ($accounts[$by . '|' . $code] ?? '');

        $rows = [];

        $push = function(int $seq, int $id, string $date, string $docNo, $code, $vendorId, string $desc, float $dr, float $cr) use (&$rows, $vendors) {
            $rows[] = [
                'date' => $date,
                'invoice_no' => $docNo,
                'account_id' => (string) $code,
                'tran' => $docNo,
                'name' => (string) ($vendors[$vendorId] ?? ''),
                'description' => $desc,
                'debit' => round($dr, 4),
                'credit' => round($cr, 4),
                '_seq' => $seq,
                '_id' => $id,
            ];
        };

        foreach ($apMasters as $m) {
            $date = $shownDate($m, 'transaction_date');
            $doc = (string) $m->document_no;
            if ((float) $m->grand_total > 0) $push(0, $m->id, $date, $doc, $m->account_code, $m->vendor_id, $acct($m->added_by, $m->account_code), 0, (float) $m->grand_total);
            if ((float) $m->{'2307_it'} > 0) $push(0, $m->id, $date, $doc, 21910, $m->vendor_id, 'Withholding Tax - IT', 0, (float) $m->{'2307_it'});
            if ((float) $m->{'2307_vt'} > 0) $push(0, $m->id, $date, $doc, 21910, $m->vendor_id, 'Withholding Tax - VT', 0, (float) $m->{'2307_vt'});
        }

        foreach ($apLines as $l) {
            $date = $shownDate($l, 'transaction_date');
            $doc = (string) $l->document_no;
            $total = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated;
            if ((float) $l->input_vat > 0) $push(1, $l->id, $date, $doc, $l->account_code, $l->vendor_id, 'Input Tax', (float) $l->input_vat, 0);
            if ($total > 0) $push(1, $l->id, $date, $doc, $l->account_code, $l->vendor_id, $acct($l->added_by, $l->account_code), $total, 0);
        }

        usort($rows, fn($a, $b) => [$a['date'], $a['tran'], $a['_seq'], $a['_id']] <=> [$b['date'], $b['tran'], $b['_seq'], $b['_id']]);
        $rows = array_map(function($r) { unset($r['_seq'], $r['_id']); return $r; }, $rows);

        return response()->json([
            'company' => $this->companyHeader($account),
            'branches' => $branches->map(fn($b) => [
                'id' => $b->id,
                'label' => "{$b->branch_code} - {$b->trade_name}"
            ])->values(),
            'rows' => $rows,
            'totals' => [
                'debit' => round(array_sum(array_column($rows, 'debit')), 4),
                'credit' => round(array_sum(array_column($rows, 'credit')), 4),
            ],
        ]);
    }
    public function expensesJournal(Request $request){
        $d = $request->validate([
            'filter_type' => ['required', 'in:DR,DM,DE'],
            'from' => ['required', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);

        $type = $d['filter_type'];
        $from = $d['from'];
        $to = $d['to'] ?? $from;

        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $byDate = function ($q, string $dateCol) use ($type, $from, $to) {
            if ($type === 'DR') $q->whereBetween($dateCol, [$from, $to]);
            if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to]);
            if ($type === 'DE') $q->whereDate('created_at', $from);
        };

        $shownDate = function ($row, string $dateCol) use ($type) {
            if ($type === 'DR') return optional($row->{$dateCol})->format('Y-m-d') ?? (string) $row->{$dateCol};
            if ($type === 'DM') return (string) $row->declared_month;
            return optional($row->created_at)->format('Y-m-d');
        };

        $apMasters = PurchaseExpenseMaster::whereIn('added_by', $ids)
            ->active()->where('record_status', 'posted')
            ->expenses()
            ->where(fn($q) => $byDate($q, 'transaction_date'))
            ->get(['id', 'added_by', 'invoice_no', 'document_no', 'vendor_id', 'account_code', 'transaction_date', 'declared_month', 'created_at', 'grand_total', '2307_it', '2307_vt']);

        $apLines = PurchaseExpenseDetail::whereIn('added_by', $ids)
            ->active()->where('record_status', 'posted')
            ->expenses()
            ->where(fn($q) => $byDate($q, 'transaction_date'))
            ->get(['id', 'added_by', 'invoice_no', 'document_no', 'vendor_id', 'account_code', 'transaction_date', 'declared_month', 'created_at', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated']);

        $vendorIds = $apMasters->pluck('vendor_id')->merge($apLines->pluck('vendor_id'))->filter()->unique();
        $vendors = VendorCustomer::whereIn('id', $vendorIds)->pluck('name', 'id');

        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $acct = fn($by, $code) => (string) ($accounts[$by . '|' . $code] ?? '');

        $rows = [];

        $push = function (int $seq, int $id, string $date, string $docNo, $code, $vendorId, string $desc, float $dr, float $cr) use (&$rows, $vendors) {
            $rows[] = [
                'date' => $date,
                'invoice_no' => $docNo,
                'account_id' => (string) $code,
                'tran' => $docNo,
                'name' => (string) ($vendors[$vendorId] ?? ''),
                'description' => $desc,
                'debit' => round($dr, 4),
                'credit' => round($cr, 4),
                '_seq' => $seq,
                '_id' => $id,
            ];
        };

        foreach ($apMasters as $m) {
            $date = $shownDate($m, 'transaction_date');
            $doc = (string) $m->document_no;

            if ((float) $m->grand_total > 0) $push(0, $m->id, $date, $doc, $m->account_code, $m->vendor_id, $acct($m->added_by, $m->account_code), 0, (float) $m->grand_total);
            if ((float) $m->{'2307_it'} > 0) $push(0, $m->id, $date, $doc, 21910, $m->vendor_id, 'Withholding Tax - IT', 0, (float) $m->{'2307_it'});
            if ((float) $m->{'2307_vt'} > 0) $push(0, $m->id, $date, $doc, 21910, $m->vendor_id, 'Withholding Tax - VT', 0, (float) $m->{'2307_vt'});
        }

        foreach ($apLines as $l) {
            $date = $shownDate($l, 'transaction_date');
            $doc = (string) $l->document_no;
            $total = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated;

            if ((float) $l->input_vat > 0) $push(1, $l->id, $date, $doc, $l->account_code, $l->vendor_id, 'Input Tax', (float) $l->input_vat, 0);
            if ($total > 0) $push(1, $l->id, $date, $doc, $l->account_code, $l->vendor_id, $acct($l->added_by, $l->account_code), $total, 0);
        }

        usort($rows, fn($a, $b) => [$a['date'], $a['tran'], $a['_seq'], $a['_id']] <=> [$b['date'], $b['tran'], $b['_seq'], $b['_id']]);
        $rows = array_map(function($r) {
            unset($r['_seq'], $r['_id']);
            return $r;
        }, $rows);

        return response()->json([
            'company' => $this->companyHeader($account),
            'branches' => $branches->map(fn($b) => [
                'id' => $b->id,
                'label' => "{$b->branch_code} - {$b->trade_name}"
            ])->values(),
            'rows' => $rows,
            'totals' => [
                'debit' => round(array_sum(array_column($rows, 'debit')), 4),
                'credit' => round(array_sum(array_column($rows, 'credit')), 4),
            ],
        ]);
    }
    public function generalJournal(Request $request){
        $d = $request->validate([
            'filter_type' => ['required', 'in:DR,DM,DE'],
            'from' => ['required', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);

        $type = $d['filter_type'];
        $from = $d['from'];
        $to = $d['to'] ?? $from;

        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $byDate = function($q) use ($type, $from, $to) {
            if ($type === 'DR') $q->whereBetween('transaction_date', [$from, $to]);
            if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to]);
            if ($type === 'DE') $q->whereDate('created_at', $from);
        };

        $shownDate = function($row) use ($type) {
            if ($type === 'DR') return optional($row->transaction_date)->format('Y-m-d') ?? (string) $row->transaction_date;
            if ($type === 'DM') return (string) $row->declared_month;
            return optional($row->created_at)->format('Y-m-d');
        };

        $journals = GeneralJournal::whereIn('added_by', $ids)
            ->where(fn($q) => $byDate($q))
            ->get([
                'id',
                'jv_no',
                'account_code',
                'subsidiary',
                'debit',
                'credit',
                'remarks',
                'transaction_date',
                'declared_month',
                'added_by',
                'employee_id',
                'created_at'
            ]);

        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $acct = fn($by, $code) => (string) ($accounts[$by . '|' . $code] ?? '');

        $rows = [];

        foreach ($journals as $j) {
            $rows[] = [
                'date' => $shownDate($j),
                'invoice_no' => (string) $j->jv_no,
                'account_id' => (string) $j->account_code,
                'tran' => (string) $j->jv_no,
                'name' => $acct($j->added_by, $j->account_code),
                'description' => (string) ($j->remarks ?? ''),
                'debit' => round((float) $j->debit, 4),
                'credit' => round((float) $j->credit, 4),
                '_id' => $j->id,
            ];
        }

        usort($rows, fn($a, $b) => [$a['date'], $a['tran'], $a['_id']] <=> [$b['date'], $b['tran'], $b['_id']]);

        $rows = array_map(function($r) {
            unset($r['_id']);
            return $r;
        }, $rows);

        return response()->json([
            'company' => $this->companyHeader($account),
            'branches' => $branches->map(fn($b) => [
                'id' => $b->id,
                'label' => "{$b->branch_code} - {$b->trade_name}"
            ])->values(),
            'rows' => $rows,
            'totals' => [
                'debit' => round(array_sum(array_column($rows, 'debit')), 4),
                'credit' => round(array_sum(array_column($rows, 'credit')), 4),
            ],
        ]);
    }
    public function salesReport(Request $request){
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DR,DM,DE'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['nullable', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        $type = $d['filter_type'];
        $from = $d['from'];
        $to   = $d['to'] ?? $from;

        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $q = SalesInvoiceDetail::whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)
            ->where('trans_type', 'posted');

        if ($type === 'DR') $q->whereBetween('transaction_date', [$from, $to])->orderBy('transaction_date');
        if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to])->orderBy('transaction_date')->orderBy('id');
        if ($type === 'DE') $q->whereBetween(\DB::raw('DATE(created_at)'), [$from, $to])->orderBy('created_at');

        if (!empty($d['account_code']) && $d['account_code'] !== '0') {
            $q->where('account_code', $d['account_code']);
        }

        $lines = $q->get([
            'id', 'added_by', 'invoice_no', 'document_no', 'customer_id', 'account_code', 'transaction_date',
            'declared_month', 'amount', 'currency', 'ex_rate', 'net_of_vat', 'input_vat', 'vat_exempt',
            'zero_rated', 'non_vat',
        ]);

        // customer TIN / branch / name
        $customers = VendorCustomer::whereIn('id', $lines->pluck('customer_id')->filter()->unique())
            ->get(['id', 'name', 'tin', 'branch_code'])
            ->keyBy('id');

        // client branch code per user (old: tbl2_user → tbl_client_account)
        $userCaid   = UserClient::whereIn('id', $ids)->pluck('caid', 'id');
        $branchCode = ClientAccount::whereIn('id', $userCaid->filter()->unique())->pluck('branch_code', 'id');

        // account names per user (old key: AccountCode|AddedBy)
        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $rows = $lines->map(function ($l) use ($customers, $userCaid, $branchCode, $accounts) {
            $rate = (float) ($l->ex_rate ?: 1);
            $c    = $customers[$l->customer_id] ?? null;

            return [
                'id'            => $l->id,
                'invoice_no'    => $l->invoice_no,
                'branch_client' => (string) ($branchCode[$userCaid[$l->added_by] ?? 0] ?? ''),
                'date'          => optional($l->transaction_date)->format('d-M-Y'),
                'tin'           => (string) ($c->tin ?? ''),
                'branch_code'   => (string) ($c->branch_code ?? ''),
                'customer'      => (string) ($c->name ?? 'WALK IN'),
                'document_no'   => (string) ($l->document_no ?? ''),
                'account_name'  => (string) ($accounts[$l->added_by . '|' . $l->account_code] ?? ''),
                'amount'        => round((float) $l->amount, 4),
                'currency'      => (string) ($l->currency ?: 'PHP'),
                'ex_rate'       => $rate,
                'php'           => round((float) $l->amount * $rate, 4),
                'net_of_vat'    => round((float) $l->net_of_vat * $rate, 4),
                'output_vat'    => round((float) $l->input_vat * $rate, 4),
                'vat_exempt'    => round((float) $l->vat_exempt * $rate, 4),
                'zero_rated'    => round((float) $l->zero_rated * $rate, 4),
                'non_vat'       => round((float) $l->non_vat * $rate, 4),
            ];
        })->values();

        return response()->json([
            'company'  => $this->companyHeader($account),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'rows'     => $rows,
        ]);
    }
    public function purchaseReport(Request $request){
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DR,DM,DE'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['nullable', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        $type    = $d['filter_type'];
        $from    = $d['from'];
        $to      = $d['to'] ?? $from;
        $account = (!empty($d['account_code']) && $d['account_code'] !== '0') ? $d['account_code'] : null;

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        /* ---------- GRN lines (old WHERE_0) ---------- */
        $grn = GRNDetail::whereIn('receive_by', $ids)->where('total', '<>', 0);
        if ($type === 'DR') $grn->whereBetween('grn_date', [$from, $to])->where('grn_status_id', '<>', 5);
        if ($type === 'DM') $grn->whereBetween('declared_month', [$from, $to])->where('grn_status_id', '<>', 5);
        if ($type === 'DE') $grn->whereBetween(\DB::raw('DATE(created_at)'), [$from, $to]);
        if ($account) $grn->where('account_code', $account);

        $grnLines = $grn->get([
            'id', 'receive_by', 'grn_no', 'po_number', 'vendor_id', 'account_code', 'grn_date', 'declared_month',
            'total', 'net_of_vat', 'input_vat', 'vat_exempt', 'zero_rated', 'non_vat',
        ]);

        /* ---------- AP invoice lines, purchases only (old WHERE_1) ---------- */
        $ap = PurchaseExpenseDetail::whereIn('added_by', $ids)->purchases()->where('record_status', 'posted');
        if ($type === 'DR') $ap->whereBetween('transaction_date', [$from, $to])->where('invoice_status_id', '<>', 5);
        if ($type === 'DM') $ap->whereBetween('declared_month', [$from, $to])->where('invoice_status_id', '<>', 5)->where('amount', '<>', 0);
        if ($type === 'DE') $ap->whereBetween(\DB::raw('DATE(created_at)'), [$from, $to])->where('amount', '<>', 0);
        if ($account) $ap->where('account_code', $account);

        $apLines = $ap->get([
            'id', 'added_by', 'invoice_no', 'document_no', 'po_number', 'vendor_id', 'account_code',
            'transaction_date', 'declared_month', 'amount', 'net_of_vat', 'input_vat', 'vat_exempt',
            'zero_rated', 'non_vat',
        ]);

        /* ---------- lookups ---------- */
        $vendors = VendorCustomer::whereIn('id', $grnLines->pluck('vendor_id')->merge($apLines->pluck('vendor_id'))->filter()->unique())
            ->get(['id', 'name', 'tin', 'branch_code'])
            ->keyBy('id');

        $userCaid   = UserClient::whereIn('id', $ids)->pluck('caid', 'id');
        $branchCode = ClientAccount::whereIn('id', $userCaid->filter()->unique())->pluck('branch_code', 'id');

        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $row = function ($src, $by, $date, string $doc, string $ref, $amount) use ($vendors, $userCaid, $branchCode, $accounts) {
            $v    = $vendors[$src->vendor_id] ?? null;
            $code = $src->account_code ?: 'NoCode';
            return [
                'id'            => $src->id,
                'sort_date'     => optional($date)->format('Y-m-d') ?? '',
                'branch_client' => (string) ($branchCode[$userCaid[$by] ?? 0] ?? ''),
                'date'          => optional($date)->format('d-M-Y'),
                'tin'           => (string) ($v->tin ?? ''),
                'branch_code'   => (string) ($v->branch_code ?? ''),
                'vendor'        => (string) ($v->name ?? ''),
                'document_no'   => $doc,
                'ref_no'        => $ref,
                'account_name'  => (string) ($accounts[$by . '|' . $code] ?? ''),
                'amount'        => round((float) $amount, 4),
                'currency'      => 'PHP',          // old report: always PHP / 1.0000
                'ex_rate'       => 1,
                'php'           => round((float) $amount, 4),
                'net_of_vat'    => round((float) $src->net_of_vat, 4),
                'input_vat'     => round((float) $src->input_vat, 4),
                'vat_exempt'    => round((float) $src->vat_exempt, 4),
                'zero_rated'    => round((float) $src->zero_rated, 4),
                'non_vat'       => round((float) $src->non_vat, 4),
            ];
        };

        $rows = collect()
            ->merge($grnLines->map(fn ($l) => $row($l, $l->receive_by, $l->grn_date, (string) ($l->po_number ?? ''), (string) $l->grn_no, $l->total)))
            ->merge($apLines->map(fn ($l) => $row($l, $l->added_by, $l->transaction_date, (string) ($l->document_no ?? ''), (string) $l->invoice_no, $l->amount)))
            ->sortBy([['sort_date', 'asc'], ['id', 'asc']])   // old: ORDER BY PODate2, ID
            ->values()
            ->map(function ($r, $i) {
                $r['id'] = $i + 1;   // unique key for the table (GRN and AP ids can repeat)
                unset($r['sort_date']);
                return $r;
            });

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'rows'     => $rows,
        ]);
    }
    public function expenseReport(Request $request){
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DR,DM,DE'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['nullable', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        $type = $d['filter_type'];
        $from = $d['from'];
        $to   = $d['to'] ?? $from;

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        /* ---------- AP invoice lines, expenses only (old WHERE_1) ---------- */
        $q = PurchaseExpenseDetail::whereIn('added_by', $ids)
            ->expenses()->where('record_status', 'posted')
            ->whereNotNull('vendor_id');                          // old INNER JOIN lib_vendor_customer

        if ($type === 'DR') $q->whereBetween('transaction_date', [$from, $to])->where('invoice_status_id', '<>', 5)
                              ->orderBy('transaction_date');
        if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to])->where('invoice_status_id', '<>', 5)
                              ->orderBy('transaction_date')->orderBy('id');
        if ($type === 'DE') $q->whereBetween(\DB::raw('DATE(created_at)'), [$from, $to])
                              ->orderBy('created_at');

        if (!empty($d['account_code']) && $d['account_code'] !== '0') {
            $q->where('account_code', $d['account_code']);
        }

        $lines = $q->get([
            'id', 'added_by', 'invoice_no', 'document_no', 'po_number', 'vendor_id', 'account_code',
            'transaction_date', 'declared_month', 'amount', 'net_of_vat', 'input_vat', 'vat_exempt',
            'zero_rated', 'non_vat',
        ]);

        /* ---------- lookups ---------- */
        $vendors = VendorCustomer::whereIn('id', $lines->pluck('vendor_id')->filter()->unique())
            ->get(['id', 'name', 'tin', 'branch_code'])
            ->keyBy('id');

        // old INNER JOIN: drop lines whose vendor no longer exists
        $lines = $lines->filter(fn ($l) => isset($vendors[$l->vendor_id]))->values();

        $userCaid   = UserClient::whereIn('id', $ids)->pluck('caid', 'id');
        $branchCode = ClientAccount::whereIn('id', $userCaid->filter()->unique())->pluck('branch_code', 'id');

        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $rows = $lines->map(function ($l, $i) use ($vendors, $userCaid, $branchCode, $accounts) {
            $v    = $vendors[$l->vendor_id];
            $code = $l->account_code ?: 'NoCode';
            $doc  = (string) ($l->document_no ?? '');

            return [
                'id'            => $i + 1,
                'branch_client' => (string) ($branchCode[$userCaid[$l->added_by] ?? 0] ?? ''),
                'date'          => optional($l->transaction_date)->format('d-M-Y'),
                'tin'           => (string) ($v->tin ?? ''),
                'branch_code'   => (string) ($v->branch_code ?? ''),
                'vendor'        => (string) ($v->name ?? ''),
                // old: DocumentNo if longer than 1 char, else PONumber
                'document_no'   => strlen($doc) > 1 ? $doc : (string) ($l->po_number ?? ''),
                'ref_no'        => (string) $l->invoice_no,
                'account_name'  => (string) ($accounts[$l->added_by . '|' . $code] ?? ''),
                'amount'        => round((float) $l->amount, 4),
                'currency'      => 'PHP',          // old report: always PHP / 1.0000
                'ex_rate'       => 1,
                'php'           => round((float) $l->amount, 4),
                'net_of_vat'    => round((float) $l->net_of_vat, 4),
                'input_vat'     => round((float) $l->input_vat, 4),
                'vat_exempt'    => round((float) $l->vat_exempt, 4),
                'zero_rated'    => round((float) $l->zero_rated, 4),
                'non_vat'       => round((float) $l->non_vat, 4),
            ];
        })->values();

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'rows'     => $rows,
        ]);
    }
    private function ledgerEntries(array $ids, string $type, string $from, string $to, array $opt = []): array{
        $withCollections = $opt['collections'] ?? true;
        $only            = $opt['only'] ?? null;
        $customerId      = $opt['customer_id'] ?? null;
        $vendorId        = $opt['vendor_id'] ?? null;
        $withClosing     = $opt['closing'] ?? false; // Closing Entry (CE-…) only for the General Ledger
        $gjMode          = $opt['gj'] ?? 'all';         // all = normal + adjusting · normal · adjusting (journal entry types)
        $want            = fn (string $src) => !$only || in_array($src, $only, true);

        $byDate = function ($q, string $dateCol) use ($type, $from, $to) {
            if ($type === 'DR') $q->whereBetween($dateCol, [$from, $to]);
            if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to]);
            if ($type === 'DE') $q->whereDate('created_at', $from);
        };
        $shown = function ($src, $date) use ($type) {
            if ($type === 'DM') return (string) $src->declared_month;
            if ($type === 'DE') return optional($src->created_at)->format('Y-m-d');
            return optional($date)->format('Y-m-d');
        };

        /* ---------- lookups ---------- */
        $accounts = ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);
        $acct = fn ($by, $code) => (string) ($accounts[$by . '|' . $code] ?? '');

        $partyCache = [];
        $partyName = function ($id) use (&$partyCache) {
            if (!$id) return '';
            if (!array_key_exists($id, $partyCache)) {
                $v = VendorCustomer::find($id);
                $partyCache[$id] = $v ? (string) ($v->name ?? $v->vendor_name ?? $v->registered_name ?? $v->trade_name ?? '') : '';
            }
            return $partyCache[$id];
        };

        $items = Items::whereIn('added_by', $ids)
            ->get(['id', 'item_code', 'input_vat_account', 'output_vat_account', 'gl_cost_of_sales_account'])
            ->keyBy('id');

        /* ---------- helpers ---------- */
        $rows = [];

        $base = function ($src, $date, string $transType, $reference, $refData, string $jrnl, $vendorId, string $itemCode = '')
            use ($partyName, $shown) {
            $name = $partyName($vendorId);
            return [
                'id'             => $src->id,
                'trans_type'     => $transType,
                'reference'      => (string) $reference,
                'ref_data'       => (string) $refData,
                'jrnl'           => $jrnl,
                'description'    => $itemCode !== '' ? "{$name} - Item : {$itemCode}" : $name,
                'party'          => $name,
                'date'           => $shown($src, $date),
                'sort_date'      => optional($date)->format('Y-m-d') ?? '',
                'declared_month' => (string) $src->declared_month,
            ];
        };
        $add = function (array $b, $code, string $name, float $dr, float $cr, ?string $jrnl = null) use (&$rows) {
            if ($dr == 0 && $cr == 0) return;
            $row = $b + [
                'account_code' => (string) ($code ?: 0),
                'account_name' => $name,
                'debit'        => round($dr, 4),
                'credit'       => round($cr, 4),
            ];
            if ($jrnl) $row['jrnl'] = $jrnl;
            $rows[] = $row;
        };

        $itemAcct = function ($item, string $field, $by, string $fallback) use ($acct) {
            $code = $item->{$field} ?? null;
            return [$code ?: 0, $code ? ($acct($by, $code) ?: $fallback) : $fallback];
        };

        $jrnlOf = fn ($t) => strtolower((string) $t) === 'purchases' ? 'PJ' : 'CDJ';

        /* ================= SALES INVOICE ================= */
        if ($want('INV')) {
            $invMasters = SalesInvoiceMaster::whereIn('added_by', $ids)
                ->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->where(fn ($q) => $byDate($q, 'transaction_date'))
                ->get(['id', 'added_by', 'account_code', 'transaction_date', 'customer_id', 'invoice_no', 'document_no',
                       'grand_total', '2307_it', '2307_vt', 'declared_month', 'created_at']);

            foreach ($invMasters as $m) {
                $b = $base($m, $m->transaction_date, 'INV', $m->document_no, $m->invoice_no, 'SJ', $m->customer_id);
                if ((float) $m->grand_total > 0) $add($b, $m->account_code, $acct($m->added_by, $m->account_code), (float) $m->grand_total, 0);
                if ((float) $m->{'2307_it'} > 0) $add($b, 21910, 'Withholding Tax - Income Tax', (float) $m->{'2307_it'}, 0);
                if ((float) $m->{'2307_vt'} > 0) $add($b, 21910, 'Withholding Tax - VAT', (float) $m->{'2307_vt'}, 0);
            }

            $invLines = SalesInvoiceDetail::whereIn('added_by', $ids)
                ->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
                ->whereIn('invoice_no', $invMasters->pluck('invoice_no')->filter()->unique()->values()->all() ?: [''])
                ->get(['id', 'added_by', 'account_code', 'transaction_date', 'customer_id', 'invoice_no', 'document_no',
                       'input_vat', 'item_id', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated', 'ex_rate',
                       'total_cost', 'declared_month', 'created_at']);

            foreach ($invLines as $l) {
                $item = $items[$l->item_id] ?? null;
                $b    = $base($l, $l->transaction_date, 'INV', $l->document_no, $l->invoice_no, 'SJ', $l->customer_id, (string) ($item->item_code ?? ''));

                if ((float) $l->input_vat > 0) {
                    [$code, $name] = $itemAcct($item, 'output_vat_account', $l->added_by, 'No Account Code Settings');
                    $add($b, $code, $name, 0, (float) $l->input_vat);
                }
                // old: NetOfVAT + VATExempt + NonVAT + (ZeroRated * ExRate)
                $revenue = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated * (float) ($l->ex_rate ?: 1);
                $add($b, $l->account_code, $acct($l->added_by, $l->account_code), 0, $revenue);

                if ((float) $l->total_cost > 0) {
                    [$code, $name] = $itemAcct($item, 'gl_cost_of_sales_account', $l->added_by, 'Cost of Sales');
                    $add($b, $code, $name, 0, (float) $l->total_cost, 'COG');
                }
            }
        }

        /* ================= COLLECTION (RV) ================= */
        if ($withCollections && $want('RV')) {
            $rvMasters = RVoucherMaster::whereIn('added_by', $ids)
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->where(fn ($q) => $byDate($q, 'rv_date'))
                ->get(['id', 'added_by', 'bank', 'rv_date', 'customer_id', 'invoice_no', 'document_no',
                       'sub_total', '2307_it', '2307_vt', 'declared_month', 'created_at']);

            foreach ($rvMasters as $m) {
                $b = $base($m, $m->rv_date, 'RV', $m->document_no, $m->invoice_no, 'SJ', $m->customer_id);
                if ((float) $m->sub_total > 0)   $add($b, $m->bank, $acct($m->added_by, $m->bank), (float) $m->sub_total, 0);
                if ((float) $m->{'2307_it'} > 0) $add($b, 21910, 'Withholding Tax Receivable', (float) $m->{'2307_it'}, 0);
                if ((float) $m->{'2307_vt'} > 0) $add($b, 21910, 'Withholding Tax Receivable', (float) $m->{'2307_vt'}, 0);
            }

            $rvLines = RVoucherDetail::whereIn('added_by', $ids)
                ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                ->where(fn ($q) => $byDate($q, 'rv_date'))
                ->get(['id', 'added_by', 'account_code', 'rv_date', 'customer_id', 'invoice_no', 'rvoucher_no',
                       'sub_total', 'declared_month', 'created_at']);
            $rvDocNo = RVoucherMaster::whereIn('rvoucher_no', $rvLines->pluck('rvoucher_no')->filter()->unique())
                ->pluck('document_no', 'rvoucher_no');

            foreach ($rvLines as $l) {
                $b = $base($l, $l->rv_date, 'RV', $rvDocNo[$l->rvoucher_no] ?? '', $l->invoice_no, 'SJ', $l->customer_id);
                if ((float) $l->sub_total > 0) $add($b, $l->account_code, $acct($l->added_by, $l->account_code), 0, (float) $l->sub_total);
            }
        }

        /* ================= PURCHASE ORDER ================= */
        if ($want('PO')) {
            $poMasters = PurchaseOrderMaster::whereIn('added_by', $ids)
                ->where(fn ($q) => $q->whereNull('po_status_id')->orWhere('po_status_id', '<>', 4))   // 4 = VOID
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->where(fn ($q) => $byDate($q, 'po_date'))
                ->get(['id', 'added_by', 'account_code', 'po_date', 'vendor_id', 'po_number', 'document_no', 'grand_total',
                       '2307_it', '2307_vt', 'trans_type', 'ex_rate', 'declared_month', 'created_at']);

            foreach ($poMasters as $m) {
                $b = $base($m, $m->po_date, 'PO', $m->document_no, $m->po_number, $jrnlOf($m->trans_type), $m->vendor_id);
                $add($b, $m->account_code, $acct($m->added_by, $m->account_code), 0, (float) $m->grand_total);
                if ((float) $m->{'2307_it'} > 0) $add($b, 21910, 'Withholding Tax - Income Tax', 0, (float) $m->{'2307_it'});
                if ((float) $m->{'2307_vt'} > 0) $add($b, 21910, 'Withholding Tax - VAT', 0, (float) $m->{'2307_vt'});
            }

            $poByNo  = $poMasters->keyBy('po_number');
            $poLines = PurchaseOrderDetail::whereIn('po_number', $poByNo->keys())
                ->get(['id', 'po_number', 'account_code', 'item_id', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated']);

            foreach ($poLines as $l) {
                $m    = $poByNo[$l->po_number];
                $item = $items[$l->item_id] ?? null;
                $b    = $base($m, $m->po_date, 'PO', $m->document_no, $m->po_number, $jrnlOf($m->trans_type), $m->vendor_id, (string) ($item->item_code ?? ''));
                $b['id'] = $l->id;

                if ((float) $l->input_vat > 0) {
                    [$code, $name] = $itemAcct($item, 'input_vat_account', $m->added_by, 'No Account Code Settings');
                    $add($b, $code, $name, (float) $l->input_vat, 0);
                }
                $cost = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated * (float) ($m->ex_rate ?: 1);
                $add($b, $l->account_code, $acct($m->added_by, $l->account_code), $cost, 0);
            }
        }

        /* ================= AP INVOICE (purchases + expenses) ================= */
        if ($want('AP')) {
            $apMasters = PurchaseExpenseMaster::whereIn('added_by', $ids)->active()->where('record_status', 'posted')
                ->where('grand_total', '>', 0)
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->where(fn ($q) => $byDate($q, 'transaction_date'))
                ->get(['id', 'added_by', 'account_code', 'transaction_date', 'vendor_id', 'invoice_no', 'document_no',
                       'grand_total', '2307_it', '2307_vt', 'trans_type', 'declared_month', 'created_at']);

            foreach ($apMasters as $m) {
                $b = $base($m, $m->transaction_date, 'AP', $m->document_no, $m->invoice_no, $jrnlOf($m->trans_type), $m->vendor_id);
                $add($b, $m->account_code, $acct($m->added_by, $m->account_code), 0, (float) $m->grand_total);
                if ((float) $m->{'2307_it'} > 0) $add($b, 21910, 'Withholding Tax - Income Tax', 0, (float) $m->{'2307_it'});
                if ((float) $m->{'2307_vt'} > 0) $add($b, 21910, 'Withholding Tax - VAT', 0, (float) $m->{'2307_vt'});
            }

            $apLines = PurchaseExpenseDetail::whereIn('added_by', $ids)->active()->where('record_status', 'posted')
                ->whereIn('invoice_no', $apMasters->pluck('invoice_no')->filter()->unique()->values()->all() ?: [''])
                ->get(['id', 'added_by', 'account_code', 'transaction_date', 'vendor_id', 'invoice_no', 'document_no',
                       'input_vat', 'item_id', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated', 'trans_type',
                       'declared_month', 'created_at']);

            foreach ($apLines as $l) {
                $item = $items[$l->item_id] ?? null;
                $b    = $base($l, $l->transaction_date, 'AP', $l->document_no, $l->invoice_no, $jrnlOf($l->trans_type), $l->vendor_id);

                if ((float) $l->input_vat > 0) {
                    [$code, $name] = $itemAcct($item, 'input_vat_account', $l->added_by, 'Input VAT (No Account Assigned)');
                    $add($b, $code, $name, (float) $l->input_vat, 0);
                }
                $cost = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated;
                $add($b, $l->account_code, $acct($l->added_by, $l->account_code), $cost, 0);
            }
        }

        /* ================= GRN (receiving) ================= */
        if ($want('GRN')) {
            $grnMasters = GRNMaster::whereIn('receive_by', $ids)
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->where(fn ($q) => $byDate($q, 'grn_date'))
                ->get(['id', 'receive_by', 'grn_date', 'vendor_id', 'grn_no', 'grand_total', '2307_it', '2307_vt',
                       'declared_month', 'created_at']);

            foreach ($grnMasters as $m) {
                $b = $base($m, $m->grn_date, 'GRN', $m->grn_no, $m->grn_no, 'PJ', $m->vendor_id);
                $add($b, 21100, $acct($m->receive_by, 21100), 0, (float) $m->grand_total);
                if ((float) $m->{'2307_it'} > 0) $add($b, 21910, 'Withholding Tax - Income Tax', 0, (float) $m->{'2307_it'});
                if ((float) $m->{'2307_vt'} > 0) $add($b, 21910, 'Withholding Tax - VAT', 0, (float) $m->{'2307_vt'});
            }

            $grnByNo  = $grnMasters->keyBy('grn_no');
            $grnLines = GRNDetail::whereIn('grn_no', $grnByNo->keys())
                ->get(['id', 'grn_no', 'account_code', 'item_id', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated']);

            foreach ($grnLines as $l) {
                $m    = $grnByNo[$l->grn_no];
                $item = $items[$l->item_id] ?? null;
                $b    = $base($m, $m->grn_date, 'GRN', $m->grn_no, $m->grn_no, 'PJ', $m->vendor_id);
                $b['id'] = $l->id;

                if ((float) $l->input_vat > 0) {
                    [$code, $name] = $itemAcct($item, 'input_vat_account', $m->receive_by, 'No Account Code Settings');
                    $add($b, $code, $name, (float) $l->input_vat, 0);   // GRN input VAT (old code had it as Credit)
                }
                $cost = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated;
                $add($b, $l->account_code, $acct($m->receive_by, $l->account_code), $cost, 0);
            }
        }

        /* ================= GENERAL JOURNAL (manual JVs + Fixed Asset depreciation DEP-YYYY-MM) ================= */
        // old doGetGeneralLedger / doGetTrialBalanceList also read tbl2_general_journal (TransType 'GJ')
        if ($want('GJ') && !$customerId && !$vendorId) {
            $gjLines = DB::table(self::GJ_TABLE)
                ->whereIn('added_by', $ids)
                ->where(function ($q) use ($type, $from, $to) {
                    if ($type === 'DR') $q->whereBetween('transaction_date', [$from, $to]);
                    if ($type === 'DM') $q->whereBetween('declared_month', [$from, $to]);
                    if ($type === 'DE') $q->whereDate('created_at', $from);
                })
                ->when(!$withClosing, fn ($q) => $q->where('jv_no', 'not like', self::CLOSING_PREFIX . '%'))
                ->get(array_merge(['id', 'added_by', 'jv_no', 'account_code', 'debit', 'credit', 'remarks', 'transaction_date', 'declared_month', 'created_at'],
                    Schema::hasColumn(self::GJ_TABLE, 'entry_type') ? ['entry_type'] : []));
            $depJvs = $this->gjDepreciationJvs($ids);

            foreach ($gjLines as $g) {
                $gjType = $this->gjTypeOf($g, $depJvs);
                if ($gjType === self::GJ_CLOSING && !$withClosing) continue;
                if ($gjMode === 'normal' && $gjType === self::GJ_ADJUSTING) continue;
                if ($gjMode === 'adjusting' && $gjType !== self::GJ_ADJUSTING) continue;
                $tDate = $g->transaction_date ? substr((string) $g->transaction_date, 0, 10) : '';
                $b = [
                    'id'             => $g->id,
                    'trans_type'     => 'GJ',
                    'reference'      => (string) $g->jv_no,
                    'ref_data'       => (string) $g->jv_no,
                    'jrnl'           => $gjType === self::GJ_ADJUSTING ? 'AJ' : 'GJ',   // AJ = adjusting entry
                    'entry_type'     => $gjType,
                    'description'    => (string) ($g->remarks ?? ''),
                    'party'          => '',
                    'date'           => $type === 'DM' ? (string) $g->declared_month : ($type === 'DE' ? substr((string) $g->created_at, 0, 10) : $tDate),
                    'sort_date'      => $tDate,
                    'declared_month' => (string) $g->declared_month,
                ];
                $add($b, $g->account_code, $acct($g->added_by, $g->account_code), (float) $g->debit, (float) $g->credit);
            }
        }

        /* ---------- sort: account, date, id ---------- */
        usort($rows, fn ($a, $b) =>
            [$a['account_code'], $a['sort_date'], $a['id']] <=> [$b['account_code'], $b['sort_date'], $b['id']]);

        return $rows;
    }
    /* GET /api/ledgers/general?filter_type=DM&from=2026-01&to=2026-09&account_code=&branch=current
       rows      = entries in the period (Closing Entry CE-… included: General Ledger only)
       beginning = { account_code: { name, balance, debit, credit } } as of the first day of the period
                   balance = debit − credit → a debit balance shows in Debit, a credit balance in Credit
       rows[].balance = running balance of the account after that line  */
    public function generalLedger(Request $request){
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DR,DM,DE'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['nullable', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => [], 'beginning' => (object) []]);

        // General Ledger is the only report that shows the Closing Entry (CE-…)
        $rows = $this->ledgerEntries($ids, $d['filter_type'], $d['from'], $d['to'] ?? $d['from'], ['closing' => true]);
        $beginning = $this->glBeginning($ids, $d['filter_type'], $d['from']);

        if (!empty($d['account_code']) && $d['account_code'] !== '0') {
            $code = (string) $d['account_code'];
            $rows = array_values(array_filter($rows, fn ($r) => $r['account_code'] === $code));
            $beginning = array_intersect_key($beginning, [$code => true]);
        }
        // running balance per account: beginning + debit − credit, line by line (rows are sorted by account, date)
        $run = [];
        $rows = array_map(function ($r) use (&$run, $beginning) {
            $code = (string) $r['account_code'];
            $run[$code] ??= (float) ($beginning[$code]['balance'] ?? 0);
            $run[$code] += (float) $r['debit'] - (float) $r['credit'];
            $r['balance'] = round($run[$code], 2);
            unset($r['sort_date'], $r['declared_month']);
            return $r;
        }, $rows);

        return response()->json([
            'company'   => $this->companyHeader($client),
            'branches'  => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'rows'      => $rows,
            'beginning' => (object) $beginning,
            'balance_sign' => 'debit',   // balance = debit − credit (a credit balance is negative)
        ]);
    }

    /**
     * Beginning balance per account as of the first day of the period:
     *   beginning balances (t_beg_balance_accounts, declared month before the period)
     * + every ledger entry after those balances and before the period (Closing Entry included,
     *   so last year's Income Statement accounts start the new year at zero).
     * Balance = debit − credit (debit balance positive, credit balance negative).
     */
    private function glBeginning(array $ids, string $type, string $from): array
    {
        if (!$ids || !$from) return [];

        $fromMonth = substr($from, 0, 7);
        $bal = [];
        $add = function ($code, $debit, $credit) use (&$bal) {
            $code = (string) $code;
            if ($code === '') return;
            $bal[$code] = ($bal[$code] ?? 0) + (float) $debit - (float) $credit;
        };

        // 1) beginning balances before the period
        $begRows = BegBalanceAccount::whereIn('added_by', $ids)
            ->where('declared_month', '<', $fromMonth)
            ->selectRaw('account_code, SUM(COALESCE(assets_expenses,0)) AS d, SUM(COALESCE(liability_equity_income,0)) AS c, MAX(declared_month) AS last_month')
            ->groupBy('account_code')
            ->get();
        $lastBegMonth = null;
        foreach ($begRows as $b) {
            $add($b->account_code, $b->d, $b->c);
            if ($b->last_month && (!$lastBegMonth || $b->last_month > $lastBegMonth)) $lastBegMonth = substr((string) $b->last_month, 0, 7);
        }

        // 2) activity after the beginning balances, up to the day/month before the period
        $startMonth = $lastBegMonth
            ? Carbon::createFromFormat('Y-m-d', "{$lastBegMonth}-01")->addMonth()->format('Y-m')
            : '1900-01';

        if ($type === 'DM') {
            $prevMonth = Carbon::createFromFormat('Y-m-d', "{$fromMonth}-01")->subMonth()->format('Y-m');
            $before = $startMonth <= $prevMonth
                ? $this->ledgerEntries($ids, 'DM', $startMonth, $prevMonth, ['closing' => true])
                : [];
        } else {                                                    // DR / DE: by transaction date
            $fromDate = substr($from, 0, 10);
            if (strlen($fromDate) === 7) $fromDate .= '-01';
            $prevDate = Carbon::parse($fromDate)->subDay()->format('Y-m-d');
            $startDate = "{$startMonth}-01";
            $before = $startDate <= $prevDate
                ? $this->ledgerEntries($ids, 'DR', $startDate, $prevDate, ['closing' => true])
                : [];
        }
        foreach ($before as $e) $add($e['account_code'], $e['debit'] ?? 0, $e['credit'] ?? 0);

        // names for accounts that only have a beginning balance
        $names = ChartsOfAccount::whereIn('added_by', $ids)->pluck('account_name', 'account_code');
        foreach ($before as $e) if (!isset($names[$e['account_code']])) $names[$e['account_code']] = $e['account_name'] ?? '';

        $out = [];
        ksort($bal, SORT_STRING);
        foreach ($bal as $code => $v) {
            $v = round($v, 2);
            if (abs($v) < 0.005) continue;
            $out[$code] = ['name' => (string) ($names[$code] ?? $code), 'balance' => $v,
                'debit' => $v > 0 ? $v : 0.0, 'credit' => $v < 0 ? -$v : 0.0];
        }
        return $out;
    }
    public function summaryLedger(Request $request){
        $d = $request->validate([
            'year'         => ['required', 'digits:4'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        $year = $d['year'];

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        // old: DeclaredMonth LIKE 'YYYY-%'  (no collections in the old summary)
        $rows = $this->ledgerEntries($ids, 'DM', "{$year}-01", "{$year}-12", ['collections' => false]);

        if (!empty($d['account_code']) && $d['account_code'] !== '0') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['account_code'] === (string) $d['account_code']));
        }

        // one line per account: Debit - Credit per month
        $summary = [];
        foreach ($rows as $r) {
            $code = $r['account_code'];
            $summary[$code] ??= [
                'account_code' => $code,
                'account_name' => $r['account_name'],
                'months'       => array_fill(1, 12, 0.0),
                'total'        => 0.0,
            ];
            $month  = (int) substr($r['declared_month'], 5, 2);
            $amount = (float) $r['debit'] - (float) $r['credit'];
            if ($month >= 1 && $month <= 12) {
                $summary[$code]['months'][$month] += $amount;
            }
            $summary[$code]['total'] += $amount;
        }

        $list = array_map(function ($s) {
            $s['months'] = array_map(fn ($v) => round($v, 2), $s['months']);
            $s['total']  = round($s['total'], 2);
            return $s;
        }, array_values($summary));

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'year'     => (int) $year,
            'rows'     => $list,
        ]);
    }
    public function customerLedger(Request $request)
    {
        return $this->partyLedger($request, 'customer');
    }

    public function vendorLedger(Request $request)
    {
        return $this->partyLedger($request, 'vendor');
    }
    private function partyLedger(Request $request, string $party)
    {
        $key = $party === 'customer' ? 'customer_id' : 'vendor_id';

        $d = $request->validate([
            'filter_type' => ['required', 'in:DR,DM,DE'],
            'from'        => ['required', 'string', 'max:10'],
            'to'          => ['nullable', 'string', 'max:10'],
            $key          => ['required', 'integer', 'min:1'],
            'branch'      => ['nullable', 'string', 'max:20'],
        ], [
            "{$key}.required" => 'Choose a ' . $party . '.',
            "{$key}.min"      => 'Choose a ' . $party . '.',
        ]);

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $rows = $this->ledgerEntries($ids, $d['filter_type'], $d['from'], $d['to'] ?? $d['from'], $party === 'customer'
            ? ['only' => ['INV'], 'customer_id' => (int) $d[$key]]                 // old: invoices only
            : ['only' => ['PO', 'AP', 'GRN'], 'vendor_id' => (int) $d[$key]]);     // old: PO + AP + GRN

        $rows = array_map(function ($r) { unset($r['sort_date'], $r['declared_month']); return $r; }, $rows);

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'rows'     => $rows,
        ]);
    }
        /* ---------- Trial balance totals per account (old doGetTrialBalanceList) ---------- */
    private array $tbUnbalanced = [];
    private function trialBalanceRows(array $ids, string $type, string $from, string $to, array $opt = []): array
    {
        // old trial balance: no collections, no cost of sales (normal + adjusting journal entries unless $opt['gj'] says otherwise)
        $entries = $this->ledgerEntries($ids, $type, $from, $to, ['collections' => false] + $opt);

        $modules = ['INV' => 'Sales / Invoices', 'PO' => 'Purchase Order', 'GRN' => 'Receive Inventory', 'GJ' => 'General Journal'];
        $acc = [];
        $docBal = [];
        foreach ($entries as $e) {
            if (($e['jrnl'] ?? '') === 'COG') continue;

            $code = $e['account_code'];
            $acc[$code] ??= [
                'account_code' => $code,
                'account_name' => $e['account_name'],
                'debit'        => 0.0,
                'credit'       => 0.0,
                'docs'         => [],
            ];
            $acc[$code]['debit']  += (float) $e['debit'];
            $acc[$code]['credit'] += (float) $e['credit'];

            // documents behind this account (old "View" popup)
            $key = $e['trans_type'] . '|' . $e['ref_data'];
            $acc[$code]['docs'][$key] ??= [
                'date'        => $e['sort_date'],
                'document_no' => $e['reference'],
                'reference'   => $e['ref_data'],
                'name'        => $e['party'] ?? '',
                'module'      => $e['trans_type'] === 'AP'
                    ? ($e['jrnl'] === 'PJ' ? 'Purchases' : 'Expenses')
                    : ($modules[$e['trans_type']] ?? $e['trans_type']),
                'amount'      => 0.0,
            ];
            $acc[$code]['docs'][$key]['amount'] += (float) $e['debit'] - (float) $e['credit'];
            $docBal[$key] ??= ['module' => $acc[$code]['docs'][$key]['module'], 'trans_type' => $e['trans_type'], 'date' => $e['sort_date'],
                'document_no' => $e['reference'], 'reference' => $e['ref_data'], 'name' => $e['party'] ?? '', 'debit' => 0.0, 'credit' => 0.0];
            $docBal[$key]['debit'] += (float) $e['debit'];
            $docBal[$key]['credit'] += (float) $e['credit'];
        }
        $this->tbUnbalanced = collect($docBal)
            ->map(fn ($d) => ['debit' => round($d['debit'], 4), 'credit' => round($d['credit'], 4), 'difference' => round($d['debit'] - $d['credit'], 4)] + $d)
            ->filter(fn ($d) => abs($d['difference']) >= 0.005)
            ->sortByDesc(fn ($d) => abs($d['difference']))->values()->take(500)->all();

        ksort($acc, SORT_STRING);

        return array_values(array_map(function ($a) {
            $a['debit']   = round($a['debit'], 4);
            $a['credit']  = round($a['credit'], 4);
            $a['balance'] = round($a['debit'] - $a['credit'], 4);
            $a['docs']    = collect($a['docs'])
                ->map(fn ($d) => ['amount' => round(abs($d['amount']), 2)] + $d)
                ->sortBy('date')->values()->all();
            return $a;
        }, $acc));
    }

    /* GET /api/reports/trial-balance?filter_type=DM&from=2026-01&to=2026-09&account_code=&branch=all */
    public function trialBalance(Request $request)
    {
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DR,DM'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['nullable', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['rows' => [], 'branches' => []]);

        $rows = $this->trialBalanceRows($ids, $d['filter_type'], $d['from'], $d['to'] ?? $d['from']);

        if (!empty($d['account_code']) && $d['account_code'] !== '0') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['account_code'] === (string) $d['account_code']));
        }

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'rows'     => $rows,
            'unbalanced' => $this->tbUnbalanced,
        ]);
    }

    public function balanceSheet(Request $request){
        $d = $request->validate([
            'year_from' => ['required', 'digits:4'],
            'year_to'   => ['required', 'digits:4'],
            'branch'    => ['nullable', 'string', 'max:20'],
        ]);
        $from  = (int) min($d['year_from'], $d['year_to']);
        $to    = (int) max($d['year_from'], $d['year_to']);
        $years = range($from, min($to, $from + 9));   // at most 10 years

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');

        $sections = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $zero     = array_fill_keys($years, 0.0);
        $totals   = [
            'current_assets'      => $zero,
            'current_liabilities' => $zero,
            'retained_earnings'   => $zero,
            'liabilities_equity'  => $zero,
        ];

        $addTo = function (string $sec, string $code, string $name, int $year, float $amt) use (&$sections, $zero) {
            $key = $code . '|' . $name;
            $sections[$sec][$key] ??= ['code' => $code, 'name' => $name, 'amounts' => $zero];
            $sections[$sec][$key]['amounts'][$year] += $amt;
        };

        foreach ($years as $year) {
            $rows = $ids ? $this->trialBalanceRows($ids, 'DR', "{$year}-01-01", "{$year}-12-31") : [];

            $income = $cos = $expense = $otherIncome = $otherExpense = 0.0;

            foreach ($rows as $r) {
                $bal = (float) $r['balance'];
                switch (intdiv((int) $r['account_code'], 10000)) {
                    case 1: // assets (debit-normal)
                        $addTo('assets', $r['account_code'], $r['account_name'], $year, $bal);
                        $totals['current_assets'][$year] += $bal;
                        break;
                    case 2: // liabilities (credit-normal → flip)
                        $addTo('liabilities', $r['account_code'], $r['account_name'], $year, -$bal);
                        $totals['current_liabilities'][$year] += -$bal;
                        break;
                    case 3: // equity (credit-normal → flip)
                        $addTo('equity', $r['account_code'], $r['account_name'], $year, -$bal);
                        $totals['retained_earnings'][$year] += -$bal;
                        break;
                    case 4: $income       += -$bal; break;
                    case 5: $cos          += $bal;  break;
                    case 6: $expense      += $bal;  break;
                    case 7: $otherIncome  += -$bal; break;
                    case 8: $otherExpense += $bal;  break;
                }
            }

            $net = $income - $cos - $expense + $otherIncome - $otherExpense;
            $addTo('equity', '', 'Net income for the year', $year, $net);
            $totals['retained_earnings'][$year] += $net;
            $totals['liabilities_equity'][$year] = $totals['current_liabilities'][$year] + $totals['retained_earnings'][$year];
        }

        $sort = fn ($list) => collect($list)->sortBy(fn ($r) => $r['code'] === '' ? 'zzzz' : $r['code'])->values()->all();

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'years'    => $years,
            'sections' => [
                'assets'      => $sort($sections['assets']),
                'liabilities' => $sort($sections['liabilities']),
                'equity'      => $sort($sections['equity']),
            ],
            'totals'   => $totals,
        ]);
    }
        /* ---------- account section by code (old wtb_SectionForCode) ---------- */
    private function wtbSection(string $code): array
    {
        return match (intdiv((int) $code, 10000)) {
            1 => ['ASSET', 'BS'],
            2 => ['LIABILITIES', 'BS'],
            3 => ['OWNERS EQUITY', 'BS'],
            4 => ['REVENUE', 'IS'],
            5 => ['COS', 'IS'],
            6 => ['EXPENSES', 'IS'],
            7 => ['OTHER INCOME', 'IS'],
            8 => ['OTHER EXPENSE', 'IS'],
            default => ['OTHER', 'BS'],
        };
    }

    /**
     * Working Trial Balance rows of one year (also used by the Financial Statement):
     *   BEG.      = balance as of Jan 1 (General Ledger beginning: t_beg_balance_accounts + every entry before the year,
     *               incl. past years' Closing Entry) — a typed Beg. value on the page replaces it
     *   DATA      = this year's activity (declared month Jan–Dec) without the adjusting journal entries
     *   ADJUSTING = this year's ADJUSTING journal entries (General Journal → Adjusting Entry, Fixed Asset depreciation)
     */
    private function wtbRows(array $ids, ?int $caid, int $year): array
    {
        if (!$ids) return [];
        $activity = collect($this->trialBalanceRows($ids, 'DM', "{$year}-01", "{$year}-12", ['gj' => 'normal']))->keyBy('account_code');
        $adjusting = collect($this->trialBalanceRows($ids, 'DM', "{$year}-01", "{$year}-12", ['gj' => 'adjusting', 'only' => ['GJ']]))->keyBy('account_code');

        // glBeginning() is debit − credit → debit balance in Beg. Debit, credit balance in Beg. Credit
        $beg = collect($this->glBeginning($ids, 'DM', "{$year}-01"))
            ->mapWithKeys(fn ($b, $code) => [(string) $code => (object) ['d' => (float) $b['debit'], 'c' => (float) $b['credit'], 'name' => $b['name']]]);

        // typed Beg. Balance values on this page
        $manual = $caid ? WtbManual::where('caid', $caid)->where('period', (string) $year)->get()->keyBy(fn ($r) => (string) $r->account_code) : collect();
        $manual = $manual->filter(fn ($m) => $m->beg_debit !== null || $m->beg_credit !== null);

        $names = ChartsOfAccount::whereIn('added_by', $ids)->pluck('account_name', 'account_code');

        $codes = $activity->keys()->merge($adjusting->keys())->merge($beg->keys())->merge($manual->keys())
            ->map(fn ($c) => (string) $c)->unique()->sort()->values();

        return $codes->map(function ($code) use ($activity, $adjusting, $beg, $manual, $names) {
            $a = $activity[$code] ?? null;
            $j = $adjusting[$code] ?? null;
            $b = $beg[$code] ?? null;
            $m = $manual[$code] ?? null;
            [$section, $stmt] = $this->wtbSection($code);

            return [
                'account_code' => $code,
                'account_name' => $names[$code] ?? ($a['account_name'] ?? ($j['account_name'] ?? ($b->name ?? $code))),
                'section'      => $section,
                'statement'    => $stmt,
                'beg_debit'    => $m && $m->beg_debit !== null ? (float) $m->beg_debit : (float) ($b->d ?? 0),
                'beg_credit'   => $m && $m->beg_credit !== null ? (float) $m->beg_credit : (float) ($b->c ?? 0),
                'data_debit'   => (float) ($a['debit'] ?? 0),
                'data_credit'  => (float) ($a['credit'] ?? 0),
                // from General Journal → Adjusting Entry (read-only on the page; click to open the entries)
                'adj_debit'    => round((float) ($j['debit'] ?? 0), 2),
                'adj_credit'   => round((float) ($j['credit'] ?? 0), 2),
                'adj_count'    => $j ? count($j['docs'] ?? []) : 0,
            ];
        })->values()->all();
    }

    /** account balances (debit − credit) from the Working Trial Balance of a year: 'final' = beg + data + adjusting, 'movement' = data + adjusting */
    private function wtbBalances(array $ids, ?int $caid, int $year, string $kind = 'final'): array
    {
        $out = [];
        foreach ($this->wtbRows($ids, $caid, $year) as $r) {
            $bal = ($r['data_debit'] - $r['data_credit']) + ($r['adj_debit'] - $r['adj_credit']);
            if ($kind === 'final') $bal += $r['beg_debit'] - $r['beg_credit'];
            if (abs($bal) < 0.005) continue;
            $out[$r['account_code']] = ['code' => $r['account_code'], 'name' => $r['account_name'], 'balance' => round($bal, 2)];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /* GET /api/reports/working-trial-balance?year=2026&branch=current  (old doGetWorkingTrialBalanceFull) */
    public function workingTrialBalance(Request $request)
    {
        $d = $request->validate([
            'year'   => ['required', 'digits:4'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        $year = (int) $d['year'];

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'year'     => $year,
            'adjusting_from' => 'general_journal',   // ADJUSTING ENTRIES columns come from General Journal → Adjusting Entry
            'rows'     => $this->wtbRows($ids, $this->caidFor($request) ?: null, $year),
        ]);
    }

    /* POST /api/reports/working-trial-balance  { year, account_code, field, value }  (old doSaveWTBManual) */
    public function saveWorkingTrialBalance(Request $request)
    {
        $d = $request->validate([
            'year'         => ['required', 'digits:4'],
            'account_code' => ['required', 'string', 'max:20'],
            'field'        => ['required', 'in:beg_debit,beg_credit'],   // adjusting entries come from the General Journal
            'value'        => ['nullable', 'numeric'],
        ]);

        $row = WtbManual::updateOrCreate(
            [
                'caid'         => $this->caidFor($request),
                'period'       => (string) $d['year'],
                'account_code' => $d['account_code'],
            ],
            [
                $d['field'] => round((float) ($d['value'] ?? 0), 4),
                'added_by'  => $this->addedByFor($request),
            ]
        );

        return response()->json(['ok' => true, 'row' => $row]);
    }
    public function consolidatedReport(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to'   => ['required', 'date_format:Y-m'],
        ]);
        [$from, $to] = $d['from'] <= $d['to'] ? [$d['from'], $d['to']] : [$d['to'], $d['from']];

        // old: every user of every branch of this client
        [$ids, $client, $branches] = $this->journalScope($request, 'all');
        if (!$ids) {
            return response()->json(['company' => $this->companyHeader($client), 'users' => [], 'rows' => [], 'from' => $from, 'to' => $to]);
        }

        $branchLabel = $branches->mapWithKeys(fn ($b) => [$b->id => trim("{$b->branch_code} {$b->trade_name}")]);
        $users = UserClient::whereIn('id', $ids)->orderBy('id')->get(['id', 'username', 'caid'])
            ->map(fn ($u) => [
                'id'       => (int) $u->id,
                'username' => $u->username,
                'branch'   => $branchLabel[$u->caid] ?? '',
            ])->values();

        $net = fn (string $p = '') => DB::raw(
            "SUM(COALESCE({$p}net_of_vat,0) + COALESCE({$p}vat_exempt,0) + COALESCE({$p}zero_rated,0) + COALESCE({$p}non_vat,0)) AS amount"
        );

        // Sales – posted invoice lines
        $sales = SalesInvoiceDetail::whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)
            ->where('trans_type', 'posted')
            ->whereBetween('declared_month', [$from, $to])
            ->groupBy('added_by')
            ->select('added_by', $net())
            ->pluck('amount', 'added_by');

        // Purchases – GRN lines (by receiver) + AP purchase lines
        $grn = DB::table('t_grn_detail as d')
            ->join('t_grn_master as m', 'm.grn_no', '=', 'd.grn_no')
            ->whereIn('m.receive_by', $ids)
            ->whereBetween('m.declared_month', [$from, $to])
            ->groupBy('m.receive_by')
            ->select('m.receive_by as uid', $net('d.'))
            ->pluck('amount', 'uid');

        $apPurchases = PurchaseExpenseDetail::purchases()->active()->where('record_status', 'posted')
            ->whereIn('added_by', $ids)
            ->whereBetween('declared_month', [$from, $to])
            ->groupBy('added_by')
            ->select('added_by', $net())
            ->pluck('amount', 'added_by');

        // Expenses – AP expense lines
        $expenses = PurchaseExpenseDetail::expenses()->active()->where('record_status', 'posted')
            ->whereIn('added_by', $ids)
            ->whereBetween('declared_month', [$from, $to])
            ->groupBy('added_by')
            ->select('added_by', $net())
            ->pluck('amount', 'added_by');

        $line = function (string $label, callable $valueFor) use ($users) {
            $values = [];
            foreach ($users as $u) {
                $values[$u['id']] = round((float) $valueFor($u['id']), 2);
            }
            return ['label' => $label, 'values' => $values, 'total' => round(array_sum($values), 2)];
        };

        $rows = [
            $line('Sales', fn ($id) => $sales[$id] ?? 0),
            $line('Purchases', fn ($id) => ($grn[$id] ?? 0) + ($apPurchases[$id] ?? 0)),
            $line('Expenses', fn ($id) => $expenses[$id] ?? 0),
        ];

        $colTotals = [];
        foreach ($users as $u) {
            $colTotals[$u['id']] = round(array_sum(array_map(fn ($r) => $r['values'][$u['id']], $rows)), 2);
        }

        return response()->json([
            'company' => $this->companyHeader($client),
            'from'    => $from,
            'to'      => $to,
            'users'   => $users,
            'rows'    => $rows,
            'totals'  => ['values' => $colTotals, 'total' => round(array_sum($colTotals), 2)],
        ]);
    }
        /* ================= Annual Summary (old doGetIncomeStatement) ================= */

    private function monthList(string $from, string $to): array
    {
        $out = [];
        $d = Carbon::createFromFormat('Y-m-d', "{$from}-01");
        while ($d->format('Y-m') <= $to) {
            $out[] = $d->format('Y-m');
            $d->addMonth();
        }
        return $out;
    }

    protected function partyNames($ids)
    {
        return VendorCustomer::whereIn('id', collect($ids)->filter()->unique()->values())->get()
            ->mapWithKeys(fn ($v) => [$v->id => (string) ($v->name ?? $v->vendor_name ?? $v->registered_name ?? $v->trade_name ?? '')]);
    }

    /* GET /api/reports/annual-summary?year_from=2026&year_to=2026&branch=current */
    public function annualSummary(Request $request)
    {
        $d = $request->validate([
            'year_from' => ['required', 'digits:4'],
            'year_to'   => ['required', 'digits:4'],
            'branch'    => ['nullable', 'string', 'max:20'],
        ]);
        $yf = (int) min($d['year_from'], $d['year_to']);
        $yt = min((int) max($d['year_from'], $d['year_to']), $yf + 4);   // max 5 years
        $from = "{$yf}-01";
        $to   = "{$yt}-12";
        $months = $this->monthList($from, $to);

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        $branchList = $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values();

        if (!$ids) {
            return response()->json(['company' => $this->companyHeader($client), 'branches' => $branchList, 'months' => $months, 'lines' => []]);
        }

        $n = fn (string $p = '') => sprintf(
            'COALESCE(%1$snet_of_vat,0)+COALESCE(%1$svat_exempt,0)+COALESCE(%1$snon_vat,0)+COALESCE(%1$szero_rated,0)', $p
        );

        /* ---------- a few grouped queries (account × month) ---------- */
        $sales = SalesInvoiceDetail::whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
            ->whereBetween('declared_month', [$from, $to])
            ->groupBy('account_code', 'declared_month')
            ->selectRaw("account_code AS code, declared_month AS m,
                SUM(({$n()}) * COALESCE(NULLIF(ex_rate,0),1)) AS amt,
                SUM(COALESCE(input_vat,0) * COALESCE(NULLIF(ex_rate,0),1)) AS vat")
            ->toBase()->get();

        $grn = DB::table('t_grn_detail as d')
            ->join('t_grn_master as g', 'g.grn_no', '=', 'd.grn_no')
            ->whereIn('g.receive_by', $ids)
            ->whereBetween('g.declared_month', [$from, $to])
            ->groupBy('d.account_code', 'g.declared_month')
            ->selectRaw("d.account_code AS code, g.declared_month AS m, SUM({$n('d.')}) AS amt, SUM(COALESCE(d.input_vat,0)) AS vat")
            ->get();

        $ap = fn (string $scope) => PurchaseExpenseDetail::query()->{$scope}()->active()->where('record_status', 'posted')
            ->whereIn('added_by', $ids)
            ->whereBetween('declared_month', [$from, $to])
            ->groupBy('account_code', 'declared_month')
            ->selectRaw("account_code AS code, declared_month AS m, SUM({$n()}) AS amt, SUM(COALESCE(input_vat,0)) AS vat")
            ->toBase()->get();
        $apP = $ap('purchases');
        $apE = $ap('expenses');

        $beg = BegBalanceProduct::whereIn('added_by', $ids)
            ->whereBetween('declared_month', [$from, $to])
            ->groupBy('declared_month')
            ->selectRaw('declared_month AS m, SUM(total_cost) AS amt')
            ->toBase()->pluck('amt', 'm');

        $ewtGrn = GRNMaster::whereIn('receive_by', $ids)
            ->whereBetween('declared_month', [$from, $to])->where('2307_it', '>', 0)
            ->groupBy('declared_month')->selectRaw('declared_month AS m, SUM(`2307_it`) AS amt')
            ->toBase()->pluck('amt', 'm');

        $ewtAp = fn (string $scope) => PurchaseExpenseMaster::query()->{$scope}()->active()->where('record_status', 'posted')
            ->whereIn('added_by', $ids)
            ->whereBetween('declared_month', [$from, $to])->where('2307_it', '>', 0)
            ->groupBy('declared_month')->selectRaw('declared_month AS m, SUM(`2307_it`) AS amt')
            ->toBase()->pluck('amt', 'm');

        /* ---------- helpers ---------- */
        $zero = array_fill_keys($months, 0.0);

        $byAccount = function ($rows, callable $keep, string $field = 'amt') use ($zero) {
            $out = [];
            foreach ($rows as $r) {
                $code = (string) $r->code;
                if (!array_key_exists($r->m, $zero) || !$keep($code)) continue;
                $out[$code] ??= $zero;
                $out[$code][$r->m] += (float) $r->{$field};
            }
            return $out;
        };
        $mergeAcc = function (array ...$maps) use ($zero) {
            $out = [];
            foreach ($maps as $map) {
                foreach ($map as $code => $vals) {
                    $out[$code] ??= $zero;
                    foreach ($vals as $m => $v) $out[$code][$m] += $v;
                }
            }
            ksort($out, SORT_STRING);
            return $out;
        };
        $monthly = function ($rows, string $field = 'amt') use ($zero) {
            $out = $zero;
            foreach ($rows as $r) if (array_key_exists($r->m, $out)) $out[$r->m] += (float) $r->{$field};
            return $out;
        };
        $fromPluck = function ($col) use ($zero) {
            $out = $zero;
            foreach ($col as $m => $v) if (array_key_exists($m, $out)) $out[$m] += (float) $v;
            return $out;
        };
        $plus = function (array ...$sets) use ($zero) {
            $out = $zero;
            foreach ($sets as $s) foreach ($s as $m => $v) $out[$m] += $v;
            return $out;
        };
        $minus = fn (array $a, array $b) => array_map(fn ($x, $y) => $x - $y, $a, $b) ? array_combine(array_keys($a), array_map(fn ($x, $y) => $x - $y, $a, $b)) : $a;

        $all    = fn ($c) => true;
        $not6   = fn ($c) => !str_starts_with($c, '6');
        $is6    = fn ($c) => str_starts_with($c, '6');
        $is4to8 = fn ($c) => in_array(substr($c, 0, 1), ['4', '5', '6', '7', '8'], true);

        $names = ChartsOfAccount::whereIn('added_by', $ids)->pluck('account_name', 'account_code');

        /* ---------- build the lines ---------- */
        $lines = [];
        $line = function (string $kind, string $label, ?array $vals = null, ?array $drill = null, ?string $code = null) use (&$lines) {
            $lines[] = [
                'kind'   => $kind,                       // head | account | row | total | rate | blank
                'label'  => $label,
                'code'   => $code,
                'values' => $vals === null ? null : array_map(fn ($v) => round($v, 2), $vals),
                'total'  => $vals === null ? null : round(array_sum($vals), 2),
                'drill'  => $drill,
            ];
        };
        $accounts = function (array $map, string $type) use ($line, $names) {
            ksort($map, SORT_STRING);
            foreach ($map as $code => $vals) {
                $line('account', (string) ($names[$code] ?? $code), $vals, ['type' => $type, 'account' => (string) $code], (string) $code);
            }
        };
        $sumMap = fn (array $map) => $plus(...array_values($map));

        // SALES
        $salesAcc = $byAccount($sales, $all);
        $line('head', 'SALES');
        $accounts($salesAcc, 'SALES');
        $totalSales = $sumMap($salesAcc);
        $line('total', 'TOTAL SALES', $totalSales);

        // COST OF SALES
        $begInv   = $fromPluck($beg);
        $cogsAcc  = $mergeAcc($byAccount($grn, $not6), $byAccount($apP, $not6));
        $purch    = $sumMap($cogsAcc);
        $ending   = $zero;   // old formula always ends at 0 (no ending count yet)
        $tcogs    = $minus($plus($begInv, $purch), $ending);

        $line('head', 'COST OF SALES');
        $line('row', 'Merchandise Inventory, Beginning', $begInv);
        $accounts($cogsAcc, 'COGS');
        $line('total', 'Total cost of goods available for sale', $purch);
        $line('row', 'Merchandise Inventory, Ending', $ending);
        $line('total', 'TOTAL COST OF GOODS SOLD', $tcogs);
        $line('blank', '');

        // GROSS
        $gross = $minus($totalSales, $tcogs);
        $line('total', 'GROSS AMOUNT', $gross);
        $rates = [];
        foreach ($months as $m) $rates[$m] = $totalSales[$m] != 0 ? round($gross[$m] / $totalSales[$m], 4) : 0;
        $lines[] = [
            'kind' => 'rate', 'label' => 'Gross Profit Rate', 'code' => null, 'values' => $rates,
            'total' => array_sum($totalSales) != 0 ? round(array_sum($gross) / array_sum($totalSales), 4) : 0, 'drill' => null,
        ];

        // 1601-C – EXPENSES
        $expAcc = $mergeAcc($byAccount($apE, $is4to8), $byAccount($apP, $is6));
        $totalExp = $sumMap($expAcc);
        $line('head', '1601-C');
        $accounts($expAcc, 'EXP');
        $line('total', 'TOTAL EXPENSES', $totalExp);
        $line('total', 'INCOME SUBJECT TO INCOME TAX', $minus($gross, $totalExp));

        // 2550-M / 2550-Q – VAT
        $outVat  = $monthly($sales, 'vat');
        $inVatP  = $plus($monthly($grn, 'vat'), $monthly($apP, 'vat'));
        $inVatE  = $monthly($apE, 'vat');
        $inVat   = $plus($inVatP, $inVatE);
        $line('head', '2550-M/2550-Q');
        $line('row', 'Output VAT (Sales)', $outVat, ['type' => 'OUTPUT']);
        $line('row', 'Input VAT (Purchases)', $inVatP, ['type' => 'INPUT_P']);
        $line('row', 'Input VAT (Expenses)', $inVatE, ['type' => 'INPUT_E']);
        $line('total', 'Total Input VAT', $inVat);
        $line('total', 'Payable / (Excess)', $minus($outVat, $inVat));

        // 0619-E / 1601-EQ – EWT
        $ewtP = $plus($fromPluck($ewtGrn), $fromPluck($ewtAp('purchases')));
        $ewtE = $fromPluck($ewtAp('expenses'));
        $line('head', '0619-E/1601-EQ');
        $line('row', 'EWT (Purchases)', $ewtP, ['type' => 'EWT_P']);
        $line('row', 'EWT (Expenses)', $ewtE, ['type' => 'EWT_E']);
        $line('total', 'Total EWT', $plus($ewtP, $ewtE));

        return response()->json([
            'company'  => $this->companyHeader($client),
            'branches' => $branchList,
            'months'   => $months,
            'lines'    => $lines,
        ]);
    }
    public function annualSummaryDocs(Request $request)
    {
        $d = $request->validate([
            'type'    => ['required', 'in:SALES,OUTPUT,COGS,EXP,INPUT_P,INPUT_E,EWT_P,EWT_E'],
            'month'   => ['required', 'date_format:Y-m'],
            'account' => ['nullable', 'string', 'max:20'],
            'branch'  => ['nullable', 'string', 'max:20'],
        ]);
        [$ids] = $this->journalScope($request, $d['branch'] ?? 'current');
        if (!$ids) return response()->json(['docs' => []]);

        $m    = $d['month'];
        $acct = $d['account'] ?? null;

        $sales = function (callable $extra) use ($ids, $m) {
            $nos = SalesInvoiceDetail::whereIn('added_by', $ids)
                ->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
                ->where('declared_month', $m)->where($extra)->distinct()->pluck('invoice_no');
            return SalesInvoiceMaster::whereIn('invoice_no', $nos)
                ->get(['invoice_no', 'document_no', 'transaction_date', 'customer_id', 'grand_total', 'declared_month'])
                ->map(fn ($x) => [
                    'module' => 'Sales', 'ref' => $x->invoice_no, 'document_no' => $x->document_no,
                    'date' => optional($x->transaction_date)->format('Y-m-d'), 'declared_month' => $x->declared_month,
                    'party_id' => $x->customer_id, 'total' => (float) $x->grand_total, 'open' => 'sales',
                ]);
        };
        $apMasters = fn ($q) => $q->get(['invoice_no', 'document_no', 'transaction_date', 'vendor_id', 'grand_total', 'declared_month', 'trans_type'])
            ->map(fn ($x) => [
                'module' => ucfirst((string) $x->trans_type), 'ref' => $x->invoice_no, 'document_no' => $x->document_no,
                'date' => optional($x->transaction_date)->format('Y-m-d'), 'declared_month' => $x->declared_month,
                'party_id' => $x->vendor_id, 'total' => (float) $x->grand_total, 'open' => null,
            ]);
        $ap = function (string $scope, callable $extra) use ($ids, $m, $apMasters) {
            $nos = PurchaseExpenseDetail::query()->{$scope}()->active()->where('record_status', 'posted')
                ->whereIn('added_by', $ids)->where('declared_month', $m)->where($extra)->distinct()->pluck('invoice_no');
            return $apMasters(PurchaseExpenseMaster::whereIn('invoice_no', $nos)->whereIn('added_by', $ids));
        };
        $grnMasters = fn ($q) => $q->get(['grn_no', 'grn_date', 'vendor_id', 'grand_total', 'declared_month'])
            ->map(fn ($x) => [
                'module' => 'Receive Inventory', 'ref' => $x->grn_no, 'document_no' => $x->grn_no,
                'date' => optional($x->grn_date)->format('Y-m-d'), 'declared_month' => $x->declared_month,
                'party_id' => $x->vendor_id, 'total' => (float) $x->grand_total, 'open' => null,
            ]);
        $grn = function (callable $extra) use ($ids, $m, $grnMasters) {
            $nos = DB::table('t_grn_detail as d')->join('t_grn_master as g', 'g.grn_no', '=', 'd.grn_no')
                ->whereIn('g.receive_by', $ids)->where('g.declared_month', $m)->where($extra)->distinct()->pluck('g.grn_no');
            return $grnMasters(GRNMaster::whereIn('grn_no', $nos));
        };

        $docs = match ($d['type']) {
            'SALES'   => $sales(fn ($q) => $q->where('account_code', $acct)),
            'OUTPUT'  => $sales(fn ($q) => $q->where('input_vat', '>', 0)),
            'COGS'    => $grn(fn ($q) => $q->where('d.account_code', $acct))
                            ->concat($ap('purchases', fn ($q) => $q->where('account_code', $acct))),
            'EXP'     => $ap('expenses', fn ($q) => $q->where('account_code', $acct))
                            ->concat($ap('purchases', fn ($q) => $q->where('account_code', $acct))),
            'INPUT_P' => $grn(fn ($q) => $q->where('d.input_vat', '>', 0))
                            ->concat($ap('purchases', fn ($q) => $q->where('input_vat', '>', 0))),
            'INPUT_E' => $ap('expenses', fn ($q) => $q->where('input_vat', '>', 0)),
            'EWT_P'   => $grnMasters(GRNMaster::whereIn('receive_by', $ids)->where('declared_month', $m)->where('2307_it', '>', 0))
                            ->concat($apMasters(PurchaseExpenseMaster::purchases()->active()->where('record_status', 'posted')->whereIn('added_by', $ids)->where('declared_month', $m)->where('2307_it', '>', 0))),
            'EWT_E'   => $apMasters(PurchaseExpenseMaster::expenses()->active()->where('record_status', 'posted')->whereIn('added_by', $ids)->where('declared_month', $m)->where('2307_it', '>', 0)),
        };

        $names = $this->partyNames($docs->pluck('party_id'));
        $docs = $docs->map(fn ($x) => ['name' => $names[$x['party_id']] ?? ''] + $x)->sortBy('date')->values();

        return response()->json(['docs' => $docs]);
    }
        /* ================= Cash Flow · Retained Earnings · Changes in Equity ================= */

    // debit − credit per account for a date range (transaction dates), all sources incl. collections
    private function accountBalances(array $ids, string $from, string $to): array
    {
        $acc = [];
        foreach ($this->ledgerEntries($ids, 'DR', $from, $to) as $e) {
            if (($e['jrnl'] ?? '') === 'COG') continue;
            $c = (string) $e['account_code'];
            $acc[$c] ??= ['code' => $c, 'name' => $e['account_name'], 'balance' => 0.0];
            $acc[$c]['balance'] += (float) $e['debit'] - (float) $e['credit'];
        }
        ksort($acc, SORT_STRING);
        return $acc;
    }

    private function rootOf(string $code): int
    {
        return intdiv((int) $code, 10000);
    }

    private function coaFor(array $ids)
    {
        return ChartsOfAccount::whereIn('added_by', $ids)
            ->get(['account_code', 'account_name', 'account_type'])
            ->keyBy(fn ($a) => (string) $a->account_code);
    }

    private function netIncomeOf(array $bal): float
    {
        $sum = 0.0;
        foreach ($bal as $r) {
            $root = $this->rootOf($r['code']);
            if ($root >= 4 && $root <= 8) $sum += $r['balance'];
        }
        return -$sum;   // income is credit-normal
    }

    // equity account: capital | retained | dividend
    private function equityKind(string $name): string
    {
        $n = strtolower($name);
        if (preg_match('/dividend|drawing|withdraw/', $n)) return 'dividend';
        if (preg_match('/retained|deficit|accumulated (profit|earning)/', $n)) return 'retained';
        return 'capital';
    }

    // net income, RE account movement, dividends for a set of balances
    private function reParts(array $bal, $coa): array
    {
        $p = ['net_income' => $this->netIncomeOf($bal), 'adjustments' => 0.0, 'dividends' => 0.0];
        foreach ($bal as $r) {
            if ($this->rootOf($r['code']) !== 3) continue;
            $kind = $this->equityKind((string) ($coa[$r['code']]->account_name ?? $r['name']));
            if ($kind === 'dividend') $p['dividends']   += $r['balance'];    // debit = paid out
            if ($kind === 'retained') $p['adjustments'] += -$r['balance'];   // credit = increase
        }
        return $p;
    }

    // cash | operating | investing | financing
    private function cashFlowClass(string $code, string $name, ?string $type): string
    {
        $t = strtolower(trim(($type ?? '') . ' ' . $name));
        $root = $this->rootOf($code);

        if ($root === 1) {
            if (preg_match('/receivable/', $t)) return 'operating';
            if (preg_match('/\bcash\b|bank|petty/', $t)) return 'cash';
            if (preg_match('/depreciation|amortization/', $t)) return 'operating';   // non-cash add-back
            if (preg_match('/fixed|property|equipment|plant|vehicle|furniture|building|land|non-?current|investment|intangible/', $t)) return 'investing';
            return 'operating';
        }
        if ($root === 2) {
            return preg_match('/loan|long[- ]?term|non-?current|mortgage|bond|notes payable/', $t) ? 'financing' : 'operating';
        }
        if ($root === 3) return 'financing';
        return 'operating';
    }

    private function branchList($branches)
    {
        return $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values();
    }

    /* GET /api/reports/cash-flow?year=2026&branch=current */
    public function cashFlow(Request $request)
    {
        $d = $request->validate([
            'year'   => ['required', 'digits:4'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        $y = (int) $d['year'];

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        $base = ['company' => $this->companyHeader($client), 'branches' => $this->branchList($branches), 'year' => $y];
        if (!$ids) return response()->json($base + ['lines' => [], 'cash_accounts' => []]);

        $coa  = $this->coaFor($ids);
        $cur  = $this->accountBalances($ids, "{$y}-01-01", "{$y}-12-31");
        $prev = $this->accountBalances($ids, '1900-01-01', ($y - 1) . '-12-31');

        $nameOf  = fn ($r) => (string) (($coa[$r['code']]->account_name ?? null) ?: ($r['name'] ?: $r['code']));
        $classOf = fn ($r) => $this->cashFlowClass($r['code'], $nameOf($r), $coa[$r['code']]->account_type ?? null);

        $items = ['operating' => [], 'investing' => [], 'financing' => []];
        $cashAccounts = [];
        $cashPerBooks = 0.0;

        foreach ($cur as $r) {
            $root = $this->rootOf($r['code']);
            if ($root < 1 || $root > 3) continue;
            $class = $classOf($r);

            if ($class === 'cash') {
                $cashPerBooks += $r['balance'];
                $cashAccounts[$r['code']] = $nameOf($r);
                continue;
            }
            if (abs($r['balance']) < 0.005) continue;

            $increase = $root === 1 ? $r['balance'] > 0 : $r['balance'] < 0;
            $items[$class][] = [
                'kind'   => 'row',
                'code'   => $r['code'],
                'label'  => ($increase ? 'Increase in ' : 'Decrease in ') . $nameOf($r),
                'amount' => round(-$r['balance'], 2),   // asset up = cash out; liability/equity up = cash in
            ];
        }

        $cashBeg = 0.0;
        foreach ($prev as $r) {
            $root = $this->rootOf($r['code']);
            if ($root >= 1 && $root <= 3 && $classOf($r) === 'cash') {
                $cashBeg += $r['balance'];
                $cashAccounts[$r['code']] = $nameOf($r);
            }
        }

        $netIncome = $this->netIncomeOf($cur);
        $sum = fn ($list) => array_sum(array_column($list, 'amount'));
        $op  = $netIncome + $sum($items['operating']);
        $inv = $sum($items['investing']);
        $fin = $sum($items['financing']);
        $change = $op + $inv + $fin;

        $L = fn ($kind, $label, $amount = null) => ['kind' => $kind, 'label' => $label, 'amount' => $amount === null ? null : round($amount, 2)];

        $lines = [];
        $lines[] = $L('head', 'CASH FLOWS FROM OPERATING ACTIVITIES');
        $lines[] = $L('row', 'Net income (loss)', $netIncome);
        if ($items['operating']) $lines[] = $L('sub', 'Changes in operating assets and liabilities:');
        array_push($lines, ...$items['operating']);
        $lines[] = $L('total', 'Net cash from operating activities', $op);
        $lines[] = $L('blank', '');

        $lines[] = $L('head', 'CASH FLOWS FROM INVESTING ACTIVITIES');
        array_push($lines, ...($items['investing'] ?: [$L('none', 'No investing activities')]));
        $lines[] = $L('total', 'Net cash from (used in) investing activities', $inv);
        $lines[] = $L('blank', '');

        $lines[] = $L('head', 'CASH FLOWS FROM FINANCING ACTIVITIES');
        array_push($lines, ...($items['financing'] ?: [$L('none', 'No financing activities')]));
        $lines[] = $L('total', 'Net cash from (used in) financing activities', $fin);
        $lines[] = $L('blank', '');

        $lines[] = $L('total', 'NET INCREASE (DECREASE) IN CASH', $change);
        $lines[] = $L('row', 'Cash at beginning of year', $cashBeg);
        $lines[] = $L('grand', 'CASH AT END OF YEAR', $cashBeg + $change);

        if (abs($cashPerBooks - $change) >= 0.01) {
            $lines[] = $L('blank', '');
            $lines[] = $L('warn', 'Cash at end of year per books', $cashBeg + $cashPerBooks);
            $lines[] = $L('warn', 'Difference (entries not balanced)', $cashPerBooks - $change);
        }

        return response()->json($base + ['lines' => $lines, 'cash_accounts' => array_values($cashAccounts)]);
    }

    /* GET /api/reports/retained-earnings?year_from=2025&year_to=2026&branch=current */
    public function retainedEarnings(Request $request)
    {
        $d = $request->validate([
            'year_from' => ['required', 'digits:4'],
            'year_to'   => ['required', 'digits:4'],
            'branch'    => ['nullable', 'string', 'max:20'],
        ]);
        $yf = (int) min($d['year_from'], $d['year_to']);
        $yt = min((int) max($d['year_from'], $d['year_to']), $yf + 9);
        $years = range($yf, $yt);

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        $base = ['company' => $this->companyHeader($client), 'branches' => $this->branchList($branches), 'years' => $years];
        if (!$ids) return response()->json($base + ['lines' => []]);

        $coa = $this->coaFor($ids);
        $p = $this->reParts($this->accountBalances($ids, '1900-01-01', ($yf - 1) . '-12-31'), $coa);
        $begin = $p['net_income'] + $p['adjustments'] - $p['dividends'];

        $rows = ['begin' => [], 'ni' => [], 'adj' => [], 'div' => [], 'end' => []];
        foreach ($years as $y) {
            $x = $this->reParts($this->accountBalances($ids, "{$y}-01-01", "{$y}-12-31"), $coa);
            $end = $begin + $x['net_income'] + $x['adjustments'] - $x['dividends'];

            $rows['begin'][$y] = round($begin, 2);
            $rows['ni'][$y]    = round($x['net_income'], 2);
            $rows['adj'][$y]   = round($x['adjustments'], 2);
            $rows['div'][$y]   = round(-$x['dividends'], 2);
            $rows['end'][$y]   = round($end, 2);
            $begin = $end;
        }

        $lines = [
            ['kind' => 'row',   'label' => 'Retained earnings, beginning',      'values' => $rows['begin']],
            ['kind' => 'row',   'label' => 'Add: Net income (loss) for the year', 'values' => $rows['ni']],
        ];
        if (array_filter($rows['adj'], fn ($v) => abs($v) >= 0.005)) {
            $lines[] = ['kind' => 'row', 'label' => 'Adjustments to retained earnings', 'values' => $rows['adj']];
        }
        $lines[] = ['kind' => 'row',   'label' => 'Less: Dividends / drawings', 'values' => $rows['div']];
        $lines[] = ['kind' => 'grand', 'label' => 'Retained earnings, end',     'values' => $rows['end']];

        return response()->json($base + ['lines' => $lines]);
    }

    /* GET /api/reports/changes-in-equity?year=2026&branch=current */
    public function changesInEquity(Request $request)
    {
        $d = $request->validate([
            'year'   => ['required', 'digits:4'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        $y = (int) $d['year'];

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        $base = ['company' => $this->companyHeader($client), 'branches' => $this->branchList($branches), 'year' => $y];
        if (!$ids) return response()->json($base + ['columns' => [], 'lines' => []]);

        $coa  = $this->coaFor($ids);
        $prev = $this->accountBalances($ids, '1900-01-01', ($y - 1) . '-12-31');
        $cur  = $this->accountBalances($ids, "{$y}-01-01", "{$y}-12-31");
        $nameOf = fn ($r) => (string) (($coa[$r['code']]->account_name ?? null) ?: ($r['name'] ?: $r['code']));

        // capital accounts (equity, not retained / dividends)
        $capital = [];
        foreach ([$prev, $cur] as $set) {
            foreach ($set as $r) {
                if ($this->rootOf($r['code']) === 3 && $this->equityKind($nameOf($r)) === 'capital') {
                    $capital[$r['code']] = $nameOf($r);
                }
            }
        }
        ksort($capital, SORT_STRING);

        $pp = $this->reParts($prev, $coa);
        $cp = $this->reParts($cur, $coa);
        $reBegin = $pp['net_income'] + $pp['adjustments'] - $pp['dividends'];

        $columns = [];
        foreach ($capital as $code => $name) $columns[] = ['key' => (string) $code, 'label' => $name];
        $columns[] = ['key' => 're', 'label' => 'Retained Earnings'];

        $row = function (string $kind, string $label, array $vals) use ($columns) {
            $values = [];
            foreach ($columns as $c) $values[$c['key']] = round((float) ($vals[$c['key']] ?? 0), 2);
            return ['kind' => $kind, 'label' => $label, 'values' => $values, 'total' => round(array_sum($values), 2)];
        };

        $begin = ['re' => $reBegin];
        $contrib = [];
        foreach ($capital as $code => $_) {
            $begin[$code]   = -($prev[$code]['balance'] ?? 0);   // credit-normal
            $contrib[$code] = -($cur[$code]['balance'] ?? 0);
        }

        $lines = [$row('row', "Balance, January 1, {$y}", $begin)];
        if ($capital) $lines[] = $row('row', 'Contributions / (withdrawals) of capital', $contrib);
        $lines[] = $row('row', 'Net income (loss) for the year', ['re' => $cp['net_income']]);
        if (abs($cp['adjustments']) >= 0.005) $lines[] = $row('row', 'Adjustments to retained earnings', ['re' => $cp['adjustments']]);
        $lines[] = $row('row', 'Dividends / drawings', ['re' => -$cp['dividends']]);

        $end = [];
        foreach ($columns as $c) {
            $end[$c['key']] = array_sum(array_map(fn ($l) => $l['values'][$c['key']], $lines));
        }
        $lines[] = $row('grand', "Balance, December 31, {$y}", $end);

        return response()->json($base + ['columns' => $columns, 'lines' => $lines]);
    }
    public function bir2307Summary(Request $request)
    {
        $d = $request->validate([
            'type'   => ['required', 'in:S_VT,S_IT,PE_VT,PE_IT'],
            'from'   => ['required', 'date_format:Y-m'],
            'to'     => ['required', 'date_format:Y-m'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        [$from, $to] = $d['from'] <= $d['to'] ? [$d['from'], $d['to']] : [$d['to'], $d['from']];

        // months in range (max 36)
        $months = [];
        for ($c = Carbon::createFromFormat('Y-m-d', "{$from}-01"); $c->format('Y-m') <= $to && count($months) < 36; $c->addMonth()) {
            $months[] = $c->format('Y-m');
        }

        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'all');

        $kind     = strtolower(substr($d['type'], -2));        // vt | it
        $taxCol   = "2307_{$kind}";
        $atcCol   = "atc_{$kind}_id";
        $monthCol = "2307_{$kind}_declared_month";

        // first column that exists in a table
        $colOf = function (string $table, array $cands) {
            foreach ($cands as $c) if (Schema::hasColumn($table, $c)) return $c;
            return null;
        };

        $sources = str_starts_with($d['type'], 'S_')
            ? [[fn () => SalesInvoiceMaster::whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted'), 't_sales_invoice_master']]
            : [
                [fn () => PurchaseExpenseMaster::query()->active()->where('record_status', 'posted')->whereIn('added_by', $ids), 't_purchase_expense_master'],
                [fn () => GRNMaster::whereIn('receive_by', $ids), 't_grn_master'],
            ];

        // [atc id (0 = none)][Y-m] => ['base' => , 'tax' => ]  – one grouped query per source
        $cells = [];
        foreach ($sources as [$query, $table]) {
            if (!$ids || !Schema::hasColumn($table, $taxCol)) continue;

            $mCol = $colOf($table, [$monthCol, 'declared_month']);
            $base = $colOf($table, ['sub_total', 'grand_total']);
            if (!$mCol || !$base) continue;
            $atc = Schema::hasColumn($table, $atcCol) ? "COALESCE(`{$atcCol}`, 0)" : '0';

            $res = $query()
                ->where($taxCol, '<>', 0)
                ->whereBetween($mCol, [$from, $to])
                ->selectRaw("{$atc} AS atc, `{$mCol}` AS m, SUM(COALESCE(`{$base}`,0)) AS base, SUM(COALESCE(`{$taxCol}`,0)) AS tax")
                ->groupByRaw('atc, m')
                ->toBase()
                ->get();

            foreach ($res as $r) {
                $a = (int) $r->atc;
                $cells[$a][$r->m]['base'] = ($cells[$a][$r->m]['base'] ?? 0) + (float) $r->base;
                $cells[$a][$r->m]['tax']  = ($cells[$a][$r->m]['tax'] ?? 0) + (float) $r->tax;
            }
        }

        // ATC names + rates
        $atcInfo = DB::table('m_atc')->whereIn('id', array_filter(array_keys($cells)))->get(['id', 'name', 'rate'])->keyBy('id');
        $pct = fn ($r) => ((float) $r <= 1 ? (float) $r * 100 : (float) $r);

        $rows = [];
        $colTotals = array_fill_keys($months, ['base' => 0.0, 'tax' => 0.0]);
        foreach ($cells as $atcId => $byMonth) {
            $info = $atcInfo[$atcId] ?? null;
            $values = [];
            $tb = 0.0; $tt = 0.0;
            foreach ($months as $m) {
                $b = round($byMonth[$m]['base'] ?? 0, 2);
                $t = round($byMonth[$m]['tax'] ?? 0, 2);
                $values[$m] = ['base' => $b, 'tax' => $t];
                $tb += $b; $tt += $t;
                $colTotals[$m]['base'] += $b;
                $colTotals[$m]['tax']  += $t;
            }
            $rows[] = [
                'atc'    => $info ? (string) $info->name : 'No ATC',
                'rate'   => $info ? round($pct($info->rate), 2) : null,
                'values' => $values,
                'total'  => ['base' => round($tb, 2), 'tax' => round($tt, 2)],
            ];
        }
        usort($rows, fn ($a, $b) => [$a['atc'] === 'No ATC', $a['atc']] <=> [$b['atc'] === 'No ATC', $b['atc']]);

        $colTotals = array_map(fn ($x) => ['base' => round($x['base'], 2), 'tax' => round($x['tax'], 2)], $colTotals);

        // header: name, TIN (BIR valid ID), address
        $h = $this->companyHeader($account) ?? [];
        $tin = '';
        $clientNum = $account ? DB::table('m_client_account')->where('id', $account->id)->value('client_num') : null;
        if ($clientNum && class_exists(ClientValidId::class)) {
            $tin = (string) ClientValidId::bir()->where('client_num', $clientNum)->orderByDesc('id')->value('id_num');
        }
        $tin = $tin !== '' ? $tin : (string) ($h['tin'] ?? '');

        $titles = [
            'S_VT'  => 'Summary of Sales BIR2307 (VT/PT)',
            'S_IT'  => 'Summary of Sales BIR2307 (IT)',
            'PE_VT' => 'Summary of Purchases and Expenses (VT/PT)',
            'PE_IT' => 'Summary of Purchases and Expenses (IT)',
        ];

        return response()->json([
            'company'  => ['name' => $h['trade_name'] ?? '', 'tin' => $tin, 'address' => $h['address'] ?? ''],
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'title'    => $titles[$d['type']],
            'months'   => $months,
            'rows'     => $rows,
            'totals'   => [
                'months' => $colTotals,
                'base'   => round(array_sum(array_map(fn ($r) => $r['total']['base'], $rows)), 2),
                'tax'    => round(array_sum(array_map(fn ($r) => $r['total']['tax'], $rows)), 2),
            ],
        ]);
    }
        /* ======================= VAT Summary (old 2550-M/Q Summary) ======================= */

    // DM: from/to = Y-m   |   DR: from/to = Y-m-d
    private function vatPeriod(string $type, string $from, string $to): array
    {
        $dm = $type === 'DM';
        $a = Carbon::parse($dm ? "{$from}-01" : $from)->startOfMonth();
        $b = Carbon::parse($dm ? "{$to}-01" : $to)->startOfMonth();
        if ($b < $a) { [$a, $b] = [$b, $a]; [$from, $to] = [$to, $from]; }

        $months = [];
        for ($c = $a->copy(); $c <= $b && count($months) < 36; $c->addMonth()) $months[] = $c->format('Y-m');

        return ['dm' => $dm, 'from' => $from, 'to' => $to, 'months' => $months];
    }

    // [query, month expression, exchange-rate expression] for one source
    private function vatSource(string $src, array $ids, array $p, string $acct = ''): array
    {
        switch ($src) {
            case 'sales':
                $q = DB::table('t_sales_invoice_detail as x')
                    ->whereIn('x.added_by', $ids)->where('x.invoice_status_id', '<>', 5)->where('x.trans_type', 'posted');
                [$dm, $dt, $rate] = ['x.declared_month', 'x.transaction_date', 'COALESCE(NULLIF(x.ex_rate,0),1)'];
                break;
            case 'grn':
                $q = DB::table('t_grn_detail as x')
                    ->whereIn('x.receive_by', $ids)->where('x.grn_status_id', '<>', 5);
                [$dm, $dt, $rate] = ['x.declared_month', 'x.grn_date', '1'];
                break;
            case 'importation':
                // Importation (Task → Importation): taxable imports + VAT on importation, in PHP (× the line's rate)
                $q = DB::table('t_importation_detail as x')
                    ->whereIn('x.added_by', $ids)->where('x.invoice_status_id', '<>', 5);
                [$dm, $dt, $rate] = ['x.declared_month', 'x.release_date', 'COALESCE(NULLIF(x.ex_rate,0),1)'];
                break;
            default: // purchases | expenses | ap (both)
                $q = DB::table('t_purchase_expense_detail as x')
                    ->join('t_purchase_expense_master as m', 'm.invoice_no', '=', 'x.invoice_no')
                    ->whereIn('m.added_by', $ids)->where('m.invoice_status_id', '<>', 5)->where('m.record_status', 'posted')->where('x.record_status', 'posted');
                if ($src !== 'ap') $q->whereRaw('LOWER(m.trans_type) = ?', [$src]);
                [$dm, $dt, $rate] = ['m.declared_month', 'm.transaction_date', '1'];
        }
 
        if ($p['dm']) $q->whereBetween($dm, [$p['from'], $p['to']]);
        else $q->whereRaw("DATE({$dt}) BETWEEN ? AND ?", [$p['from'], $p['to']]);
 
        if ($acct !== '') $q->where('x.account_code', $acct);
 
        return [$q, $p['dm'] ? $dm : "DATE_FORMAT({$dt}, '%Y-%m')", $rate];
    }

    // [field => [Y-m => amount]]  in one grouped query
    private function vatByMonth(string $src, array $ids, array $p, array $fields, string $acct = ''): array
    {
        $out = array_fill_keys($fields, []);
        if (!$ids) return $out;
        if ($src === 'importation' && !Schema::hasTable('t_importation_detail')) return $out;
 
        [$q, $month, $rate] = $this->vatSource($src, $ids, $p, $acct);
        $sel = ["{$month} AS mon"];
        foreach ($fields as $f) $sel[] = "SUM(COALESCE(x.`{$f}`,0) * {$rate}) AS `{$f}`";
 
        foreach ($q->selectRaw(implode(', ', $sel))->groupBy('mon')->get() as $r) {
            foreach ($fields as $f) $out[$f][$r->mon] = (float) $r->{$f};
        }
        return $out;
    }
    public function vatSummary(Request $request)
    {
        $d = $request->validate([
            'filter_type'  => ['required', 'in:DM,DR'],
            'from'         => ['required', 'string', 'max:10'],
            'to'           => ['required', 'string', 'max:10'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        $p = $this->vatPeriod($d['filter_type'], $d['from'], $d['to']);
        $months = $p['months'];
        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'all');
        $acct = in_array($d['account_code'] ?? '', ['', '0'], true) ? '' : $d['account_code'];
 
        $F = ['net_of_vat' => 'Net of VAT', 'vat_exempt' => 'VAT Exempt', 'zero_rated' => 'Zero Rated'];
        $fields = array_keys($F);
 
        // one row
        $row = function (string $key, string $label, array $byMonth) use ($months) {
            $values = [];
            foreach ($months as $m) $values[$m] = round($byMonth[$m] ?? 0, 2);
            return ['key' => $key, 'label' => $label, 'values' => $values, 'total' => round(array_sum($values), 2)];
        };
        // add rows month by month
        $sum = function (array $rows, string $key = 'total', string $label = '') use ($months, $row) {
            $acc = [];
            foreach ($months as $m) $acc[$m] = array_sum(array_map(fn ($r) => $r['values'][$m], $rows));
            return $row($key, $label, $acc);
        };
        $minus = function (array $a, array $b, string $key, string $label) use ($months, $row) {
            $acc = [];
            foreach ($months as $m) $acc[$m] = $a['values'][$m] - $b['values'][$m];
            return $row($key, $label, $acc);
        };
        $merge = function (array ...$sets) {
            $out = [];
            foreach ($sets as $s) foreach ($s as $m => $v) $out[$m] = ($out[$m] ?? 0) + $v;
            return $out;
        };
 
        // ---- Sales / Purchases / Expenses
        $s = $this->vatByMonth('sales', $ids, $p, $fields, $acct);
        $g = $this->vatByMonth('grn', $ids, $p, $fields, $acct);
        $pu = $this->vatByMonth('purchases', $ids, $p, $fields, $acct);
        $ex = $this->vatByMonth('expenses', $ids, $p, $fields, $acct);
 
        $section = function (array $data) use ($F, $row, $sum) {
            $rows = [];
            foreach ($F as $f => $label) $rows[] = $row($f, $label, $data[$f]);
            return ['rows' => $rows, 'total' => $sum($rows)];
        };
        $sales = $section($s);
        $purchases = $section(array_map(fn ($f) => $merge($g[$f], $pu[$f]), array_combine($fields, $fields)));
        $expenses = $section($ex);
 
        // ---- Importation (same as E-BIR: Net of VAT = taxable imports)
        $im = $this->vatByMonth('importation', $ids, $p, ['taxable_imports', 'amount_of_vat'], $acct);
        $impRows = [$row('net_of_vat', 'Net of VAT', $im['taxable_imports'])];
        $importation = ['rows' => $impRows, 'total' => $sum($impRows)];
 
        $purchExp = $sum([$purchases['total'], $importation['total'], $expenses['total']], 'purch_exp', 'Total Purchases/Expenses');
        $net = $minus($sales['total'], $purchExp, 'net', 'Net Total');
 
        // ---- VAT (no account filter, same as the old page)
        $out = $row('output', 'Output VAT', $this->vatByMonth('sales', $ids, $p, ['input_vat'])['input_vat']);
        $in = $row('input', 'Input VAT', $merge(
            $this->vatByMonth('grn', $ids, $p, ['input_vat'])['input_vat'],
            $this->vatByMonth('ap', $ids, $p, ['input_vat'])['input_vat'],
            $this->vatByMonth('importation', $ids, $p, ['amount_of_vat'])['amount_of_vat'],   // VAT paid on importation
        ));
        $due = $minus($out, $in, 'due', 'VAT Due');
 
        // ---- Less: tax paid (AP lines on "VAT Paid" accounts) – 1st & 2nd month
        $paidCodes = DB::table('m_charts_of_account')
            ->whereRaw('LOWER(account_name) LIKE ?', ['%vat paid%'])
            ->pluck('account_code')->filter()->unique()->values()->all();
        $paid = [];
        if ($paidCodes && $ids) {
            [$q, $month] = $this->vatSource('ap', $ids, $p);
            $paid = $q->whereIn('x.account_code', $paidCodes)
                ->selectRaw("{$month} AS mon, SUM(COALESCE(x.amount,0)) AS amt")
                ->groupBy('mon')->pluck('amt', 'mon')->map(fn ($v) => (float) $v)->all();
        }
        $first = round($paid[$months[0] ?? ''] ?? 0, 2);
        $second = round($paid[$months[1] ?? ''] ?? 0, 2);
 
        // ---- 2307 VT on sales
        $vt2307 = $ids && $months
            ? round((float) SalesInvoiceMaster::whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
                ->where('2307_vt', '<>', 0)
                ->whereBetween('2307_vt_declared_month', [$months[0], end($months)])
                ->sum('2307_vt'), 2)
            : 0;
 
        // ---- header + account list
        $h = $this->companyHeader($account) ?? [];
        $accounts = [];
        try {
            $accounts = DB::table('m_charts_of_account')
                ->whereIn('added_by', $ids)->where('root_name', '<>', 0)
                ->orderBy('account_code')->get(['account_code', 'account_name'])
                ->unique('account_code')
                ->map(fn ($a) => ['code' => $a->account_code, 'name' => $a->account_name])->values();
        } catch (\Throwable $e) { /* list stays empty */ }
 
        return response()->json([
            'company'   => ['name' => $h['trade_name'] ?? '', 'tin' => $h['tin'] ?? '', 'address' => $h['address'] ?? ''],
            'branches'  => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'accounts'  => $accounts,
            'months'    => $months,
            'sales'     => $sales,
            'purchases' => $purchases,
            'importation' => $importation,
            'expenses'  => $expenses,
            'purch_exp' => $purchExp,
            'net'       => $net,
            'output'    => $out,
            'input'     => $in,
            'due'       => $due,
            'less'      => ['first' => $first, 'second' => $second, 'vt2307' => $vt2307],
        ]);
    }
    public function vatSummaryDocs(Request $request)
    {
        $d = $request->validate([
            'section'      => ['required', 'in:sales,purchases,importation,expenses,output,input'],
            'field'        => ['nullable', 'in:net_of_vat,vat_exempt,zero_rated'],
            'month'        => ['required', 'date_format:Y-m'],
            'filter_type'  => ['required', 'in:DM,DR'],
            'account_code' => ['nullable', 'string', 'max:20'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        [$ids] = $this->journalScope($request, $d['branch'] ?? 'all');
        if (!$ids) return response()->json(['rows' => [], 'total' => 0]);
 
        $m = Carbon::createFromFormat('Y-m-d', "{$d['month']}-01");
        $p = $d['filter_type'] === 'DM'
            ? ['dm' => true, 'from' => $d['month'], 'to' => $d['month']]
            : ['dm' => false, 'from' => $m->format('Y-m-d'), 'to' => $m->copy()->endOfMonth()->format('Y-m-d')];
 
        $isVat = in_array($d['section'], ['output', 'input'], true);
        $field = $isVat ? 'input_vat' : ($d['field'] ?? 'net_of_vat');
        $acct = $isVat || in_array($d['account_code'] ?? '', ['', '0'], true) ? '' : $d['account_code'];
 
        $sources = match ($d['section']) {
            'sales', 'output' => ['sales'],
            'purchases'       => ['grn', 'purchases'],
            'importation'     => ['importation'],
            'expenses'        => ['expenses'],
            'input'           => ['grn', 'ap', 'importation'],
        };
 
        $rows = collect();
        foreach ($sources as $src) {
            if ($src === 'importation' && !Schema::hasTable('t_importation_detail')) continue;
            [$q, , $rate] = $this->vatSource($src, $ids, $p, $acct);
            // importation columns: Net of VAT = taxable_imports, VAT = amount_of_vat
            $col = $src === 'importation' ? ($field === 'input_vat' ? 'amount_of_vat' : ($field === 'net_of_vat' ? 'taxable_imports' : null)) : $field;
            if ($col === null) continue;
            $amt = "COALESCE(x.`{$col}`,0) * {$rate}";
            $q->whereRaw("COALESCE(x.`{$col}`,0) <> 0");
 
            if ($src === 'sales') {
                $q->leftJoin('t_sales_invoice_master as sm', 'sm.invoice_no', '=', 'x.invoice_no')
                  ->selectRaw("x.transaction_date AS date, x.declared_month AS dm, x.document_no AS doc, x.invoice_no AS ref, x.customer_id AS pid, 'Sales / Invoices' AS module, sm.id AS sales_id, {$amt} AS amount");
            } elseif ($src === 'importation') {
                $q->selectRaw("x.release_date AS date, x.declared_month AS dm, x.invoice_no AS doc, x.importation_no AS ref, x.seller_id AS pid, 'Importation' AS module, NULL AS sales_id, {$amt} AS amount, 1 AS is_imp");
            } elseif ($src === 'grn') {
                $q->leftJoin('t_grn_master as g', 'g.grn_no', '=', 'x.grn_no')
                  ->selectRaw("x.grn_date AS date, x.declared_month AS dm, COALESCE(g.ref_data, x.grn_no) AS doc, COALESCE(g.ref_data, x.grn_no) AS ref, x.vendor_id AS pid, 'Receive Inventory' AS module, NULL AS sales_id, {$amt} AS amount");
            } else {
                $q->selectRaw("m.transaction_date AS date, m.declared_month AS dm, m.document_no AS doc, m.invoice_no AS ref, m.vendor_id AS pid,
                    CASE WHEN LOWER(m.trans_type) = 'expenses' THEN 'Expenses / Transaction' ELSE 'Vendor / Invoices' END AS module, NULL AS sales_id, {$amt} AS amount");
            }
            $rows = $rows->concat($q->get());
        }
 
        // party names
        $names = [];
        foreach (VendorCustomer::whereIn('id', $rows->pluck('pid')->filter()->unique())->get() as $v) {
            $a = $v->toArray();
            foreach (['name', 'vendor_name', 'registered_name', 'trade_name'] as $k) {
                if (!empty($a[$k])) { $names[$v->id] = $a[$k]; break; }
            }
        }
 
        // importation sellers are in m_sellers, not m_vendor_customer
        $sellers = [];
        $impPids = $rows->filter(fn ($r) => !empty($r->is_imp))->pluck('pid')->filter()->unique()->values();
        if ($impPids->isNotEmpty() && Schema::hasTable('m_sellers')) {
            $sellers = DB::table('m_sellers')->whereIn('id', $impPids)->pluck('name', 'id')->all();
        }
 
        $rows = $rows->sortBy('date')->values()->map(fn ($r) => [
            'date'     => $r->date ? substr((string) $r->date, 0, 10) : $r->dm,
            'doc'      => $r->doc,
            'ref'      => $r->ref,
            'party'    => !empty($r->is_imp) ? ($sellers[$r->pid] ?? '') : ($names[$r->pid] ?? ''),
            'module'   => $r->module,
            'sales_id' => $r->sales_id,
            'amount'   => round((float) $r->amount, 2),
        ]);
 
        return response()->json(['rows' => $rows, 'total' => round($rows->sum('amount'), 2)]);
    }
    /* ======================= E-Sub Report + DAT (old E_Sub_Report.php + ajax/dat.php) ======================= */
    private function esClean($s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace([',', "\r", "\n", "\t"], ' ', (string) $s)));
    }

    private function esMoney($n): string
    {
        return number_format((float) $n, 2, '.', '');
    }

    private function esFirst($row, array $keys): string
    {
        $a = is_array($row) ? $row : (array) $row;
        foreach ($keys as $k) if (isset($a[$k]) && trim((string) $a[$k]) !== '') return trim((string) $a[$k]);
        return '';
    }

    private function esLookup(string $table, $id, array $keys): string
    {
        if (!$id || !Schema::hasTable($table)) return '';
        $row = DB::table($table)->where('id', $id)->first();
        return $row ? $this->esFirst($row, $keys) : '';
    }

    // rate as percent: 0.01 → 1, 2 → 2
    private function esPct($r): float
    {
        $r = (float) $r;
        return $r <= 1 ? $r * 100 : $r;
    }

    // MAIN branch details + TIN + registered name
    private function esClient($account, $branches): array
    {
        $ids = collect($branches)->pluck('id')->filter()->all() ?: array_filter([optional($account)->id]);
        $rows = DB::table('m_client_account')->whereIn('id', $ids)->get();
        $main = $rows->first(fn ($r) => strtoupper($this->esFirst($r, ['branch_type', 'branch_type_id'])) === 'MAIN') ?? $rows->first();
        $m = (array) $main;

        $branch = preg_replace('/\D/', '', $this->esFirst($m, ['branch_code']));
        $branch = strlen($branch) >= 5 ? substr($branch, -4) : str_pad($branch, 4, '0', STR_PAD_LEFT);

        // TIN from client's BIR valid ID
        $clientNum = $m['client_num'] ?? null;
        $tin = '';
        if ($clientNum) {
            $tin = (string) ClientValidId::bir()->where('client_num', $clientNum)->orderByDesc('id')
                ->pluck('id_num')->map(fn ($t) => preg_replace('/\D/', '', (string) $t))
                ->first(fn ($t) => strlen($t) >= 9);
        }

        // registered name: client's full name if individual, else trade name
        $trade = $this->esClean($this->esFirst($m, ['trade_name']));
        $reg = $trade;
        if ($clientNum && Schema::hasTable('m_client')) {
            $cl = DB::table('m_client')->where('client_num', $clientNum)->first();
            if ($cl && !empty($cl->last_name)) {
                $reg = $this->esClean(trim("{$cl->last_name}, {$cl->first_name} " . ($cl->middle_name ?? '')));
            }
        }

        $cityId = $m['city_id'] ?? null;
        $c = [
            'tin9'       => substr($tin, 0, 9),
            'trade_name' => $trade,
            'reg_name'   => $reg,
            'branch'     => $branch,
            'rdo'        => $this->esClean($this->esFirst($m, ['rdo', 'rdo_code'])),
            'street'     => $this->esClean($this->esFirst($m, ['street'])),
            'barangay'   => $this->esClean($this->esLookup('m_barangay', $m['brgy_id'] ?? null, ['barangay', 'name', 'description'])),
            'city'       => $this->esClean($this->esLookup('m_city_mun', $cityId, ['city_mun', 'citymun', 'name', 'description'])),
            'zip'        => $this->esClean($this->esLookup('m_city_mun', $cityId, ['zip_code', 'zipcode', 'zip'])),
            'province'   => $this->esClean($this->esLookup('m_province', $m['province_id'] ?? null, ['province', 'name', 'description'])),
        ];
        $c['address'] = trim("{$c['street']} {$c['barangay']}, {$c['city']} {$c['province']} {$c['zip']}", ' ,');
        return $c;
    }

    // vendor / customer details by id
    private function esParties($ids): array
    {
        $out = [];
        // withoutGlobalScopes: a deleted / hidden customer still has its name and TIN on old invoices
        foreach (VendorCustomer::withoutGlobalScopes()->whereIn('id', collect($ids)->filter()->unique()->values())->get() as $v) {
            $a = $v->toArray();
            $tinRaw = $this->esFirst($a, ['tin']);
            $nameRaw = $this->esFirst($a, ['name', 'vendor_name', 'registered_name', 'trade_name']);
            $out[$v->id] = [
                'tin'      => preg_replace('/\D/', '', $tinRaw),
                'branch'   => $this->esClean($this->esFirst($a, ['branch_code'])),
                'name'     => $this->esClean($nameRaw),
                'address'  => $this->esClean($this->esFirst($a, ['mail_to_address', 'address', 'street'])),
                'city'     => $this->esClean($this->esFirst($a, ['mail_to_city_country', 'city_country', 'city'])),
                'tin_raw'  => $tinRaw,
                'name_raw' => $nameRaw,
            ];
        }
        return $out;
    }
    // 2307 rows: [pid, atc, doc, base, tax]
    private function esWithheld(string $kind, array $ids, callable $period)
    {
        $pick = function ($q, string $table, string $pid, string $tax, string $atc, string $month, string $doc) use ($period) {
            if (!Schema::hasColumn($table, $tax)) return collect();
            return $period($q->where($tax, '>', 0), $month)
                ->selectRaw("`{$pid}` AS pid, `{$atc}` AS atc, {$doc} AS doc, COALESCE(sub_total,0) AS base, `{$tax}` AS tax")
                ->get();
        };

        $sales = fn () => DB::table('t_sales_invoice_master')->whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted');

        return match ($kind) {
            'IT' => $pick($sales(), 't_sales_invoice_master', 'customer_id', '2307_it', 'atc_it_id', '2307_it_declared_month', 'document_no'),
            'VT' => $pick($sales(), 't_sales_invoice_master', 'customer_id', '2307_vt', 'atc_vt_id', '2307_vt_declared_month', 'document_no'),
            default => $pick(
                DB::table('t_purchase_expense_master')->whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('record_status', 'posted'),
                't_purchase_expense_master', 'vendor_id', '2307_it', 'atc_it_id', '2307_it_declared_month', 'document_no'
            )->concat($pick(
                DB::table('t_grn_master')->whereIn('receive_by', $ids)->where('grn_status_id', '<>', 5),
                't_grn_master', 'vendor_id', '2307_it', 'atc_it_id', '2307_it_declared_month', 'ref_data'
            )),
        };
    }

    /* GET /api/reports/esub?type=S&from=2026-07&to=2026-09&name=...   |   type=1604E&year=2026 */
    public function esubReport(Request $request)
    {
        $d = $request->validate([
            'type' => ['required', 'in:P,S,1601EQ_IT,1601EQ_VT,1601EQ_PE,1604E,I'],
            'from' => ['nullable', 'date_format:Y-m', 'required_unless:type,1604E'],
            'to'   => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'digits:4', 'required_if:type,1604E'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);
        abort_if($d['type'] === 'I', 422, "Summary of Importation isn't available yet. The importation tables haven't been moved to the new system.");
 
        [$ids, $account, $branches] = $this->journalScope($request, 'all');
        $c = $this->esClient($account, $branches);
        abort_if($c['tin9'] === '', 422, "This client has no TIN yet. Add the BIR valid ID to the client's record first.");
 
        $tin = $c['tin9'];
        $name = $this->esClean($d['name'] ?? '') ?: $c['reg_name'];
        $from = $d['from'] ?? null;
        $to = $d['to'] ?? $from;
        if ($from && $to && $to < $from) [$from, $to] = [$to, $from];
 
        // period values from the From month (same as old dat.php)
        if ($from) {
            [$y, $mo] = array_map('intval', explode('-', $from));
            $periodEnd = sprintf('%02d/%02d/%04d', $mo, cal_days_in_month(CAL_GREGORIAN, $mo, $y), $y);
            $mmYYYY = sprintf('%02d/%04d', $mo, $y);
            $fileDate = sprintf('%02d%04d', $mo, $y);
        }
 
        $M = fn ($v) => $this->esMoney($v);
        $X = fn ($v) => $this->esDatText($v);                        // names: upper case, Ñ → N, no commas / quotes
        $A = fn ($v) => $this->esDatAddress($v);                     // addresses: same + characters RELIEF doesn't take dropped
        $T = fn ($v) => substr(preg_replace('/\D/', '', (string) $v), 0, 9);   // TIN: 9 digits
        // RELIEF header has TWO address fields: Address 1 = street + barangay, Address 2 = city / province / zip
        $addr1 = $A(trim("{$c['street']} {$c['barangay']}"));
        $addr2 = $A(trim("{$c['city']} {$c['province']} {$c['zip']}"));
        $columns = []; $rows = []; $totals = []; $content = ''; $filename = '';
 
        // Non VAT is NOT reported (Sales, Purchases, Expenses): a line whose only amount is Non VAT (Net of VAT, VAT,
        // VAT Exempt and Zero Rated all 0 – Office of the Treasurer, Payroll …) is left out. The Non VAT amount itself
        // is never in the DAT (it has no column). A line that has a Net of VAT / Exempt / Zero amount stays – even when
        // its Non VAT box is ticked – so the totals are the SAME as VAT Summary / E-BIR.
        $noNonVat = function ($q, string $table) {
            return $q->whereRaw('(ABS(COALESCE(x.net_of_vat,0)) + ABS(COALESCE(x.input_vat,0)) + ABS(COALESCE(x.vat_exempt,0)) + ABS(COALESCE(x.zero_rated,0))) > 0');
        };
 
        switch ($d['type']) {
            /* ---------- Purchase Summary ---------- */
            case 'P':
                $grn = $noNonVat(DB::table('t_grn_detail as x'), 't_grn_detail')
                    ->whereIn('x.receive_by', $ids)->where('x.grn_status_id', '<>', 5)
                    ->whereBetween('x.declared_month', [$from, $to])
                    ->selectRaw('x.declared_month AS mon, x.grn_no AS ref, x.vendor_id AS pid, COALESCE(x.total,0) AS php, COALESCE(x.net_of_vat,0) AS net, COALESCE(x.input_vat,0) AS vat, COALESCE(x.vat_exempt,0) AS exempt, COALESCE(x.zero_rated,0) AS zero')
                    ->get();
                $ap = $noNonVat(DB::table('t_purchase_expense_detail as x'), 't_purchase_expense_detail')
                    ->join('t_purchase_expense_master as m', 'm.invoice_no', '=', 'x.invoice_no')
                    ->whereIn('m.added_by', $ids)->where('m.invoice_status_id', '<>', 5)->where('m.record_status', 'posted')->where('x.record_status', 'posted')
                    ->whereBetween('m.declared_month', [$from, $to])
                    // Purchases + Expenses records only (same as VAT Summary); Non VAT left out (old dat.php: NonVAT = 0)
                    ->whereRaw('LOWER(m.trans_type) IN (?, ?)', ['purchases', 'expenses'])
                    ->selectRaw('m.declared_month AS mon, m.invoice_no AS ref, m.vendor_id AS pid, COALESCE(x.amount,0) AS php, COALESCE(x.net_of_vat,0) AS net, COALESCE(x.input_vat,0) AS vat, COALESCE(x.vat_exempt,0) AS exempt, COALESCE(x.zero_rated,0) AS zero')
                    ->get();

                $lines = $grn->concat($ap);
                $party = $this->esParties($lines->pluck('pid'));
                $blank = ['tin' => '', 'name' => '', 'address' => '', 'city' => '', 'tin_raw' => '', 'name_raw' => ''];
 
                $columns = [
                    ['key' => 'ref', 'label' => 'Reference'], ['key' => 'tin', 'label' => 'TIN'], ['key' => 'name', 'label' => 'Registered Name'],
                    ['key' => 'address', 'label' => 'Street and Barangay'], ['key' => 'city', 'label' => 'City'],
                    ['key' => 'php', 'label' => 'PHP Amount', 'money' => true], ['key' => 'net', 'label' => 'Net of VAT', 'money' => true],
                    ['key' => 'vat', 'label' => 'Input VAT', 'money' => true], ['key' => 'exempt', 'label' => 'VAT Exempt', 'money' => true],
                    ['key' => 'zero', 'label' => 'Zero Rated', 'money' => true],
                ];
                foreach ($lines as $r) {
                    $v = $party[$r->pid] ?? $blank;
                    $rows[] = ['ref' => $r->ref, 'tin' => $v['tin'], 'name' => $v['name'], 'address' => $v['address'], 'city' => $v['city'],
                        'php' => round($r->php, 2), 'net' => round($r->net, 2), 'vat' => round($r->vat, 2), 'exempt' => round($r->exempt, 2), 'zero' => round($r->zero, 2),
                        '_tin_raw' => $v['tin_raw'], '_name_raw' => $v['name_raw']];
                }
                // totals the way VAT Summary / E-BIR add them: each month rounded, then the months added → same to the centavo
                $t = fn ($k) => round((float) $lines->groupBy('mon')->sum(fn ($g) => round((float) $g->sum(fn ($x) => (float) $x->{$k}), 2)), 2);
                $totals = ['php' => $t('php'), 'net' => $t('net'), 'vat' => $t('vat'), 'exempt' => $t('exempt'), 'zero' => $t('zero')];
 
                // exactly the old ajax/dat.php layout (Address 1 / Address 2 = the old "street barangay, city province zip" split at the comma)
                $content .= "H,P,{$tin},{$X($name)}.,,,,{$X($c['trade_name'])},{$addr1},{$addr2},0,0,{$M($totals['exempt'])},{$M($totals['zero'])},{$M($totals['net'])},{$M($totals['vat'])},{$M($totals['vat'])},,{$c['rdo']},{$periodEnd},12\n";
                // one DAT line per TIN (RELIEF); the table above keeps one line per reference
                foreach ($this->esConsolidate($rows, ['php', 'net', 'vat', 'exempt', 'zero']) as $r) {
                    // old layout: D,P,TIN,Name,,,,Address,City,0,0,Exempt,Zero,Net,VAT,Owner TIN,Period,,,,
                    $content .= "D,P,{$T($r['tin'])},{$X($r['_name_raw'] ?: $r['name'])},,,,{$A($r['address'])},{$A($r['city'])},0,0,{$M($r['exempt'])},{$M($r['zero'])},{$M($r['net'])},{$M($r['vat'])},{$tin},{$periodEnd},,,,\n";
                }
                $filename = "{$tin}P{$fileDate}.dat";
                break;
 
            /* ---------- Sales Summary ---------- */
            case 'S':
                $ex = 'COALESCE(NULLIF(x.ex_rate,0),1)';
                // the customer is on the invoice (master); older lines often have no customer_id on the detail row,
                // which made one big line with no TIN / name – take the detail's customer, else the invoice's
                // inner query: one row per sales line with its customer worked out (pid);
                // outer query: grouped by that pid – works with MySQL ONLY_FULL_GROUP_BY
                $lines = $noNonVat(DB::table('t_sales_invoice_detail as x'), 't_sales_invoice_detail')
                    ->leftJoin('t_sales_invoice_master as sm', function ($j) {
                        $j->on('sm.invoice_no', '=', 'x.invoice_no')->on('sm.added_by', '=', 'x.added_by');
                    })
                    ->whereIn('x.added_by', $ids)->where('x.invoice_status_id', '<>', 5)->where('x.trans_type', 'posted')
                    ->whereBetween('x.declared_month', [$from, $to])
                    ->selectRaw("COALESCE(NULLIF(x.customer_id, 0), sm.customer_id, 0) AS pid, x.invoice_no AS inv,
                        COALESCE(x.amount,0)*{$ex} AS php, COALESCE(x.net_of_vat,0)*{$ex} AS net,
                        COALESCE(x.input_vat,0)*{$ex} AS vat, COALESCE(x.vat_exempt,0)*{$ex} AS exempt,
                        COALESCE(x.zero_rated,0)*{$ex} AS zero");
                $sum = DB::query()->fromSub($lines, 'l')
                    ->groupBy('l.pid')
                    ->selectRaw("l.pid AS pid, COUNT(DISTINCT l.inv) AS ninv,
                        SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT l.inv ORDER BY l.inv SEPARATOR ', '), ', ', 5) AS invs,
                        SUM(l.php) AS php, SUM(l.net) AS net, SUM(l.vat) AS vat, SUM(l.exempt) AS exempt, SUM(l.zero) AS zero")
                    ->get();
                $party = $this->esParties($sum->pluck('pid'));
                $blank = ['tin' => '', 'name' => '', 'address' => '', 'city' => '', 'tin_raw' => '', 'name_raw' => ''];
 
                $columns = [
                    ['key' => 'tin', 'label' => 'TIN'], ['key' => 'name', 'label' => 'Registered Name'],
                    ['key' => 'address', 'label' => 'Street and Barangay'], ['key' => 'city', 'label' => 'City'],
                    ['key' => 'php', 'label' => 'PHP Amount', 'money' => true], ['key' => 'net', 'label' => 'Net of VAT', 'money' => true],
                    ['key' => 'vat', 'label' => 'Output VAT', 'money' => true], ['key' => 'exempt', 'label' => 'VAT Exempt', 'money' => true],
                    ['key' => 'zero', 'label' => 'Zero Rated', 'money' => true],
                ];
                foreach ($sum as $r) {
                    $v = $party[$r->pid] ?? $blank;
                    if (!isset($party[$r->pid])) {
                        // still no customer (none on the invoice, or a deleted one): say which invoices, so they can be fixed
                        $v['name'] = '(NO CUSTOMER) ' . $r->invs . ($r->ninv > 5 ? ' … ' . $r->ninv . ' invoices' : '');
                    }
                    $rows[] = ['ref' => $r->invs, 'tin' => $v['tin'], 'name' => $v['name'], 'address' => $v['address'], 'city' => $v['city'],
                        'php' => round($r->php, 2), 'net' => round($r->net, 2), 'vat' => round($r->vat, 2), 'exempt' => round($r->exempt, 2), 'zero' => round($r->zero, 2),
                        '_tin_raw' => $v['tin_raw'], '_name_raw' => $v['name_raw']];
                }
                $t = fn ($k) => round(array_sum(array_column($rows, $k)), 2);
                $totals = ['php' => $t('php'), 'net' => $t('net'), 'vat' => $t('vat'), 'exempt' => $t('exempt'), 'zero' => $t('zero')];
 
                // H,S,TIN,Name,Last,First,Middle,Trade,Address1,Address2,Exempt,Zero rated,Taxable,Output tax,RDO,Period,Fiscal month
                // (Address 2 was missing: every field after it moved one place left → "Invalid RDO CODE" / "Invalid date on Tax Month")
                $content .= "H,S,{$tin},{$X($name)},,,,{$X($c['trade_name'])},{$addr1},{$addr2},{$M($totals['exempt'])},{$M($totals['zero'])},{$M($totals['net'])},{$M($totals['vat'])},{$c['rdo']},{$periodEnd},12,\n";
                foreach ($this->esConsolidate($rows, ['php', 'net', 'vat', 'exempt', 'zero']) as $r) {
                    // old layout: D,S,TIN,Name,,,,Address,City,Exempt,Zero,Net,VAT,Owner TIN,Period,,,
                    $content .= "D,S,{$T($r['tin'])},{$X($r['_name_raw'] ?: $r['name'])},,,,{$A($r['address'])},{$A($r['city'])},{$M($r['exempt'])},{$M($r['zero'])},{$M($r['net'])},{$M($r['vat'])},{$tin},{$periodEnd},,,\n";
                }
                $filename = "{$tin}S{$fileDate}.dat";
                break;
 
            /* ---------- 1601EQ (Sales IT / Sales VT / Purchases & Expenses) and 1604E ---------- */
            default:
                $is1604 = $d['type'] === '1604E';
                $kind = $is1604 ? 'PE' : substr($d['type'], 7);   // IT | VT | PE
                $year = $d['year'] ?? null;
 
                $list = $this->esWithheld($kind, $ids, $is1604
                    ? fn ($q, $col) => $q->where($col, 'like', "{$year}-%")
                    : fn ($q, $col) => $q->whereBetween($col, [$from, $to]));
                $party = $this->esParties($list->pluck('pid'));
                $atc = DB::table('m_atc')->whereIn('id', $list->pluck('atc')->filter()->unique())->get(['id', 'name', 'rate'])->keyBy('id');
                $blank = ['tin' => '', 'branch' => '', 'name' => '', 'tin_raw' => '', 'name_raw' => ''];
 
                $columns = array_values(array_filter([
                    ['key' => 'tin', 'label' => 'TIN'],
                    $is1604 ? null : ['key' => 'doc', 'label' => 'Document No'],
                    ['key' => 'branch', 'label' => 'Branch Code'], ['key' => 'name', 'label' => 'Registered Name'],
                    ['key' => 'atc', 'label' => 'ATC'], ['key' => 'base', 'label' => 'Amount', 'money' => true],
                    ['key' => 'rate', 'label' => 'Tax Rate', 'pct' => true], ['key' => 'tax', 'label' => 'Tax Withheld', 'money' => true],
                ]));
                foreach ($list as $r) {
                    $v = $party[$r->pid] ?? $blank;
                    $a = $atc[$r->atc] ?? null;
                    $rows[] = ['tin' => $v['tin'], 'doc' => $r->doc, 'branch' => $v['branch'], 'name' => $v['name'],
                        'atc' => $a->name ?? '', 'base' => round($r->base, 2), 'rate' => round($this->esPct($a->rate ?? 0), 2), 'tax' => round($r->tax, 2),
                        '_tin_raw' => $v['tin_raw'], '_name_raw' => $v['name_raw']];
                }
                $totals = ['base' => round(array_sum(array_column($rows, 'base')), 2), 'tax' => round(array_sum(array_column($rows, 'tax')), 2)];
 
                if ($is1604) {
                    $yearEnd = "12/31/{$year}";
                    $content .= "H1604E,{$tin},{$c['branch']},{$yearEnd},N,0,{$c['rdo']},,,,,,,,,\n";
                    foreach ($rows as $i => $r) {
                        $n = $i + 1;
                        $content .= "D3,1604E,{$tin},0000,{$yearEnd},{$n},{$T($r['tin'])},{$r['branch']},{$X($r['_name_raw'] ?: $r['name'])},,,,{$r['atc']},{$M($r['base'])}," . intval($r['rate']) . ",{$M($r['tax'])}\n";
                    }
                    $content .= "C3,1604E,{$tin},{$c['branch']},{$yearEnd},{$M($totals['tax'])},,,,,,,,,,\n";
                    $filename = "{$tin}00001231{$year}1604E.dat";
                } else {
                    $content .= "HQAP,H1601EQ,{$tin},{$c['branch']},{$X($name)},{$mmYYYY},{$c['rdo']},,,,,,,\n";
                    foreach ($rows as $i => $r) {
                        $n = $i + 1;
                        $rate = rtrim(rtrim(number_format($r['rate'], 2, '.', ''), '0'), '.');
                        $content .= "D1,1601EQ,{$n},{$T($r['tin'])},{$r['branch']},{$X($r['_name_raw'] ?: $r['name'])},,,,{$mmYYYY},{$r['atc']},{$rate},{$M($r['base'])},{$M($r['tax'])}\n";
                    }
                    $content .= "C1,1601EQ,{$tin},{$c['branch']},{$mmYYYY},{$M($totals['base'])},{$M($totals['tax'])},,,,,,,\n";
                    $filename = "{$tin}0000{$fileDate}1601EQ.dat";
                }
        }
 
        $titles = [
            'P' => 'Purchase Summary', 'S' => 'Sales Summary',
            '1601EQ_IT' => '1601EQ / Sales / IT', '1601EQ_VT' => '1601EQ / Sales / VT',
            '1601EQ_PE' => '1601EQ / Purchases & Expenses', '1604E' => '1604E',
        ];
 
        // RELIEF-style checks; with errors there is no DAT (the screen lists what to fix)
        $check = $this->esValidate($d['type'], $rows, $c, $from, $to);
        $rows = array_map(fn ($r) => array_diff_key($r, ['_tin_raw' => 1, '_name_raw' => 1]), $rows);
         // DAT per month: Jul 2026 – Sep 2026 → one DAT for Jul, one for Aug, one for Sep (months with no lines are skipped)
        $files = null;
        if ($d['type'] !== '1604E' && $from && $to && $from !== $to && $check['ok'] && !$request->boolean('_one_month')) {
            $files = [];
            for ($m = Carbon::parse("{$from}-01"); $m->format('Y-m') <= $to; $m->addMonth()) {
                $ym = $m->format('Y-m');
                $one = $this->esubReport($request->duplicate(array_merge($request->query(), ['from' => $ym, 'to' => $ym, '_one_month' => 1])))->getData(true);
                if (empty($one['rows'])) continue;
                $files[] = [
                    'month'    => $ym,
                    'label'    => $m->format('M Y'),
                    'filename' => $one['dat']['filename'],
                    'content'  => $one['dat']['content'],                                  // null = that month has errors
                    'errors'   => count($one['validation']['errors'] ?? []),
                ];
            }
        }
        return response()->json([
            'validation' => $check,
            'company' => [
                'name' => $name, 'trade_name' => $c['trade_name'],
                'tin' => $tin . substr($c['branch'], -3), 'address' => $c['address'], 'rdo' => $c['rdo'],
            ],
            'title'   => $titles[$d['type']],
            'period'  => $d['type'] === '1604E' ? $d['year'] : "{$from} to {$to}",
            'columns' => $columns,
            'rows'    => $rows,
            'totals'  => $totals,
            'dat'     => ['filename' => $filename, 'content' => $check['ok'] ? $content : null, 'files' => $files],
        ]);
    }
    /* ======================= Income Tax Summary (old Income_Statement.php) ======================= */
    // TRAIN law, used only if m_tax_brackets is missing
    private const ITS_BRACKETS = [
        ['min' => 0,       'max' => 250000,  'base' => 0,       'rate' => 0],
        ['min' => 250000,  'max' => 400000,  'base' => 0,       'rate' => 0.15],
        ['min' => 400000,  'max' => 800000,  'base' => 22500,   'rate' => 0.20],
        ['min' => 800000,  'max' => 2000000, 'base' => 102500,  'rate' => 0.25],
        ['min' => 2000000, 'max' => 8000000, 'base' => 402500,  'rate' => 0.30],
        ['min' => 8000000, 'max' => null,    'base' => 2202500, 'rate' => 0.35],
    ];

    // [account code => ['values' => [Y-m => amt], 'refs' => [Y-m => [doc…]], 'extra' => amt]]
    private function itsGroup($q, string $code, string $mon, string $amt, string $ref, ?string $extra = null): array
    {
        $sel = "{$code} AS code, {$mon} AS mon, SUM({$amt}) AS amt, GROUP_CONCAT(DISTINCT {$ref}) AS refs"
            . ($extra ? ", SUM({$extra}) AS extra" : '');
        $out = [];
        foreach ($q->selectRaw($sel)->groupBy('code', 'mon')->get() as $r) {
            $c = (string) $r->code;
            $out[$c]['values'][$r->mon] = ($out[$c]['values'][$r->mon] ?? 0) + (float) $r->amt;
            $out[$c]['refs'][$r->mon] = array_merge($out[$c]['refs'][$r->mon] ?? [], array_filter(explode(',', (string) $r->refs)));
            $out[$c]['extra'] = ($out[$c]['extra'] ?? 0) + (float) ($r->extra ?? 0);
        }
        return $out;
    }

    private function itsMerge(array ...$sets): array
    {
        $out = [];
        foreach ($sets as $set) {
            foreach ($set as $c => $g) {
                foreach ($g['values'] ?? [] as $m => $v) $out[$c]['values'][$m] = ($out[$c]['values'][$m] ?? 0) + $v;
                foreach ($g['refs'] ?? [] as $m => $r) $out[$c]['refs'][$m] = array_merge($out[$c]['refs'][$m] ?? [], $r);
                $out[$c]['extra'] = ($out[$c]['extra'] ?? 0) + ($g['extra'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Income Tax Summary scope.
     * Staff opening a client from Reports → Income Summary sends user_ids (that client's logins):
     * the statement is that client's, like /bir/income-tax is for the logged-in branch.
     * Otherwise: the normal branch scope (journalScope).
     */
    private function itsScope(Request $request, string $branch): array
    {
        $raw = $request->input('user_ids');
        if ($raw !== null && $raw !== '' && $request->user() instanceof \App\Models\UserStaff) {
            $ids = $this->vsClientUsers($this->vsIds($raw));
            abort_unless($ids, 422, 'This client has no login.');
            $caids = DB::table('m_user_client')->whereIn('id', $ids)->pluck($this->vsUserCa())->filter()->unique()->values();
            $accounts = ClientAccount::whereIn('id', $caids)->get();
            // the MAIN account names the statement (several branches = the whole client)
            $account = $accounts->first(fn ($a) => strtoupper((string) $a->branch_type) === 'MAIN') ?? $accounts->first();
            return [$ids, $account, collect()];
        }
        return $this->journalScope($request, $branch);
    }

    // is the client a corporation? (old: BusinessOwnershipTypeID = 3 → 20%)
    private function itsCorporate($account): bool
    {
        if (!$account) return false;
        $clientNum = DB::table('m_client_account')->where('id', $account->id)->value('client_num');
        if (!$clientNum || !Schema::hasTable('m_client')) return false;
        $botId = DB::table('m_client')->where('client_num', $clientNum)->value('business_ownership_type_id');
        if ((int) $botId === 3) return true;
        if ($botId && Schema::hasTable('m_business_ownership_type')) {
            $row = (array) DB::table('m_business_ownership_type')->where('id', $botId)->first();
            foreach (['name', 'description', 'type'] as $k) {
                if (!empty($row[$k]) && stripos($row[$k], 'corp') !== false) return true;
            }
        }
        return false;
    }

    public function incomeTaxSummary(Request $request){
        $d = $request->validate([
            'from'   => ['required', 'date_format:Y-m'],
            'to'     => ['required', 'date_format:Y-m'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        [$from, $to] = $d['from'] <= $d['to'] ? [$d['from'], $d['to']] : [$d['to'], $d['from']];

        $months = [];
        for ($c = Carbon::createFromFormat('Y-m-d', "{$from}-01"); $c->format('Y-m') <= $to && count($months) < 24; $c->addMonth()) {
            $months[] = $c->format('Y-m');
        }

        [$ids, $account, $branches] = $this->itsScope($request, $d['branch'] ?? 'all');
        DB::statement('SET SESSION group_concat_max_len = 1000000');

        // account names
        $names = DB::table('m_charts_of_account')->whereIn('added_by', $ids)->pluck('account_name', 'account_code')->all();
        if (!$names) $names = DB::table('m_charts_of_account')->pluck('account_name', 'account_code')->all();

        $amt = fn ($p) => "(COALESCE({$p}.net_of_vat,0)+COALESCE({$p}.vat_exempt,0)+COALESCE({$p}.non_vat,0)+COALESCE({$p}.zero_rated,0))";
        $ap = fn () => DB::table('t_purchase_expense_detail as x')
            ->join('t_purchase_expense_master as m', 'm.invoice_no', '=', 'x.invoice_no')
            ->whereIn('m.added_by', $ids)->where('m.invoice_status_id', '<>', 5)->where('m.record_status', 'posted')->where('x.record_status', 'posted')
            ->whereBetween('m.declared_month', [$from, $to]);

        $sales = $purchases = $expenses = [];
        if ($ids) {
            // SALES – 4xxxx
            $sales = $this->itsGroup(
                DB::table('t_sales_invoice_detail as x')
                    ->whereIn('x.added_by', $ids)->where('x.invoice_status_id', '<>', 5)->where('x.trans_type', 'posted')
                    ->whereBetween('x.declared_month', [$from, $to])->where('x.account_code', 'like', '4%'),
                'x.account_code', 'x.declared_month', $amt('x') . ' * COALESCE(NULLIF(x.ex_rate,0),1)', 'x.invoice_no'
            );

            // PURCHASES – 5xxxx (GRN + AP)
            $purchases = $this->itsMerge(
                $this->itsGroup(
                    DB::table('t_grn_detail as x')
                        ->whereIn('x.receive_by', $ids)->where('x.grn_status_id', '<>', 5)
                        ->whereBetween('x.declared_month', [$from, $to])
                        ->where('x.account_code', 'like', '5%')->where('x.total', '<>', 0),
                    'x.account_code', 'x.declared_month', $amt('x'), 'x.grn_no'
                ),
                $this->itsGroup($ap()->where('x.account_code', 'like', '5%'), 'x.account_code', 'm.declared_month', $amt('x'), 'm.invoice_no')
            );

            // EXPENSES – 6xxxx and 8xxxx (with Non-VAT total)
            $expenses = $this->itsGroup(
                $ap()->where(fn ($q) => $q->where('x.account_code', 'like', '6%')->orWhere('x.account_code', 'like', '8%')),
                'x.account_code', 'm.declared_month', $amt('x'), 'm.invoice_no', 'COALESCE(x.non_vat,0)'
            );

            // GENERAL JOURNAL (manual JVs + Fixed Asset depreciation) on the same accounts
            // Closing Entry (CE-…) is left out: it shows in the General Ledger only
            $gj = fn (array $prefixes) => DB::table(self::GJ_TABLE . ' as x')
                ->whereIn('x.added_by', $ids)->whereBetween('x.declared_month', [$from, $to])
                ->where('x.jv_no', 'not like', self::CLOSING_PREFIX . '%')
                ->where(function ($q) use ($prefixes) { foreach ($prefixes as $p) $q->orWhere('x.account_code', 'like', $p . '%'); });
            $drCr = 'COALESCE(x.debit,0) - COALESCE(x.credit,0)';     // expense / cost: debit-normal
            $crDr = 'COALESCE(x.credit,0) - COALESCE(x.debit,0)';     // income: credit-normal
            $sales     = $this->itsMerge($sales, $this->itsGroup($gj(['4']), 'x.account_code', 'x.declared_month', $crDr, 'x.jv_no'));
            $purchases = $this->itsMerge($purchases, $this->itsGroup($gj(['5']), 'x.account_code', 'x.declared_month', $drCr, 'x.jv_no'));
            $expenses  = $this->itsMerge($expenses, $this->itsGroup($gj(['6', '8']), 'x.account_code', 'x.declared_month', $drCr, 'x.jv_no'));
        }

        $toRows = function (array $groups, string $type) use ($months, $names) {
            ksort($groups);
            $rows = [];
            foreach ($groups as $code => $g) {
                $values = $refs = [];
                foreach ($months as $m) {
                    $values[$m] = round($g['values'][$m] ?? 0, 2);
                    $refs[$m] = implode(',', array_values(array_unique($g['refs'][$m] ?? [])));
                }
                $rows[] = [
                    'type' => $type, 'code' => (string) $code, 'label' => $names[$code] ?? (string) $code,
                    'values' => $values, 'refs' => $refs, 'total' => round(array_sum($values), 2),
                    'adjustment' => 0, 'nonvat' => round($g['extra'] ?? 0, 2),
                ];
            }
            return $rows;
        };

        // Beginning inventory (product beginning balances)
        $beginning = $ids ? (float) DB::table('t_beg_balance_product')
            ->whereIn('added_by', $ids)->whereBetween('declared_month', [$from, $to])->sum('total_cost') : 0;

        // Creditable withholding 2307 (IT) on sales this period
        $ewt2307 = $ids ? (float) SalesInvoiceMaster::whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
            ->where('2307_it', '>', 0)->whereBetween('2307_it_declared_month', [$from, $to])->sum('2307_it') : 0;

        $brackets = Schema::hasTable('m_tax_brackets')
            ? DB::table('m_tax_brackets')->orderBy('bracket_min')->get()->map(fn ($b) => [
                'min' => (float) $b->bracket_min, 'max' => ($b->bracket_max === null || (float) $b->bracket_max <= (float) $b->bracket_min) ? null : (float) $b->bracket_max,   // empty / 0 = and above
                'base' => (float) $b->base_tax, 'rate' => (float) $b->rate,
            ])->values()->all()
            : self::ITS_BRACKETS;

        $h = $this->companyHeader($account) ?? [];

        return response()->json([
            'company'   => ['name' => $h['trade_name'] ?? '', 'tin' => $h['tin'] ?? '', 'address' => $h['address'] ?? ''],
            'branches'  => $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values(),
            'months'    => $months,
            'sales'     => $toRows($sales, 'SALES'),
            'purchases' => $toRows($purchases, 'COGS'),
            'expenses'  => $toRows($expenses, 'EXP'),
            'beginning' => round($beginning, 2),
            'ewt2307'   => round($ewt2307, 2),
            'brackets'  => $brackets,
            'corporate' => $this->itsCorporate($account),
        ]);
    }

    public function incomeTaxDocs(Request $request)
    {
        $d = $request->validate([
            'type'   => ['required', 'in:SALES,COGS,EXP'],
            'refs'   => ['required', 'string'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        [$ids] = $this->itsScope($request, $d['branch'] ?? 'all');
        $refs = array_values(array_filter(array_map('trim', explode(',', $d['refs']))));
        if (!$ids || !$refs) return response()->json([]);

        $rows = collect();
        if ($d['type'] === 'SALES') {
            $rows = DB::table('t_sales_invoice_master')->whereIn('invoice_no', $refs)->whereIn('added_by', $ids)
                ->selectRaw("id AS sales_id, customer_id AS pid, invoice_no AS ref, document_no AS doc, transaction_date AS date, declared_month AS dm, grand_total AS total, 'Sales / Invoice' AS module")
                ->get();
        } else {
            $rows = DB::table('t_purchase_expense_master')->whereIn('invoice_no', $refs)->whereIn('added_by', $ids)
                ->selectRaw("NULL AS sales_id, vendor_id AS pid, invoice_no AS ref, document_no AS doc, transaction_date AS date, declared_month AS dm, grand_total AS total,
                    CASE WHEN LOWER(trans_type) = 'expenses' THEN 'Expenses' ELSE 'Vendor Invoice' END AS module")
                ->get();
            if ($d['type'] === 'COGS') {
                $rows = $rows->concat(DB::table('t_grn_master')->whereIn('grn_no', $refs)->whereIn('receive_by', $ids)
                    ->selectRaw("NULL AS sales_id, vendor_id AS pid, grn_no AS ref, ref_data AS doc, grn_date AS date, declared_month AS dm, grand_total AS total, 'Receive Inventory' AS module")
                    ->get());
            }
        }

        // General Journal entries (JV no. in refs, e.g. DEP-2026-10 from Fixed Asset Management)
        $rows = $rows->concat(DB::table(self::GJ_TABLE)->whereIn('jv_no', $refs)->whereIn('added_by', $ids)
            ->groupBy('jv_no')
            ->selectRaw("NULL AS sales_id, NULL AS pid, jv_no AS ref, jv_no AS doc, MIN(transaction_date) AS date, MIN(declared_month) AS dm,
                SUM(COALESCE(debit,0)) AS total, 'General Journal' AS module, MAX(remarks) AS gj_remarks")
            ->get());

        $names = VendorCustomer::whereIn('id', $rows->pluck('pid')->filter()->unique())->pluck('name', 'id');

        return response()->json($rows->sortByDesc('date')->values()->map(fn ($r) => [
            'sales_id' => $r->sales_id,
            'name'     => $names[$r->pid] ?? ($r->gj_remarks ?? ''),
            'ref'      => $r->ref,
            'doc'      => $r->doc,
            'date'     => $r->date ? substr((string) $r->date, 0, 10) : '',
            'month'    => $r->dm,
            'total'    => round((float) $r->total, 2),
            'module'   => $r->module,
        ]));
    }

    public function incomeTaxNonVat(Request $request)
    {
        $d = $request->validate([
            'account_code' => ['required', 'string', 'max:20'],
            'from'         => ['required', 'date_format:Y-m'],
            'to'           => ['required', 'date_format:Y-m'],
            'branch'       => ['nullable', 'string', 'max:20'],
        ]);
        [$ids] = $this->itsScope($request, $d['branch'] ?? 'all');
        if (!$ids) return response()->json([]);

        $rows = DB::table('t_purchase_expense_detail as x')
            ->join('t_purchase_expense_master as m', 'm.invoice_no', '=', 'x.invoice_no')
            ->whereIn('m.added_by', $ids)->where('m.invoice_status_id', '<>', 5)->where('m.record_status', 'posted')->where('x.record_status', 'posted')
            ->whereBetween('m.declared_month', [$d['from'], $d['to']])
            ->where('x.account_code', $d['account_code'])->where('x.non_vat', '>', 0)
            ->orderBy('m.transaction_date')->orderBy('x.id')
            ->get(['x.id', 'm.vendor_id', 'm.document_no', 'm.invoice_no', 'm.transaction_date', 'm.declared_month', 'x.non_vat']);

        $names = VendorCustomer::whereIn('id', $rows->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');

        return response()->json($rows->map(fn ($r) => [
            'id'     => $r->id,
            'vendor' => $names[$r->vendor_id] ?? '',
            'doc'    => $r->document_no ?: $r->invoice_no,
            'date'   => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : '',
            'month'  => $r->declared_month,
            'amount' => round((float) $r->non_vat, 2),
        ]));
    }

    /* ======================= Income Tax Summary → saved adjustments ======================= */
 
    public function incomeTaxAdjustments(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        [, $account] = $this->itsScope($request, 'all');
        $me = (int) $request->user()->id;
        $myType = class_basename($request->user());
 
        $fmt = fn ($t) => $t ? Carbon::parse($t)->format('M d Y - h:iA') : '—';
 
        $rows = DB::table('t_income_statement_adjustment')
            ->where('caid', optional($account)->id)
            ->where('declared_month_from', $d['from'])->where('declared_month_to', $d['to'])
            ->orderByDesc('version')->orderByDesc('id')->get();
 
        // name per login (looked up once per type + id)
        $names = [];
        foreach ($rows as $r) {
            $k = "{$r->user_type}:{$r->user_id}";
            if (!isset($names[$k])) $names[$k] = $this->itsSaverName($r->user_type, $r->user_id);
        }
 
        return response()->json($rows->map(fn ($r) => [
            'id'           => $r->id,
            'version'      => (int) $r->version,
            'employee'     => $names["{$r->user_type}:{$r->user_id}"] ?: (trim((string) $r->user_name) ?: '—'),
            'mine'         => (int) $r->user_id === $me && $r->user_type === $myType,
            'date_added'   => $fmt($r->created_at),
            'date_updated' => $r->updated_at && $r->updated_at !== $r->created_at ? $fmt($r->updated_at) : '—',
            'from'         => $d['from'],
            'to'           => $d['to'],
            'data'         => $this->itsDecode($r->data),
        ]));
    }
        /**
     * name of whoever saved an adjustment (old-system rows have no user_name)
     * staff login → m_employee (via comid) "LAST, FIRST M." · else the login's own name / username
     */
    private function itsUserName($id): string
    {
        $id = (int) $id;
        if (!$id) return '—';

        $emp = function ($e) {
            if (!$e) return '';
            $mi = trim((string) ($e->middle_name ?? ''));
            $first = trim((string) ($e->first_name ?? ''));
            $last = trim((string) ($e->last_name ?? ''));
            return trim(($last !== '' ? "{$last}, " : '') . $first . ($mi !== '' ? ' ' . mb_substr($mi, 0, 1) . '.' : ''), ', ');
        };
        $own = function ($row) {
            if (!$row) return '';
            foreach (['fullname', 'full_name', 'name', 'username', 'email'] as $k) {
                if (!empty($row->{$k})) return trim((string) $row->{$k});
            }
            return '';
        };

        // staff (the ones who prepare the income tax summary)
        if (Schema::hasTable('m_user_staff') && ($s = DB::table('m_user_staff')->where('id', $id)->first())) {
            $com = (int) ($s->comid ?? 0);
            if ($com && Schema::hasTable('m_employee')) {
                $e = DB::table('m_employee')->where('com_id', $com)->orderByDesc('id')->first(['last_name', 'first_name', 'middle_name']);
                if ($name = $emp($e)) return $name;
            }
            if ($name = $own($s)) return $name;
        }
        // client login / client staff
        foreach (['m_user_client', 'm_user_client_staff'] as $t) {
            if (Schema::hasTable($t) && ($name = $own(DB::table($t)->where('id', $id)->first()))) return $name;
        }
        return "User #{$id}";
    }
    // saved adjustment JSON → array (handles BOM, double-encoded strings, old-system saves)
    private function itsDecode($raw)
    {
        $v = $raw;
        for ($i = 0; $i < 3 && is_string($v); $i++) {
            $t = trim(preg_replace('/^\xEF\xBB\xBF/', '', $v));
            if ($t === '') return null;
            $j = json_decode($t, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $j = json_decode(mb_convert_encoding($t, 'UTF-8', 'UTF-8, ISO-8859-1'), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            }
            if ($j === null) return null;
            $v = $j;
        }
        return $v;
    }
    /**
     * who saved an adjustment
     * UserClient → the Client · UserClientStaff → its Employee · UserStaff → the Employee (via comid)
    */
    private function itsSaverName(?string $type, $id): string
    {
        $full = fn ($p) => $p ? trim(preg_replace('/\s+/', ' ', "{$p->last_name} {$p->first_name} {$p->middle_name}")) : '';
        $id = (int) $id;
        if (!$id || !$type) return '';
 
        if ($type === 'UserClient') {
            $u = UserClient::find($id);
            $account = $u?->clientAccount;
            return $full($account ? \App\Models\Client::where('client_num', $account->client_num)->first() : null) ?: (string) ($u->username ?? '');
        }
        if ($type === 'UserClientStaff') {
            $u = \App\Models\UserClientStaff::find($id);
            return $full($u?->employee) ?: (string) ($u->fullname ?? $u->username ?? '');
        }
        if ($type === 'UserStaff') {
            $u = \App\Models\UserStaff::find($id);
            return $full($u ? \App\Models\Employee::where('com_id', $u->comid)->first() : null) ?: (string) ($u->username ?? '');
        }
        return '';
    }
    public function saveIncomeTaxAdjustment(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to'   => ['required', 'date_format:Y-m'],
            'mode' => ['required', 'in:new_version,continue'],
            'data' => ['required', 'array'],
        ]);
        [, $account] = $this->itsScope($request, 'all');
        $caid = optional($account)->id;
        abort_unless($caid, 422, 'No client account is selected.');
 
        $user = $request->user();
        $userType = class_basename($user);   // UserStaff | UserClient | UserClientStaff
        $userName = mb_substr($this->itsSaverName($userType, $user->id) ?: (string) $user->username, 0, 100);
 
        $base = DB::table('t_income_statement_adjustment')
            ->where('caid', $caid)->where('declared_month_from', $d['from'])->where('declared_month_to', $d['to']);
        $mine = (clone $base)->where('user_id', $user->id)->where('user_type', $userType)->orderByDesc('version')->first();
        $json = json_encode($d['data']);
 
        if ($mine && $d['mode'] === 'continue') {
            DB::table('t_income_statement_adjustment')->where('id', $mine->id)->update(['data' => $json, 'user_name' => $userName, 'updated_at' => now()]);
            $version = (int) $mine->version;
        } else {
            $version = (int) (clone $base)->max('version') + 1;
            DB::table('t_income_statement_adjustment')->insert([
                'caid' => $caid, 'declared_month_from' => $d['from'], 'declared_month_to' => $d['to'],
                'user_id' => $user->id, 'user_type' => $userType, 'user_name' => $userName, 'version' => $version,
                'data' => $json, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
 
        return response()->json(['version' => $version]);
    }
    /* ======================= Report Generator (old Report_Generator.php) ======================= */

    private const RG_REPORTS = [
        'P'               => 'Purchase Summary',
        'S'               => 'Sales Summary',
        '1601EQ_IT'       => '1601EQ / Sales / IT',
        '1601EQ_VT'       => '1601EQ / Sales / VT',
        '1601EQ_PE'       => '1601EQ / Purchases & Expenses',
        '1604E'           => '1604E',
        'SalesReport'     => 'Sales Report',
        'PurchaseReport'  => 'Purchase Report',
        'ExpenseReport'   => 'Expense Report',
        'SalesJournal'    => 'Sales Journal',
        'PurchaseJournal' => 'Purchase Journal',
        'ExpenseJournal'  => 'Expense Journal',
        'GeneralLedger'   => 'General Ledger',
    ];

    /* GET /api/report-generator/clients  → clients with their branches */
    private function rgOwnClient(Request $request): ?string
    {
        $u = $request->user();
        $isClient = $u instanceof UserClient || $u instanceof \App\Models\UserClientStaff;
        if (!$isClient) return null;

        $caid = (int) ($u->caid ?? 0);
        $num = $caid ? ClientAccount::whereKey($caid)->value('client_num') : null;
        abort_if($num === null || $num === '', 403, 'Your login is not linked to a client account.');
        return (string) $num;
    }

    private function rgGuard(Request $request, string $clientNum): void
    {
        $own = $this->rgOwnClient($request);
        abort_if($own !== null && $own !== $clientNum, 403, 'You can only generate reports for your own client accounts.');
    }

    public function reportGeneratorClients(Request $request)
    {
        $own = $this->rgOwnClient($request);
        $current = (int) ($this->caidFor($request) ?? 0);   // staff → branch selected on his token; client → his own caid
        $accounts = ClientAccount::when($own !== null, fn ($q) => $q->where('client_num', $own))
            ->orderBy('trade_name')->get(['id', 'client_num', 'trade_name', 'branch_code', 'branch_type']);
        $people = Schema::hasTable('m_client')
            ? DB::table('m_client')->when($own !== null, fn ($q) => $q->where('client_num', $own))
                ->get(['client_num', 'last_name', 'first_name', 'middle_name'])->keyBy('client_num')
            : collect();

        $out = $accounts->groupBy('client_num')->map(function ($accs, $num) use ($people, $current) {
            $main = $accs->first(fn ($a) => strtoupper((string) $a->branch_type) === 'MAIN') ?? $accs->first();
            $p = $people[$num] ?? null;
            $person = $p ? trim("{$p->last_name}, {$p->first_name} " . ($p->middle_name ?? ''), ' ,') : '';
            return [
                'client_num' => (string) $num,
                'name'       => $main->trade_name ?: ($person ?: (string) $num),
                'person'     => $person,
                'branches'   => $accs->map(fn ($a) => [
                    'id'      => $a->id,
                    'label'   => trim("{$a->trade_name} | {$a->branch_code}"),
                    'main'    => strtoupper((string) $a->branch_type) === 'MAIN',
                    'current' => $current > 0 && (int) $a->id === $current,
                ])->values(),
            ];
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return response()->json($out);
    }

    /* GET /api/report-generator/generate?client_num=…&branch=all&filter_type=DM&from=2026-07&to=2026-09&reports[]=P… → ZIP */
    private function rgPrepare(array $d): array
    {
        $type = $d['filter_type'];
        [$from, $to] = $d['from'] <= $d['to'] ? [$d['from'], $d['to']] : [$d['to'], $d['from']];
        abort_unless($type === 'DM' ? preg_match('/^\d{4}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}$/', $to)
            : preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to), 422, 'The dates don\'t match the filter type.');
 
        $accounts = ClientAccount::where('client_num', $d['client_num'])->get();
        abort_if($accounts->isEmpty(), 422, 'Client not found.');
        $branch = $d['branch'] ?? 'all';
        $caids = $branch === 'all' ? $accounts->pluck('id')->all() : $accounts->where('id', (int) $branch)->pluck('id')->all();
        abort_if(!$caids, 422, 'That branch does not belong to this client.');
        $ids = UserClient::whereIn('caid', $caids)->pluck('id')->all();
 
        $main = $accounts->first(fn ($a) => strtoupper((string) $a->branch_type) === 'MAIN') ?? $accounts->first();
        $picked = $branch === 'all' ? $main : $accounts->firstWhere('id', (int) $branch);
        $c = $this->esClient($picked, $accounts->whereIn('id', $caids)->values());
        $tinText = $c['tin9'] ? implode('-', str_split($c['tin9'], 3)) . '-' . substr($c['branch'], -3) : '';
        $periodText = $type === 'DM'
            ? Carbon::parse("{$from}-01")->format('F Y') . ($from !== $to ? ' – ' . Carbon::parse("{$to}-01")->format('F Y') : '')
            : Carbon::parse($from)->format('M d, Y') . ' – ' . Carbon::parse($to)->format('M d, Y');
        $head = ['name' => $c['reg_name'] ?: $c['trade_name'], 'tin' => $tinText, 'address' => $c['address'], 'period' => $periodText];
 
        return [$type, $from, $to, $ids, $head];
    }
 
    private function rgDir(string $token): string
    {
        return storage_path('app/tmp/rgp_' . $token);
    }
    public function reportGeneratorPart(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');
        $d = $request->validate([
            'client_num'  => ['required', 'string', 'max:60'],
            'branch'      => ['nullable', 'string', 'max:20'],
            'filter_type' => ['required', 'in:DM,DT,DE'],
            'from'        => ['required', 'string', 'max:10'],
            'to'          => ['required', 'string', 'max:10'],
            'report'      => ['required', 'string', 'in:' . implode(',', array_keys(self::RG_REPORTS))],
            'token'       => ['nullable', 'regex:/^[A-Za-z0-9]{20}$/'],
        ]);
        $this->rgGuard($request, $d['client_num']);
        [$type, $from, $to, $ids, $head] = $this->rgPrepare($d);
 
        // job folder (old ones – more than a day – are cleared)
        $token = $d['token'] ?? Str::random(20);
        $dir = $this->rgDir($token);
        @mkdir($dir, 0775, true);
        foreach (glob(storage_path('app/tmp/rgp_*')) ?: [] as $old) {
            if (is_dir($old) && filemtime($old) < time() - 86400) { array_map('unlink', glob("{$old}/*") ?: []); @rmdir($old); }
        }
 
        $safe = trim(preg_replace('/[^\w\- .()]+/', '', $head['name'])) ?: 'Client';
        $zipName = "{$safe} ({$from}).zip";
 
        $reports = $ids ? $this->rgBuild([$d['report']], $ids, $type, $from, $to) : [];
        $rep = $reports[0] ?? null;
        if (!$rep) return response()->json(['token' => $token, 'file' => null, 'rows' => 0, 'zip_name' => $zipName]);
 
        $this->rgSheet("{$dir}/{$rep['file']}", $head, $rep);
        return response()->json(['token' => $token, 'file' => $rep['file'], 'rows' => count($rep['rows'] ?? []), 'zip_name' => $zipName]);
    }
 
    /* GET /api/report-generator/zip?token=&name= → the ZIP of every report made for this token */
    public function reportGeneratorZip(Request $request)
    {
        $d = $request->validate([
            'token' => ['required', 'regex:/^[A-Za-z0-9]{20}$/'],
            'name'  => ['nullable', 'string', 'max:150'],
        ]);
        $dir = $this->rgDir($d['token']);
        $files = glob("{$dir}/*.xlsx") ?: [];
        abort_if(!$files, 422, 'No reports to put in the ZIP.');
 
        $name = trim(preg_replace('/[^\w\- .()]+/', '', (string) ($d['name'] ?? ''))) ?: 'Reports.zip';
        if (!str_ends_with(strtolower($name), '.zip')) $name .= '.zip';
        $zipPath = storage_path('app/tmp/rgp_' . $d['token'] . '.zip');
 
        $zip = new ZipArchive();
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Could not create the zip file.');
        foreach ($files as $f) $zip->addFile($f, basename($f));
        $zip->close();
        foreach ($files as $f) @unlink($f);
        @rmdir($dir);
 
        return response()->download($zipPath, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
    public function reportGeneratorGenerate(Request $request)
    {
        $d = $request->validate([
            'client_num'  => ['required', 'string', 'max:60'],
            'branch'      => ['nullable', 'string', 'max:20'],
            'filter_type' => ['required', 'in:DM,DT,DE'],
            'from'        => ['required', 'string', 'max:10'],
            'to'          => ['required', 'string', 'max:10'],
            'reports'     => ['nullable', 'array'],
            'reports.*'   => ['string', 'in:' . implode(',', array_keys(self::RG_REPORTS))],
        ]);
        $this->rgGuard($request, $d['client_num']);
        $type = $d['filter_type'];
        [$from, $to] = $d['from'] <= $d['to'] ? [$d['from'], $d['to']] : [$d['to'], $d['from']];
        abort_unless($type === 'DM' ? preg_match('/^\d{4}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}$/', $to)
            : preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to), 422, 'The dates don\'t match the filter type.');

        // ---- client, branches, users
        $accounts = ClientAccount::where('client_num', $d['client_num'])->get();
        abort_if($accounts->isEmpty(), 422, 'Client not found.');
        $branch = $d['branch'] ?? 'all';
        $caids = $branch === 'all' ? $accounts->pluck('id')->all() : $accounts->where('id', (int) $branch)->pluck('id')->all();
        abort_if(!$caids, 422, 'That branch does not belong to this client.');
        $ids = UserClient::whereIn('caid', $caids)->pluck('id')->all();

        $main = $accounts->first(fn ($a) => strtoupper((string) $a->branch_type) === 'MAIN') ?? $accounts->first();
        $picked = $branch === 'all' ? $main : $accounts->firstWhere('id', (int) $branch);
        $c = $this->esClient($picked, $accounts->whereIn('id', $caids)->values());
        $tinText = $c['tin9'] ? implode('-', str_split($c['tin9'], 3)) . '-' . substr($c['branch'], -3) : '';

        $periodText = $type === 'DM'
            ? Carbon::parse("{$from}-01")->format('F Y') . ($from !== $to ? ' – ' . Carbon::parse("{$to}-01")->format('F Y') : '')
            : Carbon::parse($from)->format('M d, Y') . ' – ' . Carbon::parse($to)->format('M d, Y');
        $head = ['name' => $c['reg_name'] ?: $c['trade_name'], 'tin' => $tinText, 'address' => $c['address'], 'period' => $periodText];

        $wanted = $d['reports'] ?? array_keys(self::RG_REPORTS);
        $reports = $ids ? $this->rgBuild($wanted, $ids, $type, $from, $to) : [];
        abort_if(!$reports, 422, 'No reports to generate for this client.');

        // ---- write the xlsx files and zip them
        $dir = storage_path('app/tmp/rg_' . Str::random(12));
        @mkdir($dir, 0775, true);
        $safe = trim(preg_replace('/[^\w\- .()]+/', '', $head['name'])) ?: 'Client';
        $zipName = "{$safe} ({$from}).zip";
        $zipPath = "{$dir}/{$zipName}";

        $zip = new ZipArchive();
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Could not create the zip file.');
        $files = [];
        foreach ($reports as $rep) {
            $path = "{$dir}/{$rep['file']}";
            $this->rgSheet($path, $head, $rep);
            $zip->addFile($path, $rep['file']);
            $files[] = $path;
        }
        $zip->close();
        foreach ($files as $f) @unlink($f);

        return response()->download($zipPath, $zipName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    // build the selected reports: [file, title, columns, rows, totals]
    private function rgBuild(array $wanted, array $ids, string $type, string $from, string $to): array
    {
        $want = fn ($k) => in_array($k, $wanted, true);
        $out = [];

        // date filter (DM = declared month, DT = transaction date, DE = date entered)
        $where = function ($q, string $month, string $date, string $created) use ($type, $from, $to) {
            if ($type === 'DM') return $q->whereBetween($month, [$from, $to]);
            $col = $type === 'DT' ? $date : $created;
            return $q->whereRaw("DATE({$col}) BETWEEN ? AND ?", [$from, $to]);
        };
        $grn = fn () => $where(DB::table('t_grn_detail as x')->whereIn('x.receive_by', $ids)->where('x.grn_status_id', '<>', 5),
            'x.declared_month', 'x.grn_date', 'x.created_at');
        $ap = fn (?string $kind = null) => $where(
            DB::table('t_purchase_expense_detail as x')->join('t_purchase_expense_master as m', 'm.invoice_no', '=', 'x.invoice_no')
                ->whereIn('m.added_by', $ids)->where('m.invoice_status_id', '<>', 5)->where('m.record_status', 'posted')->where('x.record_status', 'posted')
                ->when($kind, fn ($q) => $q->whereRaw('LOWER(m.trans_type) = ?', [$kind])),
            'm.declared_month', 'm.transaction_date', 'm.created_at');
        $sales = fn () => $where(DB::table('t_sales_invoice_detail as x')->whereIn('x.added_by', $ids)->where('x.invoice_status_id', '<>', 5)->where('x.trans_type', 'posted'),
            'x.declared_month', 'x.transaction_date', 'x.created_at');

        $names = ChartsOfAccount::whereIn('added_by', $ids)->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);
        $acct = fn ($by, $code) => (string) ($names[$by . '|' . $code] ?? '');

        $money = fn ($key, $label) => ['key' => $key, 'label' => $label, 'money' => true];
        $text = fn ($key, $label) => ['key' => $key, 'label' => $label];
        $sum = function (array $rows, array $keys) {
            $t = [];
            foreach ($keys as $k) $t[$k] = round(array_sum(array_map(fn ($r) => (float) ($r[$k] ?? 0), $rows)), 2);
            return $t;
        };
        $blank = ['tin' => '', 'name' => '', 'address' => '', 'city' => '', 'branch' => ''];
        $vatKeys = ['php', 'net', 'vat', 'exempt', 'zero'];
        $summaryCols = [
            $text('no', '#'), $text('tin', 'TIN'), $text('name', 'Registered Name'),
            $text('address', 'Street and Barangay'), $text('city', 'City/Municipality/Province/Country'),
            $money('php', 'PHP Amount'), $money('net', 'Net of VAT'), $money('vat', 'Input VAT'),
            $money('exempt', 'VAT Exempt'), $money('zero', 'Zero Rated'),
        ];

        /* ---------- Purchase Summary ---------- */
        if ($want('P')) {
            $lines = $grn()->where('x.total', '<>', 0)
                ->selectRaw('x.vendor_id AS pid, x.total AS php, x.net_of_vat AS net, x.input_vat AS vat, x.vat_exempt AS exempt, x.zero_rated AS zero')->get()
                ->concat($ap()->where(fn ($q) => $q->whereNull('x.non_vat')->orWhere('x.non_vat', 0))->where('x.amount', '<>', 0)
                    ->selectRaw('m.vendor_id AS pid, x.amount AS php, x.net_of_vat AS net, x.input_vat AS vat, x.vat_exempt AS exempt, x.zero_rated AS zero')->get());
            $party = $this->esParties($lines->pluck('pid'));
            $rows = [];
            foreach ($lines as $i => $l) {
                $v = $party[$l->pid] ?? $blank;
                $rows[] = ['no' => $i + 1, 'tin' => $v['tin'], 'name' => $v['name'], 'address' => $v['address'], 'city' => $v['city'],
                    'php' => (float) $l->php, 'net' => (float) $l->net, 'vat' => (float) $l->vat, 'exempt' => (float) $l->exempt, 'zero' => (float) $l->zero];
            }
            $out[] = ['file' => 'PurchasesSummary.xlsx', 'title' => 'Purchase Summary', 'columns' => $summaryCols, 'rows' => $rows, 'totals' => $sum($rows, $vatKeys)];
        }

        /* ---------- Sales Summary ---------- */
        if ($want('S')) {
            $ex = 'COALESCE(NULLIF(x.ex_rate,0),1)';
            $lines = $sales()->where('x.amount', '<>', 0)->groupBy('x.customer_id')
                ->selectRaw("x.customer_id AS pid, SUM(x.amount*{$ex}) AS php, SUM(x.net_of_vat*{$ex}) AS net, SUM(x.input_vat*{$ex}) AS vat,
                    SUM(x.vat_exempt*{$ex}) AS exempt, SUM(x.zero_rated*{$ex}) AS zero")->get();
            $party = $this->esParties($lines->pluck('pid'));
            $rows = [];
            foreach ($lines as $i => $l) {
                $v = $party[$l->pid] ?? $blank;
                $rows[] = ['no' => $i + 1, 'tin' => $v['tin'], 'name' => $v['name'], 'address' => $v['address'], 'city' => $v['city'],
                    'php' => (float) $l->php, 'net' => (float) $l->net, 'vat' => (float) $l->vat, 'exempt' => (float) $l->exempt, 'zero' => (float) $l->zero];
            }
            $cols = $summaryCols;
            $cols[7] = $money('vat', 'Output VAT');
            $out[] = ['file' => 'SalesSummary.xlsx', 'title' => 'Sales Summary', 'columns' => $cols, 'rows' => $rows, 'totals' => $sum($rows, $vatKeys)];
        }

        /* ---------- 1601EQ (IT / VT / PE) and 1604E ---------- */
        $eqCols = [
            $text('no', '#'), $text('tin', 'TIN'), $text('branch', 'Branch Code'), $text('name', 'Registered Name'),
            $text('atc', 'ATC'), $money('base', 'Amount'), $text('rate', 'Tax Rate'), $money('tax', 'Tax Withheld'),
        ];
        $eqRows = function ($list) use ($blank) {
            $party = $this->esParties($list->pluck('pid'));
            $atc = DB::table('m_atc')->whereIn('id', $list->pluck('atc')->filter()->unique())->get(['id', 'name', 'rate'])->keyBy('id');
            $rows = [];
            foreach ($list->values() as $i => $l) {
                $v = $party[$l->pid] ?? $blank;
                $a = $atc[$l->atc] ?? null;
                $rows[] = ['no' => $i + 1, 'tin' => $v['tin'], 'branch' => $v['branch'], 'name' => $v['name'], 'atc' => $a->name ?? '',
                    'base' => (float) $l->base, 'rate' => number_format($this->esPct($a->rate ?? 0), 2) . '%', 'tax' => (float) $l->tax];
            }
            return $rows;
        };
        $master = fn (string $table, string $by, string $status, string $pid, string $kind, string $date, ?callable $period = null) =>
            ($period ?? fn ($q) => $where($q, "2307_{$kind}_declared_month", $date, 'created_at'))(
                $this->rpPosted(DB::table($table), $table)->whereIn($by, $ids)->where($status, '<>', 5)->where("2307_{$kind}", '<>', 0)
            )->selectRaw("`{$pid}` AS pid, `atc_{$kind}_id` AS atc, COALESCE(sub_total,0) AS base, `2307_{$kind}` AS tax")->get();

        foreach (['IT' => 'it', 'VT' => 'vt'] as $K => $k) {
            if ($want("1601EQ_{$K}")) {
                $rows = $eqRows($master('t_sales_invoice_master', 'added_by', 'invoice_status_id', 'customer_id', $k, 'transaction_date'));
                $out[] = ['file' => "Report1601EQ_{$K}.xlsx", 'title' => "1601EQ / Sales / {$K}", 'columns' => $eqCols, 'rows' => $rows, 'totals' => $sum($rows, ['base', 'tax'])];
            }
        }
        $pe = fn (?callable $period = null) => $master('t_purchase_expense_master', 'added_by', 'invoice_status_id', 'vendor_id', 'it', 'transaction_date', $period)
            ->concat($master('t_grn_master', 'receive_by', 'grn_status_id', 'vendor_id', 'it', 'grn_date', $period));
        if ($want('1601EQ_PE')) {
            $rows = $eqRows($pe());
            $out[] = ['file' => 'Report1601EQ_PE.xlsx', 'title' => '1601EQ / Purchases & Expenses', 'columns' => $eqCols, 'rows' => $rows, 'totals' => $sum($rows, ['base', 'tax'])];
        }
        if ($want('1604E')) {
            $year = substr($to, 0, 4);
            $rows = $eqRows($pe(fn ($q) => $q->where('2307_it_declared_month', 'like', "{$year}-%")));
            $out[] = ['file' => 'Report1604E.xlsx', 'title' => "1604E – {$year}", 'columns' => $eqCols, 'rows' => $rows, 'totals' => $sum($rows, ['base', 'tax'])];
        }

        /* ---------- Sales / Purchase / Expense reports (line by line) ---------- */
        $repCols = fn (string $party, string $vatLabel) => [
            $text('no', '#'), $text('date', 'Date'), $text('tin', 'TIN'), $text('name', $party), $text('doc', 'Document'),
            $text('account', 'Account Title'), $money('amount', 'Amount'), $text('currency', 'Currency'), $money('rate', 'Ex Rate'),
            $money('php', 'PHP'), $money('net', 'Net of VAT'), $money('vat', $vatLabel), $money('exempt', 'VAT Exempt'),
            $money('zero', 'Zero Rated'), $money('nonvat', 'Non VAT'),
        ];
        $repKeys = ['amount', 'php', 'net', 'vat', 'exempt', 'zero', 'nonvat'];
        $repRows = function ($lines) use ($blank, $acct) {
            $party = $this->esParties($lines->pluck('pid'));
            $rows = [];
            foreach ($lines->sortBy('date')->values() as $i => $l) {
                $r = (float) ($l->ex ?: 1);
                $v = $party[$l->pid] ?? $blank;
                $rows[] = [
                    'no' => $i + 1, 'date' => $l->date ? Carbon::parse($l->date)->format('d-M-Y') : '', 'tin' => $v['tin'], 'name' => $v['name'],
                    'doc' => (string) $l->doc, 'account' => $acct($l->by, $l->code), 'amount' => (float) $l->amount, 'currency' => $l->currency ?: 'PHP',
                    'rate' => $r, 'php' => $l->amount * $r, 'net' => $l->net * $r, 'vat' => $l->vat * $r,
                    'exempt' => $l->exempt * $r, 'zero' => $l->zero * $r, 'nonvat' => $l->nonvat * $r,
                ];
            }
            return $rows;
        };
        $vatSel = 'COALESCE(x.net_of_vat,0) AS net, COALESCE(x.input_vat,0) AS vat, COALESCE(x.vat_exempt,0) AS exempt, COALESCE(x.zero_rated,0) AS zero, COALESCE(x.non_vat,0) AS nonvat';

        if ($want('SalesReport')) {
            $rows = $repRows($sales()->selectRaw("x.transaction_date AS date, x.customer_id AS pid, x.document_no AS doc, x.added_by AS `by`, x.account_code AS code,
                x.amount, x.currency, x.ex_rate AS ex, {$vatSel}")->get());
            $cols = $repCols('Customer', 'Output VAT');
            $out[] = ['file' => 'SalesReport.xlsx', 'title' => 'Sales Report', 'columns' => $cols, 'rows' => $rows, 'totals' => $sum($rows, $repKeys)];
        }
        if ($want('PurchaseReport')) {
            $lines = $grn()->selectRaw("x.grn_date AS date, x.vendor_id AS pid, x.grn_no AS doc, x.receive_by AS `by`, x.account_code AS code,
                    x.total AS amount, 'PHP' AS currency, 1 AS ex, {$vatSel}")->get()
                ->concat($ap('purchases')->selectRaw("m.transaction_date AS date, m.vendor_id AS pid, m.document_no AS doc, m.added_by AS `by`, x.account_code AS code,
                    x.amount, 'PHP' AS currency, 1 AS ex, {$vatSel}")->get());
            $rows = $repRows($lines);
            $out[] = ['file' => 'PurchaseReport.xlsx', 'title' => 'Purchase Report', 'columns' => $repCols('Vendor', 'Input VAT'), 'rows' => $rows, 'totals' => $sum($rows, $repKeys)];
        }
        if ($want('ExpenseReport')) {
            $rows = $repRows($ap('expenses')->selectRaw("m.transaction_date AS date, m.vendor_id AS pid, m.document_no AS doc, m.added_by AS `by`, x.account_code AS code,
                x.amount, 'PHP' AS currency, 1 AS ex, {$vatSel}")->get());
            $out[] = ['file' => 'ExpenseReport.xlsx', 'title' => 'Expense Report', 'columns' => $repCols('Vendor', 'Input VAT'), 'rows' => $rows, 'totals' => $sum($rows, $repKeys)];
        }

        /* ---------- Journals (same entries as the General Ledger) ---------- */
        $jCols = [
            $text('date', 'Date'), $text('account', 'Account ID'), $text('tran', 'Tran'), $text('name', 'Name'),
            $text('line', 'Line Description'), $money('debit', 'Debit Amnt'), $money('credit', 'Credit Amnt'),
        ];
        $ledgerType = $type === 'DT' ? 'DR' : $type;
        $journal = function (array $only, string $jrnl, bool $collections) use ($ids, $ledgerType, $from, $to, $sum) {
            $entries = collect($this->ledgerEntries($ids, $ledgerType, $from, $to, ['only' => $only, 'collections' => $collections]))
                ->where('jrnl', $jrnl)
                ->sortBy([['sort_date', 'asc'], ['reference', 'asc']])->values();
            $rows = [];
            $prev = null;
            foreach ($entries as $e) {
                $key = $e['trans_type'] . '|' . $e['reference'] . '|' . $e['ref_data'];
                if ($prev !== null && $key !== $prev) $rows[] = ['date' => '', 'account' => '', 'tran' => '', 'name' => '', 'line' => '', 'debit' => '', 'credit' => ''];
                $rows[] = [
                    'date' => $e['date'], 'account' => $e['account_code'], 'tran' => $e['reference'] ?: $e['ref_data'],
                    'name' => $e['description'], 'line' => $e['account_name'],
                    'debit' => $e['debit'] ? (float) $e['debit'] : '', 'credit' => $e['credit'] ? (float) $e['credit'] : '',
                ];
                $prev = $key;
            }
            return [$rows, $sum($rows, ['debit', 'credit'])];
        };
        if ($want('SalesJournal')) {
            [$rows, $tot] = $journal(['INV', 'RV'], 'SJ', true);
            $out[] = ['file' => 'SalesJournal.xlsx', 'title' => 'Sales Journal', 'columns' => $jCols, 'rows' => $rows, 'totals' => $tot];
        }
        if ($want('PurchaseJournal')) {
            [$rows, $tot] = $journal(['AP', 'GRN'], 'PJ', false);
            $out[] = ['file' => 'PurchaseJournal.xlsx', 'title' => 'Purchase Journal', 'columns' => $jCols, 'rows' => $rows, 'totals' => $tot];
        }
        if ($want('ExpenseJournal')) {
            [$rows, $tot] = $journal(['AP'], 'CDJ', false);
            $out[] = ['file' => 'ExpenseJournal.xlsx', 'title' => 'Expense Journal', 'columns' => $jCols, 'rows' => $rows, 'totals' => $tot];
        }

        /* ---------- General Ledger (beginning, entries, period change, ending per account) ---------- */
        if ($want('GeneralLedger')) {
            // everything before the period → beginning balances
            $beginning = [];
            if ($type !== 'DE') {
                [$bFrom, $bTo] = $type === 'DM'
                    ? ['1900-01', Carbon::parse("{$from}-01")->subMonth()->format('Y-m')]
                    : ['1900-01-01', Carbon::parse($from)->subDay()->format('Y-m-d')];
                foreach ($this->ledgerEntries($ids, $ledgerType, $bFrom, $bTo, ['collections' => true]) as $e) {
                    $beginning[$e['account_code']] = ($beginning[$e['account_code']] ?? 0) + (float) $e['debit'] - (float) $e['credit'];
                }
            }

            $entries = collect($this->ledgerEntries($ids, $ledgerType, $from, $to, ['collections' => true]));
            $byAccount = $entries->groupBy('account_code');
            $codes = collect(array_keys($beginning))->merge($byAccount->keys())->unique()->sort(SORT_NATURAL)->values();

            $glCols = [
                $text('date', 'Date'), $text('account', 'Account ID'), $text('acct_name', 'Account Description'),
                $text('tran', 'Reference'), $text('type', 'Trans Type'), $text('line', 'Description'),
                $money('debit', 'Debit Amnt'), $money('credit', 'Credit Amnt'), $money('balance', 'Balance'),
            ];
            $empty = ['date' => '', 'account' => '', 'acct_name' => '', 'tran' => '', 'type' => '', 'line' => '', 'debit' => '', 'credit' => '', 'balance' => ''];

            $rows = [];
            $gDebit = $gCredit = 0.0;
            foreach ($codes as $code) {
                $list = ($byAccount[$code] ?? collect())->sortBy([['sort_date', 'asc'], ['reference', 'asc']])->values();
                $name = (string) ($list->first()['account_name'] ?? '') ?: $this->rgAccountName($ids, $code);
                $bal = round($beginning[$code] ?? 0, 2);
                if (!$list->count() && abs($bal) < 0.005) continue;   // nothing to show

                $rows[] = ['date' => $type === 'DM' ? $from : $from, 'account' => (string) $code, 'acct_name' => $name,
                    'tran' => '', 'type' => '', 'line' => 'Beginning Balance', 'debit' => '', 'credit' => '', 'balance' => $bal];

                $dr = $cr = 0.0;
                foreach ($list as $e) {
                    $d = (float) $e['debit'];
                    $c = (float) $e['credit'];
                    $bal += $d - $c;
                    $dr += $d;
                    $cr += $c;
                    $rows[] = [
                        'date' => $e['date'], 'account' => '', 'acct_name' => '',
                        'tran' => $e['reference'] ?: $e['ref_data'], 'type' => $e['trans_type'], 'line' => $e['description'],
                        'debit' => $d ?: '', 'credit' => $c ?: '', 'balance' => round($bal, 2),
                    ];
                }

                $rows[] = ['line' => 'Current Period Change', 'debit' => round($dr, 2), 'credit' => round($cr, 2), 'balance' => round($dr - $cr, 2)] + $empty;
                $rows[] = ['date' => $type === 'DM' ? $to : $to, 'line' => 'Ending Balance', 'balance' => round($bal, 2)] + $empty;
                $rows[] = $empty;

                $gDebit += $dr;
                $gCredit += $cr;
            }
            if ($rows && end($rows) === $empty) array_pop($rows);

            $out[] = [
                'file' => 'GeneralLedger.xlsx', 'title' => 'General Ledger', 'columns' => $glCols, 'rows' => $rows,
                'totals' => ['debit' => round($gDebit, 2), 'credit' => round($gCredit, 2)],
            ];
        }

        return $out;
    }
    private function rgAccountName(array $ids, $code): string{
        return (string) (ChartsOfAccount::whereIn('added_by', $ids)->where('account_code', $code)->value('account_name')
            ?? ChartsOfAccount::where('account_code', $code)->value('account_name') ?? '');
    }
    // one report → one .xlsx (header block, table, totals)
    private function rgSheet(string $path, array $head, array $rep): void
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle(substr(preg_replace('/[^\w ]+/', ' ', $rep['title']), 0, 31));

        $cols = $rep['columns'];
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $col = fn ($i) => Coordinate::stringFromColumnIndex($i + 1);

        // header block
        $r = 1;
        foreach ([$head['name'], $head['tin'] ? "TIN : {$head['tin']}" : '', $head['address'], $rep['title'], $head['period']] as $i => $t) {
            $sh->setCellValueExplicit("A{$r}", (string) $t, DataType::TYPE_STRING);
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getAlignment()->setHorizontal('center');
            if ($i === 0) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
            if ($i === 3) $sh->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12);
            $r++;
        }
        $r++;

        // column titles
        $headRow = $r;
        foreach ($cols as $i => $c) $sh->setCellValue($col($i) . $r, $c['label']);
        $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
            'font'    => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ]);
        $r++;

        // rows
        $first = $r;
        foreach ($rep['rows'] as $row) {
            foreach ($cols as $i => $c) {
                $v = $row[$c['key']] ?? '';
                $cell = $col($i) . $r;
                if (!empty($c['money']) && $v !== '' && $v !== null) $sh->setCellValue($cell, round((float) $v, 2));
                elseif ($c['key'] === 'no' && $v !== '') $sh->setCellValue($cell, (int) $v);
                else $sh->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
            }
            if (in_array($row['line'] ?? '', ['Beginning Balance', 'Current Period Change', 'Ending Balance'], true)) {
                $sh->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
                if (($row['line'] ?? '') === 'Ending Balance') {
                    $sh->getStyle("A{$r}:{$last}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                }
            }
            $r++;
        }
        if (!$rep['rows']) {
            $sh->setCellValue("A{$r}", 'No transactions for this period.');
            $sh->mergeCells("A{$r}:{$last}{$r}");
            $sh->getStyle("A{$r}")->getFont()->setItalic(true);
            $r++;
        }

        // totals
        if (!empty($rep['totals'])) {
            $sh->setCellValue("A{$r}", 'TOTAL');
            foreach ($cols as $i => $c) {
                if (array_key_exists($c['key'], $rep['totals'])) $sh->setCellValue($col($i) . $r, $rep['totals'][$c['key']]);
            }
            $sh->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                'font'    => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE]],
            ]);
        }

        // number format, widths, frozen header
        foreach ($cols as $i => $c) {
            if (!empty($c['money'])) $sh->getStyle($col($i) . $first . ':' . $col($i) . $r)->getNumberFormat()->setFormatCode('#,##0.00');
            $sh->getColumnDimension($col($i))->setAutoSize(true);
        }
        $sh->freezePane('A' . ($headRow + 1));
        $sh->setCellValue('A' . ($r + 2), 'Generated ' . now()->format('l, F j, Y g:i A'));
        $sh->getStyle('A' . ($r + 2))->getFont()->setItalic(true)->setSize(9);

        (new Xlsx($ss))->save($path);
        $ss->disconnectWorksheets();
    }
    /* ======================= Other → Transactions (old Transactions.php) ======================= */
    /* GET /api/reports/transactions?type=purchase|sales|expense&from=2026-01&to=2026-09&branch=current */
    public function transactionsList(Request $request){
        $d = $request->validate([
            'type'   => ['required', 'in:purchase,sales,expense'],
            'from'   => ['required', 'date_format:Y-m'],
            'to'     => ['required', 'date_format:Y-m'],
            'branch' => ['nullable', 'string', 'max:20'],
        ]);
        
        [$from, $to] = $d['from'] <= $d['to'] ? [$d['from'], $d['to']] : [$d['to'], $d['from']];
        [$ids, $account, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');

        $branchList = $branches->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->branch_code} - {$b->trade_name}"])->values();
        if (!$ids) return response()->json(['rows' => [], 'branches' => $branchList]);

        $vat = 'COALESCE(x.vat_exempt,0) AS exempt, COALESCE(x.zero_rated,0) AS zero, COALESCE(x.non_vat,0) AS nonvat,
                COALESCE(x.net_of_vat,0) AS net, COALESCE(x.input_vat,0) AS vat';
        $tax2307 = fn ($p) => "{$p}.atc_vt_id AS atc_vt, {$p}.`2307_vt` AS vt, {$p}.`2307_vt_declared_month` AS vt_month,
                {$p}.atc_it_id AS atc_it, {$p}.`2307_it` AS it, {$p}.`2307_it_declared_month` AS it_month";

        $ap = fn (string $kind) => DB::table('t_purchase_expense_detail as x')
            ->join('t_purchase_expense_master as m', 'm.invoice_no', '=', 'x.invoice_no')
            ->whereIn('m.added_by', $ids)->where('m.invoice_status_id', '<>', 5)->where('m.record_status', 'posted')->where('x.record_status', 'posted')
            ->whereBetween('m.declared_month', [$from, $to])
            ->whereRaw('LOWER(m.trans_type) = ?', [$kind])
            ->selectRaw("CONCAT('AP:', m.invoice_no) AS doc_key, m.invoice_no AS trans_id, m.transaction_date AS date, m.declared_month AS month,
                m.vendor_id AS pid, m.document_no AS document, m.remarks, 'PHP' AS currency, 1 AS ex, x.account_code AS code,
                m.added_by AS `by`, m.invoice_status_id AS status_id, m.created_at, {$tax2307('m')}, x.item_id,
                COALESCE(x.amount,0) AS amount, COALESCE(x.amount,0) AS php, {$vat}");

        $lines = collect();
        if ($d['type'] === 'purchase') {
            $lines = DB::table('t_grn_detail as x')
                ->join('t_grn_master as g', 'g.grn_no', '=', 'x.grn_no')
                ->whereIn('x.receive_by', $ids)->where('x.grn_status_id', '<>', 5)
                ->whereBetween('x.declared_month', [$from, $to])
                ->selectRaw("CONCAT('GRN:', g.grn_no) AS doc_key, g.grn_no AS trans_id, g.grn_date AS date, x.declared_month AS month,
                    g.vendor_id AS pid, g.grn_no AS document, NULL AS remarks, 'PHP' AS currency, 1 AS ex, x.account_code AS code,
                    x.receive_by AS `by`, g.grn_status_id AS status_id, g.created_at, {$tax2307('g')}, x.item_id,
                    COALESCE(x.total,0) AS amount, COALESCE(x.total,0) AS php, {$vat}")
                ->get()
                ->concat($ap('purchases')->where('x.amount', '<>', 0)->get());
        } elseif ($d['type'] === 'expense') {
            $lines = $ap('expenses')->get();
        } else {
            $ex = 'COALESCE(NULLIF(x.ex_rate,0),1)';
            $lines = DB::table('t_sales_invoice_detail as x')
                ->leftJoin('t_sales_invoice_master as s', 's.invoice_no', '=', 'x.invoice_no')
                ->whereIn('x.added_by', $ids)->where('x.invoice_status_id', '<>', 5)->where('x.trans_type', 'posted')
                ->whereBetween('x.declared_month', [$from, $to])
                ->selectRaw("CONCAT('INV:', x.invoice_no) AS doc_key, x.invoice_no AS trans_id, x.transaction_date AS date, x.declared_month AS month,
                    x.customer_id AS pid, x.document_no AS document, s.remarks, COALESCE(x.currency,'PHP') AS currency, {$ex} AS ex,
                    x.account_code AS code, x.added_by AS `by`, s.invoice_status_id AS status_id, s.created_at, {$tax2307('s')}, x.item_id,
                    COALESCE(x.amount,0) AS amount, COALESCE(x.amount,0)*{$ex} AS php,
                    COALESCE(x.vat_exempt,0)*{$ex} AS exempt, COALESCE(x.zero_rated,0)*{$ex} AS zero, COALESCE(x.non_vat,0)*{$ex} AS nonvat,
                    COALESCE(x.net_of_vat,0)*{$ex} AS net, COALESCE(x.input_vat,0)*{$ex} AS vat")
                ->get();
        }

        /* ---------- lookups ---------- */
        $first = function ($row, array $keys) {
            $a = is_array($row) ? $row : (array) $row;
            foreach ($keys as $k) if (isset($a[$k]) && trim((string) $a[$k]) !== '') return trim((string) $a[$k]);
            return '';
        };

        /** @var \Illuminate\Support\Collection<int, VendorCustomer> $vendors */
        $vendors = VendorCustomer::whereIn('id', $lines->pluck('pid')->filter()->unique())->get()->keyBy('id');
        $accounts = ChartsOfAccount::whereIn('added_by', $ids)->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn (ChartsOfAccount $a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);
        $atc = DB::table('m_atc')->whereIn('id', $lines->pluck('atc_vt')->merge($lines->pluck('atc_it'))->filter()->unique())
            ->get(['id', 'name', 'rate'])->keyBy('id');
        $atcText = function ($id) use ($atc) {
            $a = $atc[$id] ?? null;
            if (!$a) return '';
            $r = (float) $a->rate;
            $pct = rtrim(rtrim(number_format($r <= 1 ? $r * 100 : $r, 2, '.', ''), '0'), '.');
            return "{$a->name} ({$pct}%)";
        };

        $statusTable = collect(['m_invoice_status', 'm_pos_status'])->first(fn ($t) => Schema::hasTable($t));
        $statuses = $statusTable
            ? DB::table($statusTable)->get()->mapWithKeys(fn ($s) => [$s->id => $first($s, ['name', 'status', 'invoice_status', 'description'])])
            : collect();

        /** @var \Illuminate\Support\Collection<int, UserClient> $users */
        $users = UserClient::whereIn('id', $lines->pluck('by')->filter()->unique())->get()->keyBy('id');
        $caidNames = ClientAccount::whereIn('id', $users->pluck('caid')->filter()->unique())->pluck('trade_name', 'id');

        $itemTax = Items::whereIn('id', $lines->pluck('item_id')->filter()->unique())->get()
            ->mapWithKeys(fn (Items $i) => [$i->id => $first($i->getAttributes(), ['tax_type', 'tax_type_name', 'vat_type'])]);

        $r2 = fn ($v) => round((float) $v, 2);
        $money = ['amount', 'php', 'exempt', 'zero', 'nonvat', 'net', 'vat'];

        /* ---------- one row per document ---------- */
        $rows = $lines->groupBy('doc_key')->map(function ($ls) use ($vendors, $accounts, $atcText, $statuses, $users, $caidNames, $itemTax, $first, $r2, $money) {
            $f = $ls->first();

            /** @var VendorCustomer|null $v */
            $v = $vendors[$f->pid] ?? null;
            $va = $v ? $v->toArray() : [];

            /** @var UserClient|null $u */
            $u = $users[$f->by] ?? null;

            $lineArr = $ls->map(function ($l) use ($accounts, $itemTax, $r2, $money) {
                $line = [
                    'code'    => (string) $l->code,
                    'account' => (string) ($accounts[$l->by . '|' . $l->code] ?? ''),
                    'tax_for' => (string) ($itemTax[$l->item_id] ?? ''),
                ];
                foreach ($money as $k) $line[$k] = $r2($l->{$k});
                return $line;
            })->values();

            $row = [
                'key'            => $f->doc_key,
                'trans_id'       => (string) $f->trans_id,
                'date'           => $f->date ? substr((string) $f->date, 0, 10) : '',
                'vendor'         => $first($va, ['name', 'vendor_name']),
                'tin'            => $first($va, ['tin']),
                'branch_code'    => $first($va, ['branch_code']),
                'tax_type'       => $first($va, ['tax_type']),
                'address'        => $first($va, ['address', 'mail_to_address']),
                'city'           => $first($va, ['city_country', 'mail_to_city_country']),
                'document'       => (string) ($f->document ?? ''),
                'remarks'        => (string) ($f->remarks ?? ''),
                'client_branch'  => $u ? (string) ($caidNames[$u->caid] ?? '') : '',
                'declared_month' => (string) $f->month,
                'currency'       => (string) $f->currency,
                'ex_rate'        => $r2($f->ex),
                'atc_vt'         => $atcText($f->atc_vt),
                'vt2307'         => $r2($f->vt),
                'vt_month'       => (string) ($f->vt_month ?? ''),
                'vt_remarks'     => '',
                'atc_it'         => $atcText($f->atc_it),
                'it2307'         => $r2($f->it),
                'it_month'       => (string) ($f->it_month ?? ''),
                'it_remarks'     => '',
                'status'         => (string) ($statuses[$f->status_id] ?? ''),
                'entry_by'       => $u ? $first($u->getAttributes(), ['username', 'name', 'full_name', 'email']) : '',
                'entry_at'       => $f->created_at ? substr((string) $f->created_at, 0, 19) : '',
                'lines'          => $lineArr,
            ];
            foreach ($money as $k) $row[$k] = $r2($lineArr->sum($k));
            return $row;
        })->sortBy([['date', 'asc'], ['trans_id', 'asc']])->values();

        return response()->json(['rows' => $rows, 'branches' => $branchList]);
    }
    /* =====================================================================
       FINANCIAL STATEMENT (comparative, PFRS for Small Entities layout)
       GET /api/reports/financial-statement?year=2025&compare=2024&branch=current

       Paste this whole block INSIDE ReportController (it uses the private helpers
       journalScope, companyHeader, branchList, coaFor, accountBalances, rootOf,
       netIncomeOf, equityKind, cashFlowClass that are already there).

       Every statement comes back as "lines" that the UI, Excel and PDF all render:
         { id, kind: head|sub|row|total|grand|blank, label, note, indent, values: {2025: n, 2024: n}, of?: [[id, sign], …] }
       "of" tells Excel which rows a total adds up (so the workbook has live SUM formulas).
    ===================================================================== */
    public function financialStatement(Request $request)
    {
        $d = $request->validate([
            'year'    => ['required', 'digits:4'],
            'compare' => ['nullable', 'digits:4'],
            'branch'  => ['nullable', 'string', 'max:20'],
        ]);
        $y1 = (int) $d['year'];
        $y0 = isset($d['compare']) ? (int) $d['compare'] : $y1 - 1;
        $years = $y0 && $y0 !== $y1 ? [$y1, $y0] : [$y1];

        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        $company = $this->companyHeader($client);
        $base = ['company' => $company, 'branches' => $this->branchList($branches), 'years' => $years];
        if (!$ids) return response()->json($base + ['statements' => [], 'notes' => [], 'checks' => []]);

        $coa = $this->coaFor($ids);
        $nameOf = fn ($code, $fallback = '') => (string) (($coa[$code]->account_name ?? null) ?: ($fallback ?: $code));
        $typeOf = fn ($code) => $coa[$code]->account_type ?? null;

        // balances from the Working Trial Balance of each year:
        //   position (cumulative) = final end. balance (beg + data + adjusting) · movement (income / cash flow) = data + adjusting
        $caid = $this->caidFor($request);
        $cum = $mov = [];
        foreach (array_unique(array_merge($years, array_map(fn ($y) => $y - 1, $years))) as $y) {
            $cum[$y] = $this->wtbBalances($ids, $caid, $y, 'final');
        }
        foreach ($years as $y) $mov[$y] = $this->wtbBalances($ids, $caid, $y, 'movement');

        /* ---------- captions (statement line) for each account ---------- */
        $caption = function (string $code) use ($nameOf, $typeOf): array {
            $root = $this->rootOf($code);
            $n = strtolower($nameOf($code) . ' ' . ($typeOf($code) ?? ''));
            if ($root === 1) {
                if (preg_match('/depreciation|amortization|property|equipment|plant|vehicle|furniture|building|land|machiner|leasehold/', $n)) return ['nca', 'Property and Equipment - Net'];
                $cls = $this->cashFlowClass($code, $nameOf($code), $typeOf($code));
                if ($cls === 'cash') return ['ca', 'Cash and Cash Equivalents'];
                if (preg_match('/receivable|advances to/', $n)) return ['ca', 'Trade and Other Receivables'];
                if (preg_match('/inventor|merchandise|supplies on hand/', $n)) return ['ca', 'Merchandise Inventory'];
                if ($cls === 'investing') return ['nca', 'Other Non-Current Assets'];
                return ['ca', 'Prepayments and Other Current Assets'];
            }
            if ($root === 2) {
                if (preg_match('/loan|long[- ]?term|non-?current|mortgage|bond|notes payable/', $n)) return ['ncl', 'Loans Payable'];
                if (preg_match('/income tax/', $n)) return ['cl', 'Income Tax Payable'];
                return ['cl', 'Trade and Other Payables'];
            }
            if ($root === 4) return ['rev', 'Revenue'];
            if ($root === 5) return ['cos', 'Direct Cost'];
            if ($root === 7) return ['oi', 'Other Income'];
            if (in_array($root, [6, 8], true) && preg_match('/income tax/', $n)) return ['tax', 'Income Tax Expense'];
            if ($root === 8) return ['oe', 'Other Expenses'];
            if ($root === 6) return ['exp', $nameOf($code)];
            return ['eq', $nameOf($code)];
        };

        /* ---------- note numbers (assigned as captions are first used) ---------- */
        $noteNo = 2;                       // 1 = general information, 2 = basis of preparation
        $notes = [];                       // caption => ['no', 'title', 'accounts' => [code => name]]
        $noteFor = function (string $cap, string $code) use (&$notes, &$noteNo, $nameOf) {
            if (!isset($notes[$cap])) $notes[$cap] = ['no' => ++$noteNo, 'title' => $cap, 'accounts' => []];
            $notes[$cap]['accounts'][$code] = $nameOf($code);
            return $notes[$cap]['no'];
        };

        $zero = fn () => array_fill_keys($years, 0.0);
        $r2 = fn ($v) => round((float) $v, 2);
        $L = function (string $id, string $kind, string $label, array $values = null, array $extra = []) use ($r2) {
            return ['id' => $id, 'kind' => $kind, 'label' => $label, 'note' => $extra['note'] ?? null, 'indent' => $extra['indent'] ?? 0,
                'values' => $values === null ? null : array_map($r2, $values), 'of' => $extra['of'] ?? null];
        };

        /* =========================================================
           1) STATEMENT OF FINANCIAL POSITION
           ========================================================= */
        $groups = ['ca' => [], 'nca' => [], 'cl' => [], 'ncl' => []];
        $equityCap = $equityDraw = $equityRe = [];   // code => values
        foreach ($years as $y) {
            foreach ($cum[$y] as $r) {
                $root = $this->rootOf($r['code']);
                if ($root < 1 || $root > 3) continue;
                if ($root === 3) {
                    $kind = $this->equityKind($nameOf($r['code'], $r['name']));
                    if ($kind === 'dividend') { $equityDraw[$r['code']] ??= $zero(); $equityDraw[$r['code']][$y] += -$r['balance']; }
                    elseif ($kind === 'retained') { $equityRe[$r['code']] ??= $zero(); $equityRe[$r['code']][$y] += -$r['balance']; }
                    else { $equityCap[$r['code']] ??= $zero(); $equityCap[$r['code']][$y] += -$r['balance']; }
                    continue;
                }
                [$g, $cap] = $caption($r['code']);
                $note = $noteFor($cap, $r['code']);
                $groups[$g][$cap] ??= ['values' => $zero(), 'note' => $note];
                $groups[$g][$cap]['values'][$y] += $root === 1 ? $r['balance'] : -$r['balance'];
            }
        }
        // cumulative earnings = retained earnings accounts + all net income to date
        $cumEarn = $zero();
        foreach ($years as $y) {
            $cumEarn[$y] = $this->netIncomeOf($cum[$y]) + array_sum(array_map(fn ($v) => $v[$y], $equityRe));
        }

        $order = ['ca' => ['Cash and Cash Equivalents', 'Trade and Other Receivables', 'Merchandise Inventory', 'Prepayments and Other Current Assets'],
                  'nca' => ['Property and Equipment - Net', 'Other Non-Current Assets'],
                  'cl' => ['Trade and Other Payables', 'Income Tax Payable'], 'ncl' => ['Loans Payable']];
        $sfp = [];
        $block = function (string $g, string $prefix) use (&$sfp, $groups, $order, $L) {
            $ids = [];
            foreach ($order[$g] as $i => $cap) {
                if (!isset($groups[$g][$cap])) continue;
                $id = "{$prefix}{$i}";
                $sfp[] = $L($id, 'row', $cap, $groups[$g][$cap]['values'], ['note' => $groups[$g][$cap]['note'], 'indent' => 1]);
                $ids[] = [$id, 1];
            }
            return $ids;
        };
        $sumOf = function (array $of, array $lines) use ($years) {
            $by = []; foreach ($lines as $l) $by[$l['id']] = $l;
            $v = array_fill_keys($years, 0.0);
            foreach ($of as [$id, $sign]) foreach ($years as $y) $v[$y] += $sign * (float) ($by[$id]['values'][$y] ?? 0);
            return $v;
        };

        $sfp[] = $L('h_a', 'head', 'A S S E T S');
        $sfp[] = $L('s_ca', 'sub', 'Current Assets');
        $of = $block('ca', 'ca');
        $sfp[] = $L('t_ca', 'total', 'Total Current Assets', $sumOf($of, $sfp), ['of' => $of, 'indent' => 1]);
        $sfp[] = $L('s_nca', 'sub', 'Non-Current Assets');
        $of = $block('nca', 'nca');
        $sfp[] = $L('t_nca', 'total', 'Total Non-Current Assets', $sumOf($of, $sfp), ['of' => $of, 'indent' => 1]);
        $of = [['t_ca', 1], ['t_nca', 1]];
        $sfp[] = $L('t_assets', 'grand', 'TOTAL ASSETS', $sumOf($of, $sfp), ['of' => $of]);
        $sfp[] = $L('b1', 'blank', '');
        $sfp[] = $L('h_le', 'head', "L I A B I L I T I E S   A N D   O W N E R ' S   E Q U I T Y");
        $sfp[] = $L('s_cl', 'sub', 'Current Liabilities');
        $of = $block('cl', 'cl');
        $sfp[] = $L('t_cl', 'total', 'Total Current Liabilities', $sumOf($of, $sfp), ['of' => $of, 'indent' => 1]);
        $sfp[] = $L('s_ncl', 'sub', 'Non-Current Liabilities');
        $of = $block('ncl', 'ncl');
        $sfp[] = $L('t_ncl', 'total', 'Total Non-Current Liabilities', $sumOf($of, $sfp), ['of' => $of, 'indent' => 1]);
        $of = [['t_cl', 1], ['t_ncl', 1]];
        $sfp[] = $L('t_liab', 'total', 'TOTAL LIABILITIES', $sumOf($of, $sfp), ['of' => $of]);
        $sfp[] = $L('b2', 'blank', '');
        $sfp[] = $L('s_eq', 'sub', "Owner's Equity");
        $eqOf = [];
        $i = 0;
        foreach ($equityCap as $code => $v) { $sfp[] = $L("eq{$i}", 'row', $nameOf($code), $v, ['indent' => 1]); $eqOf[] = ["eq{$i}", 1]; $i++; }
        foreach ($equityDraw as $code => $v) { $sfp[] = $L("eq{$i}", 'row', $nameOf($code), $v, ['indent' => 1]); $eqOf[] = ["eq{$i}", 1]; $i++; }
        $sfp[] = $L('eq_ce', 'row', 'Cumulative Earnings', $cumEarn, ['indent' => 1]);
        $eqOf[] = ['eq_ce', 1];
        $sfp[] = $L('t_eq', 'total', "Total Owner's Equity", $sumOf($eqOf, $sfp), ['of' => $eqOf, 'indent' => 1]);
        $sfp[] = $L('b3', 'blank', '');
        $of = [['t_liab', 1], ['t_eq', 1]];
        $sfp[] = $L('t_le', 'grand', "TOTAL LIABILITIES AND OWNER'S EQUITY", $sumOf($of, $sfp), ['of' => $of]);

        /* =========================================================
           2) STATEMENT OF INCOME
           ========================================================= */
        $inc = ['rev' => $zero(), 'cos' => $zero(), 'oi' => $zero(), 'oe' => $zero(), 'tax' => $zero()];
        $exp = [];
        foreach ($years as $y) {
            foreach ($mov[$y] as $r) {
                $root = $this->rootOf($r['code']);
                if ($root < 4 || $root > 8) continue;
                [$g, $cap] = $caption($r['code']);
                $sign = in_array($root, [4, 7], true) ? -1 : 1;     // income credit-normal
                if ($g === 'exp') { $exp[$r['code']] ??= $zero(); $exp[$r['code']][$y] += $r['balance']; continue; }
                $inc[$g][$y] += $sign * $r['balance'];
                $noteFor($cap, $r['code']);
            }
        }
        ksort($exp, SORT_STRING);
        $si = [];
        $si[] = $L('rev', 'row', 'Revenue', $inc['rev'], ['note' => $notes['Revenue']['no'] ?? null]);
        $si[] = $L('cos', 'row', 'Direct Cost', $inc['cos'], ['note' => $notes['Direct Cost']['no'] ?? null]);
        $of = [['rev', 1], ['cos', -1]];
        $si[] = $L('gross', 'total', 'Gross Income', $sumOf($of, $si), ['of' => $of]);
        $si[] = $L('b1', 'blank', '');
        $si[] = $L('s_exp', 'sub', 'Less: Expenses');
        $eo = [];
        $i = 0;
        foreach ($exp as $code => $v) {
            if (abs($v[$y1]) < 0.005 && (count($years) < 2 || abs($v[$years[1]]) < 0.005)) continue;
            $si[] = $L("x{$i}", 'row', $nameOf($code), $v, ['indent' => 1]); $eo[] = ["x{$i}", 1]; $i++;
        }
        $si[] = $L('t_exp', 'total', 'Total Expenses', $sumOf($eo, $si), ['of' => $eo]);
        $si[] = $L('b2', 'blank', '');
        $of = [['gross', 1], ['t_exp', -1]];
        if (array_sum($inc['oi']) != 0 || array_sum($inc['oe']) != 0) {
            $si[] = $L('op', 'total', 'Income from Operations', $sumOf($of, $si), ['of' => $of]);
            $si[] = $L('oi', 'row', 'Add: Other Income', $inc['oi'], ['note' => $notes['Other Income']['no'] ?? null]);
            $si[] = $L('oe', 'row', 'Less: Other Expenses', $inc['oe'], ['note' => $notes['Other Expenses']['no'] ?? null]);
            $of = [['op', 1], ['oi', 1], ['oe', -1]];
        }
        $si[] = $L('nibt', 'total', 'Net Income Before Tax', $sumOf($of, $si), ['of' => $of]);
        $si[] = $L('tax', 'row', 'Less: Income Tax Expense', $inc['tax'], ['note' => $notes['Income Tax Expense']['no'] ?? null]);
        $of = [['nibt', 1], ['tax', -1]];
        $si[] = $L('niat', 'grand', 'Net Income After Tax', $sumOf($of, $si), ['of' => $of]);
        $netIncome = $sumOf($of, $si);

        /* =========================================================
           3) STATEMENT OF CHANGES IN OWNER'S EQUITY
           ========================================================= */
        $eqTotal = function (int $y) use ($cum) {
            $t = $this->netIncomeOf($cum[$y] ?? []);
            foreach ($cum[$y] ?? [] as $r) if ($this->rootOf($r['code']) === 3) $t += -$r['balance'];
            return $t;
        };
        $begin = $invest = $draw = $adj = $zero();
        foreach ($years as $y) {
            $begin[$y] = $eqTotal($y - 1);
            foreach ($mov[$y] as $r) {
                if ($this->rootOf($r['code']) !== 3) continue;
                $k = $this->equityKind($nameOf($r['code'], $r['name']));
                if ($k === 'dividend') $draw[$y] += $r['balance'];          // debit = drawn
                elseif ($k === 'retained') $adj[$y] += -$r['balance'];
                else $invest[$y] += -$r['balance'];                         // credit = invested
            }
        }
        $capLabel = (count($equityCap) === 1 ? $nameOf(array_key_first($equityCap)) : "Owner's Capital");
        $sce = [];
        $sce[] = $L('beg', 'row', "{$capLabel}, Beginning", $begin);
        $sce[] = $L('s_add', 'sub', 'Add:');
        $sce[] = $L('ni', 'row', 'Net Income', $netIncome, ['indent' => 1]);
        $sce[] = $L('inv', 'row', 'Additional Investment', $invest, ['indent' => 1]);
        $of = [['beg', 1], ['ni', 1], ['inv', 1]];
        if (array_sum($adj) != 0) { $sce[] = $L('adj', 'row', 'Prior period adjustments', $adj, ['indent' => 1]); $of[] = ['adj', 1]; }
        $sce[] = $L('s_less', 'sub', 'Less:');
        $sce[] = $L('draw', 'row', 'Personal Drawings', $draw, ['indent' => 1]);
        $of[] = ['draw', -1];
        $sce[] = $L('b1', 'blank', '');
        $sce[] = $L('end', 'grand', "{$capLabel}, Ending", $sumOf($of, $sce), ['of' => $of]);

        /* =========================================================
           4) STATEMENT OF CASH FLOWS (indirect)
           ========================================================= */
        $cf = ['dep' => $zero(), 'op' => [], 'inv' => [], 'fin' => []];
        $cashBeg = $cashEnd = $zero();
        foreach ($years as $y) {
            foreach ($cum[$y - 1] ?? [] as $r) if ($this->rootOf($r['code']) === 1 && $this->cashFlowClass($r['code'], $nameOf($r['code'], $r['name']), $typeOf($r['code'])) === 'cash') $cashBeg[$y] += $r['balance'];
            foreach ($cum[$y] as $r) if ($this->rootOf($r['code']) === 1 && $this->cashFlowClass($r['code'], $nameOf($r['code'], $r['name']), $typeOf($r['code'])) === 'cash') $cashEnd[$y] += $r['balance'];

            foreach ($mov[$y] as $r) {
                $root = $this->rootOf($r['code']);
                if ($root < 1 || $root > 3 || abs($r['balance']) < 0.005) continue;
                $name = $nameOf($r['code'], $r['name']);
                $cls = $this->cashFlowClass($r['code'], $name, $typeOf($r['code']));
                if ($cls === 'cash') continue;
                $amt = -$r['balance'];   // asset up = cash out; liability/equity up = cash in
                if ($root === 1 && preg_match('/depreciation|amortization/i', $name)) { $cf['dep'][$y] += $amt; continue; }
                if ($root === 3) {
                    $k = $this->equityKind($name);
                    $label = $k === 'dividend' ? "{$name}" : ($k === 'retained' ? 'Prior period adjustments' : "{$name}");
                    $cf['fin'][$label] ??= $zero(); $cf['fin'][$label][$y] += $amt; continue;
                }
                [$g, $cap] = $caption($r['code']);
                if ($root === 1 && $g === 'nca') { $label = 'Acquisition of Property and Equipment'; $cf['inv'][$label] ??= $zero(); $cf['inv'][$label][$y] += $amt; continue; }
                if ($root === 2 && $g === 'ncl') { $label = 'Loans Payable'; $cf['fin'][$label] ??= $zero(); $cf['fin'][$label][$y] += $amt; continue; }
                $cf['op'][$cap] ??= $zero(); $cf['op'][$cap][$y] += $amt;
            }
        }
        $scf = [];
        $scf[] = $L('h_op', 'head', 'Cash flow from Operating Activities:');
        $scf[] = $L('ni', 'row', 'Net Income After Tax', $netIncome, ['indent' => 1]);
        $scf[] = $L('dep', 'row', 'Depreciation', $cf['dep'], ['indent' => 1]);
        $of = [['ni', 1], ['dep', 1]];
        $scf[] = $L('oibwc', 'total', 'Operating Income before working capital changes', $sumOf($of, $scf), ['of' => $of, 'indent' => 1]);
        $opOf = [['oibwc', 1]];
        $assetCaps = array_filter($cf['op'], fn ($k) => in_array($k, $order['ca'], true), ARRAY_FILTER_USE_KEY);
        $liabCaps = array_diff_key($cf['op'], $assetCaps);
        $i = 0;
        if ($assetCaps) {
            $scf[] = $L('s_ca', 'sub', '(Increase) Decrease in Current Assets:', null, ['indent' => 1]);
            foreach ($assetCaps as $cap => $v) { $scf[] = $L("o{$i}", 'row', $cap, $v, ['indent' => 2]); $opOf[] = ["o{$i}", 1]; $i++; }
        }
        if ($liabCaps) {
            $scf[] = $L('s_cl', 'sub', 'Increase (Decrease) in Current Liabilities:', null, ['indent' => 1]);
            foreach ($liabCaps as $cap => $v) { $scf[] = $L("o{$i}", 'row', $cap, $v, ['indent' => 2]); $opOf[] = ["o{$i}", 1]; $i++; }
        }
        $scf[] = $L('t_op', 'total', 'Net Cash flows from (used in) Operating Activities', $sumOf($opOf, $scf), ['of' => $opOf]);
        $scf[] = $L('b1', 'blank', '');
        $scf[] = $L('h_inv', 'head', 'Cash flow from Investing Activities:');
        $invOf = [];
        $i = 0;
        foreach ($cf['inv'] as $label => $v) { $scf[] = $L("i{$i}", 'row', $label, $v, ['indent' => 1]); $invOf[] = ["i{$i}", 1]; $i++; }
        if (!$invOf) $scf[] = $L('i_none', 'row', 'Acquisition of Property and Equipment', $zero(), ['indent' => 1]);
        $scf[] = $L('t_inv', 'total', 'Net Cash flows from (used in) Investing Activities', $sumOf($invOf, $scf), ['of' => $invOf]);
        $scf[] = $L('b2', 'blank', '');
        $scf[] = $L('h_fin', 'head', 'Cash flow from Financing Activities:');
        $finOf = [];
        $i = 0;
        foreach ($cf['fin'] as $label => $v) { $scf[] = $L("f{$i}", 'row', $label, $v, ['indent' => 1]); $finOf[] = ["f{$i}", 1]; $i++; }
        $scf[] = $L('t_fin', 'total', 'Net Cash flows from (used in) Financing Activities', $sumOf($finOf, $scf), ['of' => $finOf]);
        $scf[] = $L('b3', 'blank', '');
        $of = [['t_op', 1], ['t_inv', 1], ['t_fin', 1]];
        $scf[] = $L('chg', 'total', 'Net Increase (Decrease) in Cash and Cash Equivalents', $sumOf($of, $scf), ['of' => $of]);
        $scf[] = $L('beg', 'row', 'Cash and Cash Equivalents, Beginning', $cashBeg);
        $of = [['chg', 1], ['beg', 1]];
        $scf[] = $L('end', 'grand', 'Cash and Cash Equivalents, Ending', $sumOf($of, $scf), ['of' => $of]);

        /* =========================================================
           NOTES (account breakdown of each caption)
           ========================================================= */
        $noteOut = [];
        foreach ($notes as $cap => $n) {
            $isFlow = in_array($cap, ['Revenue', 'Direct Cost', 'Other Income', 'Other Expenses', 'Income Tax Expense'], true);
            $rows = [];
            foreach ($n['accounts'] as $code => $name) {
                $v = $zero();
                foreach ($years as $y) {
                    $b = (float) (($isFlow ? $mov[$y] : $cum[$y])[$code]['balance'] ?? 0);
                    $root = $this->rootOf($code);
                    $v[$y] = in_array($root, [2, 3, 4, 7], true) ? -$b : $b;
                }
                $rows[] = ['code' => $code, 'label' => $name, 'values' => array_map($r2, $v)];
            }
            usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));
            $tot = $zero();
            foreach ($rows as $row) foreach ($years as $y) $tot[$y] += $row['values'][$y];
            $noteOut[] = ['no' => $n['no'], 'title' => strtoupper($cap), 'rows' => $rows, 'total' => array_map($r2, $tot)];
        }
        usort($noteOut, fn ($a, $b) => $a['no'] <=> $b['no']);

        /* ---------- checks shown in the UI ---------- */
        $checks = [];
        $get = fn ($lines, $id) => collect($lines)->firstWhere('id', $id)['values'] ?? [];
        foreach ($years as $y) {
            $checks[] = ['year' => $y, 'label' => 'Assets = Liabilities + Equity', 'diff' => $r2(($get($sfp, 't_assets')[$y] ?? 0) - ($get($sfp, 't_le')[$y] ?? 0))];
            $checks[] = ['year' => $y, 'label' => 'Equity statement = Balance sheet equity', 'diff' => $r2(($get($sce, 'end')[$y] ?? 0) - ($get($sfp, 't_eq')[$y] ?? 0))];
            $checks[] = ['year' => $y, 'label' => 'Cash flow ending cash = Cash per books', 'diff' => $r2(($get($scf, 'end')[$y] ?? 0) - $cashEnd[$y])];
        }

        $asOf = count($years) > 1 ? "As of December 31, {$y1} and {$years[1]}" : "As of December 31, {$y1}";
        $forYrs = count($years) > 1 ? "For the years ended December 31, {$y1} and {$years[1]}" : "For the year ended December 31, {$y1}";

        return response()->json($base + [
            'statements' => [
                ['key' => 'sfp', 'title' => 'STATEMENTS OF FINANCIAL POSITION', 'period' => $asOf, 'lines' => $sfp],
                ['key' => 'si', 'title' => 'STATEMENTS OF INCOME', 'period' => $forYrs, 'lines' => $si],
                ['key' => 'sce', 'title' => "STATEMENTS OF CHANGES IN OWNER'S EQUITY", 'period' => $forYrs, 'lines' => $sce],
                ['key' => 'scf', 'title' => 'STATEMENTS OF CASH FLOWS', 'period' => $forYrs, 'lines' => $scf],
            ],
            'notes' => $noteOut,
            'period_text' => ['as_of' => $asOf, 'for_years' => $forYrs],
            'checks' => $checks,
        ]);
    }
    /* =====================================================================
       RECEIVABLES / PAYABLES AGING
       GET /api/reports/aging?type=ar|ap&filter_type=AS|DM|DR|DE&from=&to=&party_id=&branch=current

         type         ar = receivables (sales invoices − collections)
                      ap = payables (vendor invoices + expenses − payments)
         filter_type  AS = as of the end of month "from" (Accounts Receivable / Payable tab)
                      DM / DR / DE = declared month / date range / date of entry (Aged tab)

       Paste INSIDE ReportController (uses journalScope, companyHeader, branchList).
    ===================================================================== */
    public function agingReport(Request $request)
    {
        $d = $request->validate([
            'type'        => ['required', 'in:ar,ap'],
            'filter_type' => ['required', 'in:AS,DM,DR,DE'],
            'from'        => ['required', 'string', 'max:10'],
            'to'          => ['nullable', 'string', 'max:10'],
            'party_id'    => ['nullable', 'integer'],
            'branch'      => ['nullable', 'string', 'max:20'],
        ]);
        $ar = $d['type'] === 'ar';
        [$ids, $client, $branches] = $this->journalScope($request, $d['branch'] ?? 'current');
        $base = ['company' => $this->companyHeader($client), 'branches' => $this->branchList($branches)];

        // --- period → as-of date (aging is counted back from this day)
        $ft = $d['filter_type'];
        $from = $d['from'];
        $to = $d['to'] ?? $d['from'];
        if ($ft === 'AS' || $ft === 'DM') {
            $from = substr($from, 0, 7);
            $to = substr($to, 0, 7);
            $asOf = Carbon::createFromFormat('Y-m-d', ($ft === 'AS' ? $from : $to) . '-01')->endOfMonth();
        } else {
            $asOf = Carbon::parse($to)->endOfDay();
        }
        $asOfDate = $asOf->toDateString();
        $buckets = $ft === 'AS'
            ? [['b1', '0 - 30', 0, 30], ['b2', '31 - 60', 31, 60], ['b3', '61 - 90', 61, 90], ['b4', 'Over 90 days', 91, null]]
            : [['b1', '0 - 30', 0, 30], ['b2', '31 - 60', 31, 60], ['b3', '61 - 90', 61, 90], ['b4', '91 - 120', 91, 120], ['b5', 'Over 120', 121, null]];

        $empty = $base + ['as_of' => $asOfDate, 'buckets' => array_map(fn ($b) => ['key' => $b[0], 'label' => $b[1]], $buckets), 'rows' => [], 'parties' => [], 'totals' => []];
        if (!$ids) return response()->json($empty);

        // --- invoices
        $voidId = (int) (DB::table('m_invoice_status')->whereRaw("UPPER(name) LIKE 'VOID%'")->value('id') ?: 5);
        $mt = $ar ? 't_sales_invoice_master' : 't_purchase_expense_master';
        $cols = Schema::getColumnListing($mt);
        $partyCol = $ar ? 'customer_id' : (in_array('vendor_id', $cols, true) ? 'vendor_id' : 'customer_id');

        $q = DB::table("{$mt} as M")->whereIn('M.added_by', $ids)
            ->where(fn ($w) => $w->whereNull('M.invoice_status_id')->orWhere('M.invoice_status_id', '<>', $voidId));
        if ($ar) {
            $q->where('M.trans_type', 'posted');
        } elseif (in_array('record_status', $cols, true)) {
            $q->where('M.record_status', 'posted');
        }

        // only invoices booked to the Accounts Receivable / Payable account (header "Accounts"; cash sales / cash purchases are not)
        $byIds = array_values(array_unique(array_filter(array_merge($ids, [(int) $this->addedByFor($request)]))));
        $arapCodes = ChartsOfAccount::whereIn('added_by', $byIds)->get(['account_code', 'account_name'])
            ->filter(fn ($a) => preg_match($ar ? '/accounts?\s*receivable/i' : '/accounts?\s*payable/i', (string) $a->account_name))
            ->pluck('account_code')->map(fn ($c) => (string) $c)->unique()->values()->all();
        $q->whereIn('M.account_code', $arapCodes ?: [$ar ? '12200' : '21100']);

        // only NOT paid: no receipt voucher (t_rvoucher_detail) / payment voucher (t_pvoucher_detail) at all
        $vt = $ar ? 't_rvoucher_detail' : 't_pvoucher_detail';
        $link = $ar ? 'invoice_no' : 'ap_invoice_no';
        if (Schema::hasTable($vt)) $q->whereNotExists(fn ($v) => $v->from($vt)->whereColumn("{$vt}.{$link}", 'M.invoice_no'));
        if ($ft === 'AS') $q->where('M.transaction_date', '<=', $asOfDate);
        if ($ft === 'DM') $q->whereBetween('M.declared_month', [$from, $to]);
        if ($ft === 'DR') $q->whereBetween('M.transaction_date', [$from, $to]);
        if ($ft === 'DE') $q->whereBetween(DB::raw('DATE(M.created_at)'), [$from, $to]);
        if (!empty($d['party_id'])) $q->where("M.{$partyCol}", $d['party_id']);

        $invoices = $q->get(['M.id', 'M.invoice_no', 'M.document_no', 'M.transaction_date', 'M.grand_total', "M.{$partyCol} as party_id",
            in_array('trans_type', $cols, true) ? 'M.trans_type' : DB::raw('NULL as trans_type')]);

        // --- paid / collected per invoice, up to the as-of date
        $paid = [];
        $nos = $invoices->pluck('invoice_no')->filter()->unique()->values();
        if ($nos->isNotEmpty()) {
            if ($ar && Schema::hasTable('t_rvoucher_detail')) {
                $paid = DB::table('t_rvoucher_detail')->whereIn('invoice_no', $nos)
                    ->where(fn ($w) => $w->whereNull('rv_date')->orWhere('rv_date', '<=', $asOfDate))
                    ->groupBy('invoice_no')->selectRaw('invoice_no, SUM(sub_total) AS t')->pluck('t', 'invoice_no')->all();
            }
            if (!$ar && Schema::hasTable('t_pvoucher_detail')) {
                $paid = DB::table('t_pvoucher_detail')->whereIn('ap_invoice_no', $nos)
                    ->where(fn ($w) => $w->whereNull('voucher_date')->orWhere('voucher_date', '<=', $asOfDate))
                    ->groupBy('ap_invoice_no')->selectRaw('ap_invoice_no AS invoice_no, SUM(amount) AS t')->pluck('t', 'invoice_no')->all();
            }
        }

        // --- parties
        $partyIds = $invoices->pluck('party_id')->filter()->unique();
        $names = DB::table('m_vendor_customer')->whereIn('id', $partyIds)->pluck('name', 'id');

        $rows = [];
        $totals = array_fill_keys(array_merge(array_column($buckets, 0), ['amount_due', 'grand_total', 'paid']), 0.0);
        foreach ($invoices as $inv) {
            $gross = round((float) $inv->grand_total, 2);
            $p = round((float) ($paid[$inv->invoice_no] ?? 0), 2);
            $due = round($gross - $p, 2);
            if (abs($due) < 0.005) continue; // fully paid

            $date = $inv->transaction_date ? substr((string) $inv->transaction_date, 0, 10) : null;
            $days = $date ? max(0, Carbon::parse($date)->diffInDays($asOf, false)) : 0;
            $days = (int) floor($days);
            $row = [
                'id' => $inv->id, 'party_id' => (int) $inv->party_id,
                'party_name' => trim((string) ($names[$inv->party_id] ?? '')) ?: ($ar ? 'WALK IN' : '—'),
                'invoice_no' => $inv->invoice_no, 'document_no' => $inv->document_no ?: $inv->invoice_no,
                'date' => $date, 'days' => $days, 'grand_total' => $gross, 'paid' => $p, 'amount_due' => $due,
                'trans_type' => $inv->trans_type,
            ];
            foreach ($buckets as [$k, , $lo, $hi]) {
                $row[$k] = ($days >= $lo && ($hi === null || $days <= $hi)) ? $due : 0.0;
                $totals[$k] += $row[$k];
            }
            $totals['amount_due'] += $due;
            $totals['grand_total'] += $gross;
            $totals['paid'] += $p;
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => [$a['party_name'], $a['date']] <=> [$b['party_name'], $b['date']]);

        // customers / vendors for the filter (everyone with an invoice in this scope)
        $parties = DB::table("{$mt} as M")->join('m_vendor_customer as V', 'V.id', '=', "M.{$partyCol}")
            ->whereIn('M.added_by', $ids)->distinct()->orderBy('V.name')->get(['V.id', 'V.name']);

        return response()->json($base + [
            'as_of' => $asOfDate,
            'buckets' => array_map(fn ($b) => ['key' => $b[0], 'label' => $b[1]], $buckets),
            'rows' => $rows,
            'totals' => array_map(fn ($v) => round($v, 2), $totals),
            'parties' => $parties,
        ]);
    }
    private const VS_VOID = 5;
    private static array $vsColCache = [];

    private function vsCols(string $table): array
    {
        // column lists are cached (1 day) so a report request doesn't query information_schema again and again (slow on XAMPP)
        // After a migration that adds a column, run: php artisan cache:clear
        return self::$vsColCache[$table] ??= (function () use ($table) {
            $load = fn () => Schema::getColumnListing($table);
            try {
                return Cache::remember("vs_cols:{$table}", 86400, $load);
            } catch (\Throwable $e) {
                return $load();
            }
        })();
    }

    private function vsPick(string $table, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, $this->vsCols($table), true)) return $n;
        return null;
    }

    private function vsTable(array $names): ?string
    {
        foreach ($names as $n) if ($this->vsCols($n)) return $n;
        return null;
    }

    private function vsOnly(string $table, array $row): array
    {
        return array_intersect_key($row, array_flip($this->vsCols($table)));
    }

    /** "12,15,x,15" → [12, 15] */
    private function vsIds($csv): array
    {
        $ids = array_map('intval', is_array($csv) ? $csv : explode(',', (string) $csv));
        return array_values(array_unique(array_filter($ids, fn ($v) => $v > 0)));
    }

    private function vsIn(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** ['2026-01', '2026-02', …] (old dateRange) */
    private function vsMonths(string $from, string $to): array
    {
        $out = [];
        for ($t = strtotime("{$from}-01"); $t <= strtotime("{$to}-01") && count($out) < 120; $t = strtotime('+1 month', $t)) {
            $out[] = date('Y-m', $t);
        }
        return $out;
    }

    private function vsUserCa(): string
    {
        return $this->vsPick('m_user_client', ['caid', 'ca_id', 'client_account_id']) ?? 'caid';
    }

    /** purchases / expenses split (old tbl2_apinvoice_detail.PaymentType): [join, column] */
    private function vsPeType(): array
    {
        // posted only (PurchaseExpenseMaster::posted(): record_status = 'posted')
        $posted = $this->vsPick('t_purchase_expense_master', ['record_status'])
            ? "INNER JOIN t_purchase_expense_master PM ON PM.invoice_no = A.invoice_no AND PM.record_status = 'posted'"
                . ($this->vsPick('t_purchase_expense_detail', ['record_status']) ? " AND A.record_status = 'posted'" : '')
            : 'LEFT JOIN t_purchase_expense_master PM ON PM.invoice_no = A.invoice_no';
        $c = $this->vsPick('t_purchase_expense_detail', ['trans_type', 'payment_type']);
        return [$posted, $c ? "A.{$c}" : 'PM.trans_type'];
    }

    /** old tbl2_apinvoice_detail.Total */
    private function vsPeTotal(string $a = 'A'): string
    {
        if ($this->vsPick('t_purchase_expense_detail', ['total'])) return "{$a}.total";
        return $this->vsPick('t_purchase_expense_detail', ['discount_amount'])
            ? "({$a}.amount - COALESCE({$a}.discount_amount, 0))" : "{$a}.amount";
    }

    private function vsVendor(): string
    {
        return $this->vsPick('m_vendor_customer', ['name', 'vendor_name']) ?? 'name';
    }

    private function vsStatusLabel(): ?string
    {
        return $this->vsPick('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']);
    }

    private function vsUndeclaredTable(): string
    {
        return $this->vsTable(['t_undeclared_trans', 'tbl2_undeclared_trans']) ?? 't_undeclared_trans';
    }

    private function vsRemarksTable(): string
    {
        return $this->vsTable(['t_summary_remarks', 'tbl2_summary_remarks']) ?? 't_summary_remarks';
    }

    /** old tbl2_declared_month_settings (a row for CAID + month = that month is closed) */
    private function vsLockTable(): ?string
    {
        return $this->vsTable(['t_declared_month_settings', 'm_declared_month_settings', 'declared_month_settings']);
    }

    private function vsVatPaidCodes(): array
    {
        $name = $this->vsPick('m_charts_of_account', ['account_name', 'name']);
        if (!$name) return [];
        return DB::table('m_charts_of_account')->where($name, 'like', '%Vat Paid%')
            ->distinct()->pluck('account_code')->map(fn ($c) => (string) $c)->filter()->values()->all();
    }

    /** the logins of one client (the user_ids sent back must belong to it) */
    private function vsClientUsers(array $ids): array
    {
        if (!$ids) return [];
        return DB::table('m_user_client')->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** reports use posted records only: sales tables → trans_type, purchase / expense tables → record_status */
    private function rpPosted($q, string $table, string $alias = '')
    {
        $col = match ($table) {
            't_sales_invoice_master', 't_sales_invoice_detail' => 'trans_type',
            't_purchase_expense_master', 't_purchase_expense_detail' => 'record_status',
            default => null,
        };
        return $col ? $q->where(($alias ? "{$alias}." : '') . $col, 'posted') : $q;
    }

    /** posted sales only: master and detail trans_type = 'posted' */
    private function vsSalesPosted(string $a, string $table = 't_sales_invoice_detail'): string
    {
        $col = $this->vsPick('t_sales_invoice_master', ['trans_type', 'record_status']);
        if (!$col) return '';
        if ($table === 't_sales_invoice_master') return " AND {$a}.{$col} = 'posted'";
        $dCol = $this->vsPick('t_sales_invoice_detail', ['trans_type', 'record_status']);
        return ($dCol ? " AND {$a}.{$dCol} = 'posted'" : '')
            . " AND EXISTS (SELECT 1 FROM t_sales_invoice_master SP WHERE SP.invoice_no = {$a}.invoice_no AND SP.{$col} = 'posted')";
    }

    private function vsMoney($v): float
    {
        return round((float) $v, 2);
    }

    /* ---------------- lookups ---------------- */

    public function vatClientsLookups(Request $request)
    {
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_employee', ['is_active', 'active']);
        $myDa = $this->reportDaOnly($request);
        return response()->json([
            'statuses' => $sl ? DB::table('m_client_account_status')->orderBy($sl)->get(['id', "{$sl} as name"]) : [],
            // old: tbl_employee WHERE DesignationID = 3
            'analysts' => DB::table('m_employee')->where('designation_id', 3)
                ->when($myDa, fn ($q) => $q->where('id', $myDa), fn ($q) => $q->when($active, fn ($q) => $q->where($active, 1)))
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', DB::raw("TRIM(CONCAT_WS(' ', last_name, first_name, middle_name)) AS name")]),
            'my_da'    => $myDa ?: null,
        ]);
    }

    /* ---------------- main list ---------------- */
    private function vsPeriodCond(string $alias, string $monthCol, string $basis): string
    {
        return $basis === 'DE'
            ? "{$alias}.created_at >= ? AND {$alias}.created_at < ?"
            : "{$alias}.{$monthCol} BETWEEN ? AND ?";
    }
 
    /** DM: months (a date is cut to its month) · DE: whole days — From 00:00 up to the day after To (a month = its first / last day) */
    private function vsPeriodBind(string $from, string $to, string $basis): array
    {
        if ($basis !== 'DE') return [substr($from, 0, 7), substr($to, 0, 7)];
        $start = strlen($from) === 7 ? Carbon::createFromFormat('Y-m-d', "{$from}-01") : Carbon::createFromFormat('Y-m-d', $from);
        $end = strlen($to) === 7 ? Carbon::createFromFormat('Y-m-d', "{$to}-01")->endOfMonth() : Carbon::createFromFormat('Y-m-d', $to);
        return [$start->startOfDay()->format('Y-m-d H:i:s'), $end->copy()->addDay()->startOfDay()->format('Y-m-d H:i:s')];
    }
    public function vatClientsSummary(Request $request)
    {
        $d = $request->validate([
            'from'      => ['required', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],   // YYYY-MM, or YYYY-MM-DD with basis=DE
            'to'        => ['required', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],   // YYYY-MM, or YYYY-MM-DD with basis=DE
            'status_id' => ['nullable', 'integer'],
            'da_id'     => ['nullable', 'integer'],
            'basis'     => ['nullable', 'in:DM,DE'],   // DM = declared month (default) · DE = date entry (created)
        ]);
        $from = $d['from'];
        $to = $d['to'];
        $statusId = (int) ($d['status_id'] ?? 0);
        $daId = (int) ($d['da_id'] ?? 0);
        $basis = $d['basis'] ?? 'DM';
        $pc = fn (string $col) => $this->vsPeriodCond('A', $col, $basis);
        $pb = $this->vsPeriodBind($from, $to, $basis);
        if ($mine = $this->reportDaOnly($request)) $daId = $mine;   // data analyst → his clients only
        $ca = $this->vsUserCa();
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_client', ['active', 'is_active']);
 
        // 1. VAT clients + their logins (old: GROUP BY ClientNum, B.TaxRegistration = 'VAT')
        $where = ["B.tax_registration = 'VAT'"];
        $bind = [];
        if ($active) $where[] = "A.{$active} = 1";
        if ($statusId > 0) { $where[] = 'A.status_id = ?'; $bind[] = $statusId; }
        if ($daId > 0) { $where[] = 'B.assigned_daid = ?'; $bind[] = $daId; }
        $clients = DB::select("
            SELECT A.client_num, MIN(A.id) AS client_id, MIN(A.last_name) AS sort_name,
                   MAX(TRIM(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name))) AS client_name,
                   " . ($sl ? "MAX(S.{$sl})" : "''") . " AS status,
                   GROUP_CONCAT(C.id ORDER BY C.id) AS user_ids, COUNT(C.id) AS count_acc,
                   MAX(CASE WHEN B.branch_type = 'MAIN' THEN B.id END) AS caid
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($sl ? 'LEFT JOIN m_client_account_status S ON S.id = A.status_id' : '') . '
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY A.client_num
            ORDER BY sort_name, A.client_num', $bind);
        if (!$clients) return response()->json(['rows' => []]);
 
        $users = [];
        foreach ($clients as $c) foreach ($this->vsIds($c->user_ids) as $u) $users[$u] = true;
        $users = array_keys($users);
 
        // 2. every amount in ONE query, per login (rounded per login like the old code)
        $in = $this->vsIn($users);
        $daSql = $daId > 0 ? ' AND A.assigned_daid = ?' : '';
        $daBind = $daId > 0 ? [$daId] : [];
        $sAud = $this->vsPick('t_sales_invoice_detail', ['assigned_auid', 'assigned_auditor_id']);
        $pAud = $this->vsPick('t_purchase_expense_detail', ['assigned_auid', 'assigned_auditor_id']);
        [$peJoin, $peType] = $this->vsPeType();
        $void = self::VS_VOID;
        $rows = DB::select("
            SELECT A.added_by AS uid, 'S' AS k,
                   ROUND(COALESCE(SUM(A.net_of_vat), 0), 2) AS nv, ROUND(COALESCE(SUM(A.vat_exempt), 0), 2) AS ve,
                   ROUND(COALESCE(SUM(A.zero_rated), 0), 2) AS zr, ROUND(COALESCE(SUM(A.input_vat), 0), 2) AS vat,
                   GROUP_CONCAT(DISTINCT A.assigned_daid) AS da, " . ($sAud ? "GROUP_CONCAT(DISTINCT A.{$sAud})" : 'NULL') . " AS au
            FROM t_sales_invoice_detail A
            WHERE A.added_by IN ({$in}) AND {$pc('declared_month')}
              AND A.invoice_status_id <> {$void} AND A.amount != 0 {$this->vsSalesPosted('A')} {$daSql}
            GROUP BY A.added_by
            UNION ALL
            SELECT A.added_by, {$peType},
                   ROUND(COALESCE(SUM(A.net_of_vat), 0), 2), ROUND(COALESCE(SUM(A.vat_exempt), 0), 2),
                   ROUND(COALESCE(SUM(A.zero_rated), 0), 2), ROUND(COALESCE(SUM(A.input_vat), 0), 2),
                   GROUP_CONCAT(DISTINCT A.assigned_daid), " . ($pAud ? "GROUP_CONCAT(DISTINCT A.{$pAud})" : 'NULL') . "
            FROM t_purchase_expense_detail A {$peJoin}
            WHERE A.added_by IN ({$in}) AND {$pc('declared_month')}
              AND A.invoice_status_id <> {$void} AND {$this->vsPeTotal()} != 0 {$daSql}
            GROUP BY A.added_by, {$peType}
            UNION ALL
            SELECT A.added_by, 'W', ROUND(COALESCE(SUM(A.`2307_vt`), 0), 2), 0, 0, 0, NULL, NULL
            FROM t_sales_invoice_master A
            WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0
              AND {$pc('`2307_vt_declared_month`')} {$this->vsSalesPosted('A', 't_sales_invoice_master')} {$daSql}
            GROUP BY A.added_by",
            [...$users, ...$pb, ...$daBind, ...$users, ...$pb, ...$daBind, ...$users, ...$pb, ...$daBind]);
 
        $by = [];
        $empIds = [];
        foreach ($rows as $r) {
            $k = $r->k === 'S' || $r->k === 'W' ? $r->k : (strcasecmp((string) $r->k, 'Purchases') === 0 ? 'P' : (strcasecmp((string) $r->k, 'Expenses') === 0 ? 'E' : null));
            if (!$k) continue;
            $by[(int) $r->uid][$k] = $r;
            foreach ([$r->da, $r->au] as $list) foreach ($this->vsIds($list) as $e) $empIds[$e] = true;
        }
        $names = $empIds ? DB::table('m_employee')->whereIn('id', array_keys($empIds))
            ->pluck(DB::raw("TRIM(CONCAT_WS(' ', last_name, first_name, middle_name))"), 'id')->all() : [];
 
        // 3. add the logins up per client (old step 5)
        $out = [];
        foreach ($clients as $c) {
            $s = ['nv' => 0, 've' => 0, 'zr' => 0, 'vat' => 0];
            $p = ['nv' => 0, 've' => 0, 'vat' => 0];
            $e = ['nv' => 0, 've' => 0, 'vat' => 0];
            $wt = 0;
            $da = $au = [];
            foreach ($this->vsIds($c->user_ids) as $uid) {
                $x = $by[$uid] ?? [];
                if (isset($x['S'])) foreach ($s as $f => $v) $s[$f] += (float) $x['S']->{$f};
                if (isset($x['P'])) foreach ($p as $f => $v) $p[$f] += (float) $x['P']->{$f};
                if (isset($x['E'])) foreach ($e as $f => $v) $e[$f] += (float) $x['E']->{$f};
                if (isset($x['W'])) $wt += (float) $x['W']->nv;
                foreach (['S', 'P', 'E'] as $k) {
                    if (!isset($x[$k])) continue;
                    foreach ($this->vsIds($x[$k]->da) as $id) if (!empty($names[$id])) $da[$names[$id]] = true;
                    foreach ($this->vsIds($x[$k]->au) as $id) if (!empty($names[$id])) $au[$names[$id]] = true;
                }
            }
            $input = $p['vat'] + $e['vat'];
            $exempt = $p['ve'] + $e['ve'];
            $out[] = [
                'client_id'           => (int) $c->client_id,
                'client_num'          => $c->client_num,
                'client_name'         => $c->client_name,
                'user_ids'            => implode(',', $this->vsIds($c->user_ids)),
                'caid'                => $c->caid ? (int) $c->caid : null,
                'count_acc'           => (int) $c->count_acc,
                'status'              => (string) $c->status,
                'da_assigned'         => array_keys($da),
                'auditor_assigned'    => array_keys($au),
                'vatable_sales'       => $this->vsMoney($s['nv']),
                'exempt_sales'        => $this->vsMoney($s['ve']),
                'zero_rated'          => $this->vsMoney($s['zr']),
                'total_sales'         => $this->vsMoney($s['nv'] + $s['ve'] + $s['zr']),
                'output_vat'          => $this->vsMoney($s['vat']),
                'vatable_purchases'   => $this->vsMoney($p['nv']),
                'vatable_expenses'    => $this->vsMoney($e['nv']),
                'exempt_purchase_exp' => $this->vsMoney($exempt),
                'total_purchase_exp'  => $this->vsMoney($p['nv'] + $e['nv'] + $exempt),
                'input_vat'           => $this->vsMoney($input),
                'creditable_wt'       => $this->vsMoney($wt),
                'net_vat_payable'     => $this->vsMoney($s['vat'] - $input - $wt),
            ];
        }
        return response()->json(['rows' => $out]);
    }

    /* ---------------- branch rows of one client ---------------- */

    public function vatClientsBranches(Request $request)
    {
        $d = $request->validate([
            'from'       => ['required', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],   // YYYY-MM, or YYYY-MM-DD with basis=DE
            'to'         => ['required', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],   // YYYY-MM, or YYYY-MM-DD with basis=DE
            'client_num' => ['required', 'string', 'max:50'],
            'basis'      => ['nullable', 'in:DM,DE'],
        ]);
        $basis = $d['basis'] ?? 'DM';
        $pc = fn (string $col) => $this->vsPeriodCond('A', $col, $basis);
        $ca = $this->vsUserCa();
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_client', ['active', 'is_active']);
        $accounts = DB::select("
            SELECT A.id AS client_id, A.client_num, B.id AS caid, C.id AS uid,
                   TRIM(CONCAT_WS(' : ', B.branch_code, B.trade_name)) AS client_name,
                   " . ($sl ? "S.{$sl}" : "''") . " AS status
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($sl ? 'LEFT JOIN m_client_account_status S ON S.id = B.status_id' : '') . '
            WHERE A.client_num = ? ' . ($active ? "AND A.{$active} = 1" : '') . '
            ORDER BY B.branch_code, B.trade_name', [$d['client_num']]);
        if (!$accounts) return response()->json(['rows' => []]);
 
        $users = array_map(fn ($a) => (int) $a->uid, $accounts);
        $in = $this->vsIn($users);
        $b = $this->vsPeriodBind($d['from'], $d['to'], $basis);
        [$peJoin, $peType] = $this->vsPeType();
        $void = self::VS_VOID;
        // old: each invoice rounded first, then added up
        $rows = DB::select("
            SELECT A.added_by AS uid, 'S' AS k, SUM(ROUND(COALESCE(A.net_of_vat, 0), 2)) AS nv, SUM(ROUND(COALESCE(A.vat_exempt, 0), 2)) AS ve,
                   SUM(ROUND(COALESCE(A.zero_rated, 0), 2)) AS zr, SUM(ROUND(COALESCE(A.input_vat, 0), 2)) AS vat
            FROM t_sales_invoice_detail A
            WHERE A.added_by IN ({$in}) AND {$pc('declared_month')} AND A.invoice_status_id <> {$void} AND A.amount != 0{$this->vsSalesPosted('A')}
            GROUP BY A.added_by
            UNION ALL
            SELECT A.added_by, {$peType}, SUM(ROUND(COALESCE(A.net_of_vat, 0), 2)), SUM(ROUND(COALESCE(A.vat_exempt, 0), 2)),
                   SUM(ROUND(COALESCE(A.zero_rated, 0), 2)), SUM(ROUND(COALESCE(A.input_vat, 0), 2))
            FROM t_purchase_expense_detail A {$peJoin}
            WHERE A.added_by IN ({$in}) AND {$pc('declared_month')} AND A.invoice_status_id <> {$void} AND {$this->vsPeTotal()} != 0
            GROUP BY A.added_by, {$peType}
            UNION ALL
            SELECT A.added_by, 'W', SUM(ROUND(COALESCE(A.`2307_vt`, 0), 2)), 0, 0, 0
            FROM t_sales_invoice_master A
            WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0 AND {$pc('`2307_vt_declared_month`')}{$this->vsSalesPosted('A', 't_sales_invoice_master')}
            GROUP BY A.added_by", [...$users, ...$b, ...$users, ...$b, ...$users, ...$b]);
        $by = [];
        foreach ($rows as $r) {
            $k = $r->k === 'S' || $r->k === 'W' ? $r->k : (strcasecmp((string) $r->k, 'Purchases') === 0 ? 'P' : (strcasecmp((string) $r->k, 'Expenses') === 0 ? 'E' : null));
            if ($k) $by[(int) $r->uid][$k] = $r;
        }
 
        $out = [];
        foreach ($accounts as $a) {
            $x = $by[(int) $a->uid] ?? [];
            $g = fn ($k, $f) => (float) ($x[$k]->{$f} ?? 0);
            $input = $g('P', 'vat') + $g('E', 'vat');
            $exempt = $g('P', 've') + $g('E', 've');
            $out[] = [
                'client_id'           => (int) $a->client_id,
                'client_num'          => $a->client_num,
                'client_name'         => $a->client_name,
                'user_ids'            => (string) $a->uid,
                'caid'                => (int) $a->caid,
                'count_acc'           => 1,
                'status'              => (string) $a->status,
                'vatable_sales'       => $this->vsMoney($g('S', 'nv')),
                'exempt_sales'        => $this->vsMoney($g('S', 've')),
                'zero_rated'          => $this->vsMoney($g('S', 'zr')),
                'total_sales'         => $this->vsMoney($g('S', 'nv') + $g('S', 've') + $g('S', 'zr')),
                'output_vat'          => $this->vsMoney($g('S', 'vat')),
                'vatable_purchases'   => $this->vsMoney($g('P', 'nv')),
                'vatable_expenses'    => $this->vsMoney($g('E', 'nv')),
                'exempt_purchase_exp' => $this->vsMoney($exempt),
                'total_purchase_exp'  => $this->vsMoney($g('P', 'nv') + $g('E', 'nv') + $exempt),
                'input_vat'           => $this->vsMoney($input),
                'creditable_wt'       => $this->vsMoney($g('W', 'nv')),
                'net_vat_payable'     => $this->vsMoney($g('S', 'vat') - $input), // old detail rows: no 2307 here
            ];
        }
        return response()->json(['rows' => $out]);
    }

    /* ---------------- documents behind one amount ---------------- */

    public function vatClientsAmounts(Request $request)
    {
        $types = ['vatable_sales', 'exempt_sales', 'zero_rated', 'total_sales', 'vatable_purchases', 'vatable_expenses',
            'exempt_purchase_exp', 'total_purchase_exp', 'output_vat', 'input_vat', 'creditable_wt'];
        $d = $request->validate([
            'type'     => ['required', 'in:' . implode(',', $types)],
            'user_ids' => ['required', 'string'],
            'from'     => ['required', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],   // YYYY-MM, or YYYY-MM-DD with basis=DE
            'to'       => ['required', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],   // YYYY-MM, or YYYY-MM-DD with basis=DE
            'basis'    => ['nullable', 'in:DM,DE'],
        ]);
        $basis = $d['basis'] ?? 'DM';
        $pc = fn (string $col) => $this->vsPeriodCond('A', $col, $basis);
        $users = $this->vsClientUsers($this->vsIds($d['user_ids']));
        if (!$users) return response()->json([]);
        $in = $this->vsIn($users);
        $v = $this->vsVendor();
        $void = self::VS_VOID;
        [$peJoin, $peType] = $this->vsPeType();
 
        $sales = fn (string $amount, string $cond) => "
            SELECT A.id, B.{$v} AS vendor_name, A.transaction_date, A.declared_month, A.document_no, {$amount} AS amount
            FROM t_sales_invoice_detail A INNER JOIN m_vendor_customer B ON B.id = A.customer_id
            WHERE A.added_by IN ({$in}) AND {$pc('declared_month')} AND A.invoice_status_id <> {$void} AND {$cond}{$this->vsSalesPosted('A')}
            ORDER BY A.id DESC";
        $ap = fn (string $amount, string $cond) => "
            SELECT A.id, B.{$v} AS vendor_name, A.transaction_date, A.declared_month, A.document_no, {$amount} AS amount
            FROM t_purchase_expense_detail A INNER JOIN m_vendor_customer B ON B.id = A.vendor_id {$peJoin}
            WHERE A.added_by IN ({$in}) AND {$pc('declared_month')} AND A.invoice_status_id <> {$void} AND {$cond}
            ORDER BY A.id DESC";
 
        $sql = match ($d['type']) {
            'vatable_sales'       => $sales('A.net_of_vat', 'A.net_of_vat != 0'),
            'exempt_sales'        => $sales('A.vat_exempt', 'A.vat_exempt != 0'),
            'zero_rated'          => $sales('A.zero_rated', 'A.zero_rated != 0'),
            'total_sales'         => $sales('(A.net_of_vat + A.vat_exempt + A.zero_rated)', '(A.net_of_vat != 0 OR A.vat_exempt != 0 OR A.zero_rated != 0)'),
            'output_vat'          => $sales('A.input_vat', 'A.input_vat != 0'),
            'vatable_purchases'   => $ap('A.net_of_vat', "A.net_of_vat != 0 AND {$peType} = 'Purchases'"),
            'vatable_expenses'    => $ap('A.net_of_vat', "A.net_of_vat != 0 AND {$peType} = 'Expenses'"),
            'exempt_purchase_exp' => $ap('A.vat_exempt', 'A.vat_exempt != 0'),
            'total_purchase_exp'  => $ap('(A.net_of_vat + A.vat_exempt)', '(A.net_of_vat != 0 OR A.vat_exempt != 0)'),
            'input_vat'           => $ap('A.input_vat', 'A.input_vat != 0'),
            'creditable_wt'       => "
                SELECT A.id, B.{$v} AS vendor_name, A.transaction_date, A.`2307_vt_declared_month` AS declared_month, A.document_no, A.`2307_vt` AS amount
                FROM t_sales_invoice_master A INNER JOIN m_vendor_customer B ON B.id = A.customer_id
                WHERE A.added_by IN ({$in}) AND {$pc('`2307_vt_declared_month`')} AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0{$this->vsSalesPosted('A', 't_sales_invoice_master')}
                ORDER BY A.id DESC",
        };
        $rows = DB::select($sql, [...$users, ...$this->vsPeriodBind($d['from'], $d['to'], $basis)]);
        return response()->json(array_map(fn ($r) => [
            'id'               => (int) $r->id,
            'vendor_name'      => $r->vendor_name,
            'transaction_date' => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : null,
            'declared_month'   => $r->declared_month,
            'document_no'      => $r->document_no,
            'amount'           => (float) $r->amount,
        ], $rows));
    }
    /* ---------------- remarks ---------------- */

    public function vatClientsRemarks(Request $request)
    {
        $d = $request->validate(['client_num' => ['required', 'string', 'max:50'], 'month' => ['required', 'date_format:Y-m']]);
        $t = $this->vsRemarksTable();
        if (!$this->vsCols($t)) return response()->json(['remarks' => '']);
        $r = DB::table($t)->where('client_num', $d['client_num'])->where('declared_month', $d['month'])->orderByDesc('id')->value('remarks');
        return response()->json(['remarks' => (string) $r]);
    }

    public function vatClientsSaveRemarks(Request $request)
    {
        $d = $request->validate([
            'client_num' => ['required', 'string', 'max:50'],
            'month'      => ['required', 'date_format:Y-m'],
            'remarks'    => ['nullable', 'string', 'max:65000'],
        ]);
        $t = $this->vsRemarksTable();
        abort_unless($this->vsCols($t), 422, 'The remarks table is missing — run php artisan migrate.');
        $key = ['client_num' => $d['client_num'], 'declared_month' => $d['month']];
        $now = now();
        if (DB::table($t)->where($key)->exists()) {
            DB::table($t)->where($key)->update($this->vsOnly($t, ['remarks' => $d['remarks'] ?? '', 'updated_at' => $now]));
        } else {
            DB::table($t)->insert($this->vsOnly($t, $key + ['remarks' => $d['remarks'] ?? '', 'created_at' => $now, 'updated_at' => $now]));
        }
        return response()->json(['ok' => true]);
    }

    /* ---------------- E-BIR (2550M / 2550Q) ---------------- */

    /**
     * Rows "month → sums" for one source. $adj = null: the original numbers (adjusted = false) or the numbers
     * without the pending undeclared invoices (adjusted = true).
     */
    private function vsEbirSums(array $users, string $from, string $to, bool $adjusted): array
    {
        $in = $this->vsIn($users);
        $void = self::VS_VOID;
        $ut = $this->vsUndeclaredTable();
        [$peJoin, $peType] = $this->vsPeType();
        $codes = $this->vsVatPaidCodes();
        $tp = $codes ? 'A.account_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')' : '0 = 1';
        // the pending undeclared invoices are left out of the adjusted view (old: NOT EXISTS … IsApproved = 0)
        $skip = fn (string $ref) => $adjusted
            ? "LEFT JOIN (SELECT DISTINCT reference_no, old_declared_month FROM {$ut} WHERE is_approved = 0) P
                 ON P.reference_no = {$ref} AND P.old_declared_month = A.declared_month"
            : '';
        $keep = $adjusted ? 'AND P.reference_no IS NULL' : '';
        $b = [...$users, $from, $to];
        $sum = [];

        // sales (+ output VAT: original view × the invoice's exchange rate, adjusted view without — like the old code)
        $sum['sales'] = DB::select("
            SELECT A.declared_month AS m,
                   SUM(A.net_of_vat * A.ex_rate) AS nv, SUM(A.vat_exempt * A.ex_rate) AS ve, SUM(A.zero_rated * A.ex_rate) AS zr,
                   SUM(CASE WHEN A.input_vat != 0 " . ($adjusted ? 'THEN A.input_vat' : 'AND B.invoice_no IS NOT NULL THEN A.input_vat * A.ex_rate') . " END) AS ov,
                   SUM(CASE WHEN A.input_vat != 0 " . ($adjusted ? 'THEN A.input_vat' : 'AND B.invoice_no IS NOT NULL THEN A.input_vat * B.ex_rate') . " END) AS ovt
            FROM t_sales_invoice_detail A
            " . ($adjusted ? '' : 'LEFT JOIN t_sales_invoice_master B ON B.invoice_no = A.invoice_no') . "
            {$skip('A.invoice_no')}
            WHERE A.added_by IN ({$in}) AND A.declared_month BETWEEN ? AND ? AND A.invoice_status_id <> {$void} {$keep}{$this->vsSalesPosted('A')}
            GROUP BY A.declared_month", $b);

        // receive inventory (status is on the master)
        $sum['grn'] = DB::select("
            SELECT A.declared_month AS m, SUM(A.net_of_vat) AS nv, SUM(A.vat_exempt) AS ve, SUM(A.zero_rated) AS zr, SUM(A.input_vat) AS iv
            FROM t_grn_detail A
            INNER JOIN t_grn_master GM ON GM.grn_no = A.grn_no AND GM.invoice_status_id <> {$void}
            {$skip('A.grn_no')}
            WHERE A.receive_by IN ({$in}) AND A.declared_month BETWEEN ? AND ? {$keep}
            GROUP BY A.declared_month", $b);

        // AP (purchases / expenses) + tax paid (VAT Paid accounts, any status — like the old code)
        $sum['ap'] = DB::select("
            SELECT A.declared_month AS m, {$peType} AS t,
                   SUM(CASE WHEN A.invoice_status_id <> {$void} THEN A.net_of_vat END) AS nv,
                   SUM(CASE WHEN A.invoice_status_id <> {$void} THEN A.vat_exempt END) AS ve,
                   SUM(CASE WHEN A.invoice_status_id <> {$void} THEN A.zero_rated END) AS zr,
                   SUM(CASE WHEN A.invoice_status_id <> {$void} THEN A.input_vat END) AS iv,
                   SUM(CASE WHEN {$tp} THEN {$this->vsPeTotal()} END) AS tp
            FROM t_purchase_expense_detail A {$peJoin}
            {$skip('A.invoice_no')}
            WHERE A.added_by IN ({$in}) AND A.declared_month BETWEEN ? AND ? {$keep}
            GROUP BY A.declared_month, {$peType}", [...$codes, ...$b]);

        // importation
        $sum['imp'] = DB::select("
            SELECT A.declared_month AS m,
                   SUM(CASE WHEN A.taxable_imports > 0 THEN A.taxable_imports * A.ex_rate END) AS nv,
                   SUM(CASE WHEN A.amount_of_vat > 0 THEN A.amount_of_vat * A.ex_rate END) AS iv
            FROM t_importation_detail A
            {$skip('A.invoice_no')}
            WHERE A.added_by IN ({$in}) AND A.declared_month BETWEEN ? AND ? AND A.invoice_status_id <> {$void} {$keep}
            GROUP BY A.declared_month", $b);

        return $sum;
    }

    /** adjusted view: the pending undeclared invoices, put on their NEW declared month (old "…_Adj" sub-queries) */
    private function vsEbirMoved(array $users, string $from, string $to): array
    {
        $in = $this->vsIn($users);
        $void = self::VS_VOID;
        $ut = $this->vsUndeclaredTable();
        [$peJoin, $peType] = $this->vsPeType();
        $b = [...$users, $from, $to];
        $join = fn (string $ref) => "INNER JOIN {$ut} U ON U.reference_no = {$ref} AND U.is_approved = 0";
        $sum = [];
        $sum['sales'] = DB::select("
            SELECT U.new_declared_month AS m,
                   SUM(A.net_of_vat * A.ex_rate) AS nv, SUM(A.vat_exempt * A.ex_rate) AS ve, SUM(A.zero_rated * A.ex_rate) AS zr,
                   SUM(CASE WHEN A.input_vat != 0 THEN A.input_vat END) AS ov, SUM(CASE WHEN A.input_vat != 0 THEN A.input_vat END) AS ovt
            FROM t_sales_invoice_detail A {$join('A.invoice_no')}
            WHERE A.added_by IN ({$in}) AND U.new_declared_month BETWEEN ? AND ? AND A.invoice_status_id <> {$void}{$this->vsSalesPosted('A')}
            GROUP BY U.new_declared_month", $b);
        $sum['grn'] = DB::select("
            SELECT U.new_declared_month AS m, SUM(A.net_of_vat) AS nv, SUM(A.vat_exempt) AS ve, SUM(A.zero_rated) AS zr, SUM(A.input_vat) AS iv
            FROM t_grn_detail A
            INNER JOIN t_grn_master GM ON GM.grn_no = A.grn_no AND GM.invoice_status_id <> {$void}
            {$join('A.grn_no')}
            WHERE A.receive_by IN ({$in}) AND U.new_declared_month BETWEEN ? AND ?
            GROUP BY U.new_declared_month", $b);
        $sum['ap'] = DB::select("
            SELECT U.new_declared_month AS m, {$peType} AS t,
                   SUM(A.net_of_vat) AS nv, SUM(A.vat_exempt) AS ve, SUM(A.zero_rated) AS zr, SUM(A.input_vat) AS iv, 0 AS tp
            FROM t_purchase_expense_detail A {$peJoin} {$join('A.invoice_no')}
            WHERE A.added_by IN ({$in}) AND U.new_declared_month BETWEEN ? AND ? AND A.invoice_status_id <> {$void}
            GROUP BY U.new_declared_month, {$peType}", $b);
        // old code: importation stays on its own declared month (net of VAT: own month in range · VAT: new month in range)
        $sum['imp'] = DB::select("
            SELECT A.declared_month AS m,
                   SUM(CASE WHEN A.declared_month BETWEEN ? AND ? AND A.taxable_imports > 0 THEN A.taxable_imports * A.ex_rate END) AS nv,
                   SUM(CASE WHEN U.new_declared_month BETWEEN ? AND ? AND A.amount_of_vat > 0 THEN A.amount_of_vat * A.ex_rate END) AS iv
            FROM t_importation_detail A {$join('A.invoice_no')}
            WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void}
              AND (A.declared_month BETWEEN ? AND ? OR U.new_declared_month BETWEEN ? AND ?)
            GROUP BY A.declared_month", [$from, $to, $from, $to, ...$users, $from, $to, $from, $to]);
        return $sum;
    }

    public function vatClientsEbir(Request $request)
    {
        $d = $request->validate([
            'user_ids'  => ['required', 'string'],
            'client_id' => ['required', 'integer'],
            'caid'      => ['nullable', 'integer'],
            'from'      => ['required', 'date_format:Y-m'],
            'to'        => ['required', 'date_format:Y-m'],
            'mode'      => ['nullable', 'in:initial,adjusted'],
        ]);
        $from = $d['from'];
        $to = $d['to'];
        abort_if($from > $to, 422, 'From must be on or before To.');
        $adjusted = ($d['mode'] ?? 'initial') === 'adjusted';
        $users = $this->vsClientUsers($this->vsIds($d['user_ids']));
        abort_unless($users, 422, 'This client has no login.');
        $months = $this->vsMonths($from, $to);
        $zero = array_fill_keys($months, 0.0);
 
        // one bucket per line; "total" also counts rows whose declared month is not a plain YYYY-MM (old Total column)
        $lines = [];
        foreach (['Sales' => ['NetOfVAT', 'VATExempt', 'ZeroRated'], 'Purchases' => ['NetOfVAT', 'VATExempt', 'ZeroRated'],
                     'Importation' => ['NetOfVAT'], 'Expenses' => ['NetOfVAT', 'VATExempt', 'ZeroRated']] as $tag => $codes) {
            foreach ($codes as $c) $lines[$tag][$c] = ['values' => $zero, 'total' => 0.0];
        }
        $other = ['ov' => ['values' => $zero, 'total' => 0.0], 'iv' => ['values' => $zero, 'total' => 0.0], 'tp' => ['values' => $zero, 'total' => 0.0]];
        $outputTotal = 0.0;
        $add = function (array &$cell, $m, $v) {
            $v = (float) $v;
            $cell['total'] += $v;
            if (array_key_exists($m, $cell['values'])) $cell['values'][$m] += $v;
        };
        $fold = function (array $sum) use (&$lines, &$other, &$outputTotal, $add) {
            $map = ['nv' => 'NetOfVAT', 've' => 'VATExempt', 'zr' => 'ZeroRated'];
            foreach ($sum['sales'] as $r) {
                foreach ($map as $f => $c) $add($lines['Sales'][$c], $r->m, $r->{$f});
                $add($other['ov'], $r->m, $r->ov);
                $outputTotal += (float) $r->ov;   // same figures as the months (Total = sum of the months)
            }
            foreach ($sum['grn'] as $r) {
                foreach ($map as $f => $c) $add($lines['Purchases'][$c], $r->m, $r->{$f});
                $add($other['iv'], $r->m, $r->iv);
            }
            foreach ($sum['ap'] as $r) {
                $tag = strcasecmp((string) $r->t, 'Purchases') === 0 ? 'Purchases' : (strcasecmp((string) $r->t, 'Expenses') === 0 ? 'Expenses' : null);
                if ($tag) foreach ($map as $f => $c) $add($lines[$tag][$c], $r->m, $r->{$f});
                $add($other['iv'], $r->m, $r->iv);
                $add($other['tp'], $r->m, $r->tp);
            }
            foreach ($sum['imp'] as $r) {
                $add($lines['Importation']['NetOfVAT'], $r->m, $r->nv);
                $add($other['iv'], $r->m, $r->iv);
            }
        };
        $fold($this->vsEbirSums($users, $from, $to, $adjusted));
        if ($adjusted) $fold($this->vsEbirMoved($users, $from, $to));
 
        // 2307 (VT) withheld on sales
        $void = self::VS_VOID;
        $in = $this->vsIn($users);
        $ut = $this->vsUndeclaredTable();
        $vt = ['values' => $zero, 'total' => 0.0];   // per month in BOTH views (Original + See adjustment)
        if (!$adjusted) {
            foreach (DB::select("
                SELECT LEFT(A.`2307_vt_declared_month`, 7) AS m, SUM(A.`2307_vt`) AS v
                FROM t_sales_invoice_master A
                WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0
                  AND A.`2307_vt_declared_month` BETWEEN ? AND ?{$this->vsSalesPosted('A', 't_sales_invoice_master')}
                GROUP BY LEFT(A.`2307_vt_declared_month`, 7)", [...$users, $from, $to]) as $r) $add($vt, $r->m, $r->v);
        } else {
            // See adjustment, per month: invoices not undeclared → their 2307 VT month · undeclared ones → their NEW month
            foreach (DB::select("
                SELECT X.m, SUM(X.v) AS v FROM (
                    SELECT LEFT(A.`2307_vt_declared_month`, 7) AS m, A.`2307_vt` AS v
                    FROM t_sales_invoice_master A
                    LEFT JOIN (SELECT DISTINCT reference_no, old_declared_month FROM {$ut} WHERE is_approved = 0) P
                      ON P.reference_no = A.invoice_no AND P.old_declared_month = A.declared_month
                    WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0
                      AND A.`2307_vt_declared_month` BETWEEN ? AND ? AND P.reference_no IS NULL{$this->vsSalesPosted('A', 't_sales_invoice_master')}
                    UNION ALL
                    SELECT LEFT(U.new_declared_month, 7) AS m, A.`2307_vt` AS v
                    FROM t_sales_invoice_master A
                    INNER JOIN {$ut} U ON U.reference_no = A.invoice_no AND U.is_approved = 0
                    WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0
                      AND U.new_declared_month BETWEEN ? AND ?{$this->vsSalesPosted('A', 't_sales_invoice_master')}
                ) X GROUP BY X.m", [...$users, $from, $to, ...$users, $from, $to]) as $r) $add($vt, $r->m, $r->v);
        }
 
        // build the table the page shows (old HTML table, same rows)
        $names = ['NetOfVAT' => 'Net of VAT', 'VATExempt' => 'VAT Exempt', 'ZeroRated' => 'Zero Rated'];
        $sections = [];
        $pe = ['values' => $zero, 'total' => 0.0];
        $salesSub = ['values' => $zero, 'total' => 0.0];
        foreach ($lines as $tag => $codes) {
            $sub = ['values' => $zero, 'total' => 0.0];
            $rows = [];
            foreach ($codes as $code => $cell) {
                $rows[] = ['code' => $code, 'name' => $names[$code], 'values' => $cell['values'], 'total' => $cell['total']];
                foreach ($months as $m) $sub['values'][$m] += $cell['values'][$m];
                $sub['total'] += $cell['total'];
            }
            if ($tag === 'Sales') $salesSub = $sub;
            else {
                foreach ($months as $m) $pe['values'][$m] += $sub['values'][$m];
                $pe['total'] += $sub['total'];
            }
            $sections[] = ['tag' => $tag, 'rows' => $rows, 'subtotal' => $sub];
        }
        $net = ['values' => [], 'total' => $salesSub['total'] - $pe['total']];
        $due = ['values' => [], 'total' => $outputTotal - $other['iv']['total']];
        foreach ($months as $m) {
            $net['values'][$m] = $salesSub['values'][$m] - $pe['values'][$m];
            $due['values'][$m] = $other['ov']['values'][$m] - $other['iv']['values'][$m];
        }
        $first = $other['tp']['values'][$months[0]] ?? 0.0;
        $second = isset($months[1]) ? $other['tp']['values'][$months[1]] : 0.0;
        // old CalculateTotalVATPayableExcess(): VAT due − (1st month + 2nd month + 2307 VT), from the 2-decimal figures
        $payable = round($due['total'] - (round($first, 2) + round($second, 2) + round($vt['total'], 2)), 2);
 
        $clientId = (int) $d['client_id'];
        $pending = DB::table($ut)->where('client_id', $clientId)->whereBetween('old_declared_month', [$from, $to])
            ->where('is_approved', 0)->where('report', 'EBIR')->count();
        $lock = $this->vsLockTable();
        $lockCa = $lock ? $this->vsPick($lock, ['ca_id', 'caid']) : null;
 
        return response()->json([
            'mode'              => $adjusted ? 'adjusted' : 'initial',
            'months'            => $months,
            'sections'          => $sections,
            'total_purchase_exp' => $pe,
            'net_total'         => $net,
            'output_vat'        => ['values' => $other['ov']['values'], 'total' => $outputTotal],
            'input_vat'         => $other['iv'],
            'vat_due'           => $due,
            'first_month'       => $first,
            'second_month'      => $second,
            'vt_2307'           => $vt,
            'vat_payable'       => $payable,
            'undeclared_amount' => $this->vsUndeclaredAmount($clientId, $from, $to),
            'pending_count'     => $pending,
            // closed months for this account (old doGetDeclaredMonthList): the undeclare popup can't pick these
            'locked_months'     => ($lock && $lockCa && !empty($d['caid']))
                ? DB::table($lock)->where($lockCa, (int) $d['caid'])->orderByDesc('declared_month')->pluck('declared_month')->map(fn ($m) => substr((string) $m, 0, 7))->values()
                : [],
        ]);
    }

    /** old doGetUndeclaredAmount: sales invoices waiting to be moved */
    private function vsUndeclaredAmount(int $clientId, string $from, string $to): float
    {
        $refs = DB::table($this->vsUndeclaredTable())->where('client_id', $clientId)
            ->whereBetween('old_declared_month', [$from, $to])
            ->where('is_approved', 0)->where('report', 'EBIR')
            ->distinct()->pluck('reference_no')->all();
        return round(array_sum(array_column($this->vsPendingNet($refs), 'net')), 2);
    }

    /* ---------------- documents behind one E-BIR cell ---------------- */

    public function vatClientsEbirDetails(Request $request)
    {
        $d = $request->validate([
            'user_ids' => ['required', 'string'],
            'month'    => ['required', 'date_format:Y-m'],
            'type'     => ['required', 'in:Sales,Purchases,Expenses,Importation,OutputVAT,InputVAT'],
            'code'     => ['nullable', 'in:NetOfVAT,VATExempt,ZeroRated,OutputVAT,inputVAT'],
        ]);
        $users = $this->vsClientUsers($this->vsIds($d['user_ids']));
        if (!$users) return response()->json([]);
        $type = $d['type'];
        $code = $d['code'] ?? 'NetOfVAT';
        $col = ['NetOfVAT' => 'net_of_vat', 'VATExempt' => 'vat_exempt', 'ZeroRated' => 'zero_rated'][$code] ?? 'net_of_vat';
        $in = $this->vsIn($users);
        $m = $d['month'];
        $void = self::VS_VOID;
        $ut = $this->vsUndeclaredTable();
        $v = $this->vsVendor();
        $sellers = $this->vsPick('m_sellers', ['name', 'sellers_name']) ?? 'name';
        [$peJoin, $peType] = $this->vsPeType();
        $und = fn (string $ref) => "LEFT JOIN {$ut} D ON D.reference_no = {$ref} AND D.old_declared_month = ? AND D.is_approved = 0 AND D.client_id > 0";
        // VT / IT declared month: from the detail line when it has it (old), else from the invoice
        $sItM = $this->vsPick('t_sales_invoice_detail', ['2307_it_declared_month']) ? 'B.`2307_it_declared_month`' : 'E.`2307_it_declared_month`';
        $pItM = $this->vsPick('t_purchase_expense_detail', ['2307_it_declared_month']) ? 'B.`2307_it_declared_month`' : 'E.`2307_it_declared_month`';
        $pVtM = $this->vsPick('t_purchase_expense_detail', ['2307_vt_declared_month']) ? 'B.`2307_vt_declared_month`' : 'E.`2307_vt_declared_month`';
        $peTypeB = str_replace('A.', 'B.', $peType);
        $peJoinB = str_replace(['A.invoice_no', 'A.record_status'], ['B.invoice_no', 'B.record_status'], $peJoin);

        $sales = fn (string $amount, string $cond, bool $status = true) => ["
            SELECT MAX(B.transaction_date) AS transaction_date, MAX(B.declared_month) AS declared_month, MAX(B.document_no) AS document_no,
                   B.invoice_no AS reference, MAX(C.{$v}) AS fullname, 'Sales / Invoices' AS trans_type, SUM({$amount}) AS amount,
                   COALESCE(MAX(D.id), 0) AS cnt_undeclared, COALESCE(MAX(E.`2307_it`), 0) AS itvt_amount, MAX({$sItM}) AS wh_declared_month
            FROM t_sales_invoice_detail B
            LEFT JOIN m_vendor_customer C ON C.id = B.customer_id
            {$und('B.invoice_no')}
            LEFT JOIN t_sales_invoice_master E ON E.invoice_no = B.invoice_no
            WHERE B.added_by IN ({$in}) AND B.declared_month = ? " . ($status ? "AND B.invoice_status_id <> {$void}" : '') . " AND {$cond}{$this->vsSalesPosted('B')}
            GROUP BY B.invoice_no", [$m, ...$users, $m]];
        $grn = fn (string $col) => ["
            SELECT MAX(A.grn_date) AS transaction_date, MAX(A.declared_month) AS declared_month, MAX(A.ref_data) AS document_no,
                   MAX(A.ref_data) AS reference, MAX(C.{$v}) AS fullname, 'Receive Inventory' AS trans_type, COALESCE(SUM(B.{$col}), 0) AS amount,
                   COALESCE(MAX(D.id), 0) AS cnt_undeclared, 0 AS itvt_amount, '' AS wh_declared_month
            FROM t_grn_master A
            INNER JOIN t_grn_detail B ON B.grn_no = A.grn_no
            LEFT JOIN m_vendor_customer C ON C.id = A.vendor_id
            {$und('B.grn_no')}
            WHERE A.receive_by IN ({$in}) AND A.invoice_status_id <> {$void} AND A.declared_month = ? AND B.{$col} != 0
            GROUP BY A.grn_no", [$m, ...$users, $m]];
        $ap = fn (string $col, ?string $kind, string $label, string $wtax = '2307_it', string $whm = '') => ["
            SELECT MAX(B.transaction_date) AS transaction_date, MAX(B.declared_month) AS declared_month, MAX(B.document_no) AS document_no,
                   B.invoice_no AS reference, MAX(C.{$v}) AS fullname, {$label} AS trans_type, COALESCE(SUM(B.{$col}), 0) AS amount,
                   COALESCE(MAX(D.id), 0) AS cnt_undeclared, COALESCE(MAX(E.`{$wtax}`), 0) AS itvt_amount, MAX(" . ($whm ?: $pItM) . ") AS wh_declared_month
            FROM t_purchase_expense_detail B {$peJoinB}
            LEFT JOIN m_vendor_customer C ON C.id = B.vendor_id
            {$und('B.invoice_no')}
            LEFT JOIN t_purchase_expense_master E ON E.invoice_no = B.invoice_no
            WHERE B.added_by IN ({$in}) AND B.invoice_status_id <> {$void} AND B.declared_month = ? AND B.{$col} != 0
              " . ($kind ? "AND {$peTypeB} = '{$kind}'" : '') . "
            GROUP BY B.invoice_no", [$m, ...$users, $m]];
        $imp = fn (string $amount, string $cond) => ["
            SELECT MAX(B.release_date) AS transaction_date, MAX(B.declared_month) AS declared_month, B.invoice_no AS document_no,
                   MAX(B.import_entry_no) AS reference, MAX(C.{$sellers}) AS fullname, 'Importation' AS trans_type, SUM({$amount}) AS amount,
                   COALESCE(MAX(D.id), 0) AS cnt_undeclared, 0 AS itvt_amount, '' AS wh_declared_month
            FROM t_importation_detail B
            LEFT JOIN m_sellers C ON C.id = B.seller_id
            {$und('B.invoice_no')}
            WHERE B.added_by IN ({$in}) AND B.declared_month = ? AND B.invoice_status_id <> {$void} AND {$cond}
            GROUP BY B.invoice_no", [$m, ...$users, $m]];
        $union = function (array ...$parts) {
            return [implode(' UNION ALL ', array_column($parts, 0)), array_merge(...array_column($parts, 1))];
        };

        $apLabel = "CASE WHEN MAX({$peTypeB}) = 'Purchases' THEN 'Vendor / Invoices' ELSE 'Expenses / Transaction' END";
        [$sql, $bind] = match ($type) {
            'Sales'       => $sales("B.{$col} * B.ex_rate", "B.{$col} != 0"),
            'Purchases'   => $union($grn($col), $ap($col, 'Purchases', "'Vendor / Invoices'")),
            'Expenses'    => $ap($col, 'Expenses', "'Vendor / Invoices'"),
            'OutputVAT'   => $sales('B.input_vat', 'B.input_vat != 0', false),
            'InputVAT'    => $union($grn('input_vat'), $ap('input_vat', null, $apLabel, '2307_vt', $pVtM),
                                $imp('B.amount_of_vat * B.ex_rate', 'B.amount_of_vat != 0')),
            'Importation' => $code === 'VATExempt'
                ? $imp('B.exempt_imports * B.ex_rate', 'B.exempt_imports != 0')
                : $imp('B.taxable_imports * B.ex_rate', 'B.taxable_imports != 0'),
        };
        $rows = DB::select($sql, $bind);
        return response()->json(array_map(fn ($r) => [
            'transaction_date'  => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : null,
            'declared_month'    => $r->declared_month,
            'document_no'       => $r->document_no,
            'reference'         => $r->reference,
            'fullname'          => $r->fullname,
            'trans_type'        => $r->trans_type,
            'amount'            => (float) $r->amount,
            'undeclared'        => (int) $r->cnt_undeclared > 0,
            'itvt_amount'       => (float) $r->itvt_amount,
            'wh_declared_month' => $r->wh_declared_month ? substr((string) $r->wh_declared_month, 0, 7) : '',
        ], $rows));
    }

    /* ---------------- undeclare / undo / approve ---------------- */

    public function vatClientsUndeclare(Request $request)
    {
        $this->vsDaCannot($request);
        $d = $request->validate([
            'reference_no'          => ['required', 'string', 'max:100'],
            'old_declared_month'    => ['required', 'date_format:Y-m'],
            'new_declared_month'    => ['required', 'date_format:Y-m'],
            'new_wh_declared_month' => ['nullable', 'date_format:Y-m'],
            'client_id'             => ['required', 'integer', 'min:1'],
            'caid'                  => ['nullable', 'integer'],
        ], ['new_declared_month.required' => 'Declared Month is required']);
        $lock = $this->vsLockTable();
        $lockCa = $lock ? $this->vsPick($lock, ['ca_id', 'caid']) : null;
        if ($lock && $lockCa && !empty($d['caid'])
            && DB::table($lock)->where($lockCa, (int) $d['caid'])->where('declared_month', 'like', $d['new_declared_month'] . '%')->exists()) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }
        $t = $this->vsUndeclaredTable();
        // already waiting → no second copy (that doubled the printed list)
        $exists = DB::table($t)->where('reference_no', $d['reference_no'])->where('old_declared_month', $d['old_declared_month'])
            ->where('is_approved', 0)->where('report', 'EBIR')->value('id');
        if ($exists) return response()->json(['message' => 'This document is already undeclared.'], 422);
 
        $now = now();
        $id = DB::table($t)->insertGetId($this->vsOnly($t, [
            'report'                => 'EBIR',
            'reference_no'          => $d['reference_no'],
            'old_declared_month'    => $d['old_declared_month'],
            'new_declared_month'    => $d['new_declared_month'],
            'new_wh_declared_month' => $d['new_wh_declared_month'] ?? null,
            'client_id'             => (int) $d['client_id'],
            'is_approved'           => 0,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]) + $this->vsAudit(['undeclared_by' => (int) $request->user()->id]));
        return response()->json(['id' => $id]);
    }

    public function vatClientsUndo(Request $request)
    {
        $this->vsDaCannot($request);
        $d = $request->validate([
            'reference_no'       => ['required', 'string', 'max:100'],
            'old_declared_month' => ['required', 'date_format:Y-m'],
        ]);
        // only the one still waiting — approved rows are the history, never deleted
        $n = DB::table($this->vsUndeclaredTable())->where('reference_no', $d['reference_no'])
            ->where('old_declared_month', $d['old_declared_month'])->where('is_approved', 0)->delete();
        return response()->json(['deleted' => $n]);
    }

    /** old get_next_NO('TicketNo', 'TCKT', …): TCKT + yymd-his + 5 random digits */
    private function vsTicketNo(): string
    {
        return 'TCKT' . date('y') . date('md-his') . random_int(10000, 99999);
    }

    public function vatClientsApprove(Request $request)
    {
        $this->vsDaCannot($request);
        $d = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'from'      => ['required', 'date_format:Y-m'],
            'to'        => ['required', 'date_format:Y-m'],
            'user_ids'  => ['nullable', 'string'],      // for the before / after snapshot
            'caid'      => ['nullable', 'integer'],
        ]);
        $t = $this->vsUndeclaredTable();
        $list = DB::table($t)->where('client_id', (int) $d['client_id'])->whereBetween('old_declared_month', [$d['from'], $d['to']])
            ->where('is_approved', 0)->where('report', 'EBIR')->orderBy('id')->get();
        if ($list->isEmpty()) return response()->json(['message' => 'No adjustment in this transaction'], 422);

        // E-BIR table BEFORE (Original) and AFTER (Adjusted) this approval → History shows old vs new
        $snap = $this->vsApprovalSnapshot($request, $d, $list);

        $ticket = $this->vsTable(['t_request_ticket']);
        $tStatus = $ticket ? $this->vsPick($ticket, ['is_status', 'status']) : null;
        $history = $this->vsTable(['t_history', 't_ticket_history', 'tbl2_history']);
        $hData = $history ? $this->vsPick($history, ['table_data', 'data']) : null;
        $hRef = $history ? $this->vsPick($history, ['reference_no', 'reference']) : null;
        $now = now();
        $me = (int) $request->user()->id;
        $batch = 'APV' . $now->format('ymdHis') . random_int(100, 999);
        $remarks = 'Undeclared from VAT Summary';

        // the declared months on the invoice and its lines; a VT / IT month only changes when it was set
        $moveSql = function (string $table, string $refCol): string {
            $set = ['declared_month = ?'];
            foreach (['2307_it_declared_month', '2307_vt_declared_month'] as $c) {
                if ($this->vsPick($table, [$c])) $set[] = "`{$c}` = CASE WHEN `{$c}` IS NOT NULL AND `{$c}` <> '' THEN ? ELSE `{$c}` END";
            }
            if ($this->vsPick($table, ['updated_at'])) $set[] = 'updated_at = ?';
            return "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$refCol} = ?";
        };
        $moveBind = function (string $table, $row) use ($now): array {
            $b = [$row->new_declared_month];
            foreach (['2307_it_declared_month', '2307_vt_declared_month'] as $c) if ($this->vsPick($table, [$c])) $b[] = $row->new_wh_declared_month;
            if ($this->vsPick($table, ['updated_at'])) $b[] = $now;
            $b[] = $row->reference_no;
            return $b;
        };

        DB::transaction(function () use ($list, $t, $ticket, $tStatus, $history, $hData, $hRef, $now, $me, $batch, $remarks, $moveSql, $moveBind, $snap, $d) {
            foreach ($list as $row) {
                $ref = $row->reference_no;
                // a ticket + a copy of the invoice before the change (old tbl2_request_ticket / tbl2_history)
                foreach ([['t_sales_invoice_master', 't_sales_invoice_detail'], ['t_purchase_expense_master', 't_purchase_expense_detail']] as [$mt, $dt]) {
                    $master = DB::table($mt)->where('invoice_no', $ref)->first();
                    if (!$master) continue;
                    $no = $this->vsTicketNo();
                    $module = $mt === 't_sales_invoice_master' ? 'Sales/Invoices'
                        : (strcasecmp((string) ($master->trans_type ?? ''), 'Purchases') === 0 ? 'Vendors / Invoices' : 'Expenses / Transaction');
                    if ($ticket) {
                        DB::table($ticket)->insert($this->vsOnly($ticket, [
                            'ticket_no' => $no, 'reference_no' => $ref, 'remarks' => $remarks, 'module' => $module, 'action' => 'EDIT',
                            'request_by' => $master->added_by ?? null, 'created_at' => $now, 'updated_at' => $now,
                        ] + ($tStatus ? [$tStatus => 1] : [])));
                    }
                    if ($history && $hData) {
                        DB::table($history)->insert($this->vsOnly($history, [
                            $hData => json_encode(['master' => $master, 'details' => DB::table($dt)->where('invoice_no', $ref)->get()]),
                            ($hRef ?? 'reference_no') => $ref, 'ticket_no' => $no, 'created_at' => $now, 'updated_at' => $now,
                        ]));
                    }
                }
                foreach ([['t_sales_invoice_master', 'invoice_no'], ['t_sales_invoice_detail', 'invoice_no'],
                             ['t_purchase_expense_master', 'invoice_no'], ['t_purchase_expense_detail', 'invoice_no']] as [$table, $col]) {
                    DB::update($moveSql($table, $col), $moveBind($table, $row));
                }
                DB::table($t)->where('id', $row->id)->update($this->vsOnly($t, ['is_approved' => 1, 'updated_at' => $now])
                    + $this->vsAudit(['approved_by' => $me, 'approved_at' => $now, 'approval_no' => $batch]));
            }
            if ($snap) {
                DB::table('t_undeclared_approval')->insert([
                    'approval_no' => $batch, 'client_id' => (int) $d['client_id'], 'period_from' => $d['from'], 'period_to' => $snap['to'],
                    'old_data' => json_encode($snap['old']), 'new_data' => json_encode($snap['new']),
                    'approved_by' => $me, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        return response()->json(['approved' => $list->count(), 'approval_no' => $batch]);
    }

    /* ---------------- print: undeclared list + client info ---------------- */

    public function vatClientsUndeclared(Request $request)
    {
        $d = $request->validate([
            'client_id'  => ['required', 'integer'],
            'client_num' => ['required', 'string', 'max:50'],
            'from'       => ['required', 'date_format:Y-m'],
            'to'         => ['required', 'date_format:Y-m'],
            'type'       => ['required', 'in:sales,purchases,expenses'],
            'approval_no' => ['nullable', 'string', 'max:30'],   // History → print the documents of one approval
        ]);
        $ut = $this->vsUndeclaredTable();
        $ca = $this->vsUserCa();
        $v = $this->vsVendor();
        $vTin = $this->vsPick('m_vendor_customer', ['tin']);
        $vBr = $this->vsPick('m_vendor_customer', ['branch_code']);
        $acName = $this->vsPick('m_charts_of_account', ['account_name', 'name']) ?? 'account_name';
        $acBy = $this->vsPick('m_charts_of_account', ['added_by']);
        $nonVat = fn (string $t) => $this->vsPick($t, ['non_vat']) ? 'A.non_vat' : '0';
        // one row per waiting document (DISTINCT: a document undeclared twice prints once)
        // or, with approval_no: the documents of that approval (already approved)
        $byApproval = !empty($d['approval_no']) && Schema::hasColumn($ut, 'approval_no');
        $waiting = $byApproval
            ? "(SELECT DISTINCT reference_no FROM {$ut} WHERE client_id = ? AND approval_no = ? AND is_approved = 1 AND report = 'EBIR') B"
            : "(SELECT DISTINCT reference_no FROM {$ut}
                     WHERE client_id = ? AND old_declared_month BETWEEN ? AND ? AND is_approved = 0 AND report = 'EBIR') B";
        $common = fn (string $detail) => "
            LEFT JOIN m_vendor_customer C ON C.id = A." . ($detail === 't_sales_invoice_detail' ? 'customer_id' : 'vendor_id') . "
            LEFT JOIN m_charts_of_account D ON D.account_code = A.account_code" . ($acBy ? ' AND D.added_by = A.added_by' : '') . "
            INNER JOIN m_user_client E ON E.id = A.added_by
            INNER JOIN m_client_account F ON F.id = E.{$ca}
            WHERE 1 = 1";
        $head = "A.id, " . ($vBr ? "C.{$vBr}" : "''") . " AS branch_code, F.branch_code AS branch_code2,
                 DATE_FORMAT(A.transaction_date, '%d-%b-%y') AS po_date, A.invoice_no, A.declared_month, " . ($vTin ? "C.{$vTin}" : "''") . " AS tin,
                 C.{$v} AS vendor_name, COALESCE(A.document_no, '') AS document_no, A.account_code, D.{$acName} AS account_name";
        $bind = $byApproval ? [(int) $d['client_id'], $d['approval_no']] : [(int) $d['client_id'], $d['from'], $d['to']];
        if ($d['type'] === 'sales') {
            $sql = "SELECT {$head}, A.amount AS total, A.currency, A.ex_rate, A.created_at AS date_added, A.amount * A.ex_rate AS total2,
                           A.net_of_vat * A.ex_rate AS net_of_vat, A.input_vat * A.ex_rate AS input_vat, A.vat_exempt * A.ex_rate AS vat_exempt,
                           A.zero_rated * A.ex_rate AS zero_rated, {$nonVat('t_sales_invoice_detail')} * A.ex_rate AS non_vat
                    FROM {$waiting} INNER JOIN t_sales_invoice_detail A ON A.invoice_no = B.reference_no {$common('t_sales_invoice_detail')}{$this->vsSalesPosted('A')}
                    ORDER BY A.transaction_date, A.invoice_no, A.id";
        } else {
            [$peJoin, $peType] = $this->vsPeType();
            $total = $this->vsPeTotal();
            $sql = "SELECT {$head}, {$total} AS total, 'PHP' AS currency, 1 AS ex_rate, A.created_at AS date_added, {$total} AS total2,
                           A.net_of_vat, A.input_vat, A.vat_exempt, A.zero_rated, {$nonVat('t_purchase_expense_detail')} AS non_vat
                    FROM {$waiting} INNER JOIN t_purchase_expense_detail A ON A.invoice_no = B.reference_no {$peJoin} {$common('t_purchase_expense_detail')}
                      AND {$peType} = ?
                    ORDER BY A.transaction_date, A.invoice_no, A.id";
            $bind[] = $d['type'] === 'purchases' ? 'Purchases' : 'Expenses';
        }
        $rows = DB::select($sql, $bind);
        return response()->json(['client' => $this->vsClientInfo($d['client_num']), 'rows' => $rows]);
    }

    /** old doGetCAInformation (qry_client_account_masterlist, MAIN account first) */
    private function vsClientInfo(string $clientNum): ?array
    {
        $tin = $this->vsPick('m_client_account', ['tin', 'tin_no']);
        $vTable = $this->vsTable(['m_client_valid_id', 'm_client_valid_ids']);
        $tinSql = $tin ? "B.{$tin}"
            : ($vTable ? "(SELECT V.id_num FROM {$vTable} V WHERE V.client_num = A.client_num AND V.institution = 'BIR' ORDER BY V.id DESC LIMIT 1)" : "''");
        $street = $this->vsPick('m_client_account', ['street', 'address']);
        $r = DB::selectOne("
            SELECT TRIM(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name)) AS client_name, B.trade_name, A.client_num, B.branch_code,
                   " . ($street ? "B.{$street}" : "''") . " AS street, {$tinSql} AS tin
            FROM m_client A INNER JOIN m_client_account B ON B.client_num = A.client_num
            WHERE A.client_num = ?
            ORDER BY (B.branch_type = 'MAIN') DESC, B.id
            LIMIT 1", [$clientNum]);
        return $r ? (array) $r : null;
    }


    /* =====================================================================
       INCOME SUMMARY  (old pages/Reports/Income_Summary_Report.php)
       Paste INSIDE ReportController, under the VAT Summary block — it reuses its helpers
       (vsPick, vsTable, vsIds, vsIn, vsMonths, vsUserCa, vsStatusLabel, vsPeType, vsPeTotal, vsSalesPosted, vsMoney, vsClientUsers).
       Posted only, like the VAT Summary: sales trans_type = 'posted', purchases / expenses record_status = 'posted'.

       GET /reports/income-clients?from=&to=&status_id=&da_id=        main list   (old doGetVATSummaryReport in Income_Summary_Report.php)
       GET /reports/income-clients/branches?from=&to=&client_num=     branch rows (old doGetVATSummaryReport_Detail)
       GET /reports/income-clients/statement?user_ids=&from=&to=      Income Statement popup (old doGetIncomeStatement)
       Status / Data analyst lists and the remarks use the VAT Summary routes (/reports/vat-clients/lookups, /remarks).
       ===================================================================== */

    /** sales / COGS (accounts 5…) / expenses (accounts 6…) per login, one query (old: 3 queries + employee joins) */
    private function isAmounts(array $users, string $from, string $to, int $daId, bool $roundPerLine): array
    {
        if (!$users) return [];
        $in = $this->vsIn($users);
        $void = self::VS_VOID;
        [$peJoin] = $this->vsPeType();
        $daSql = $daId > 0 ? ' AND A.assigned_daid = ?' : '';
        $daBind = $daId > 0 ? [$daId] : [];
        $r = fn (string $x) => $roundPerLine ? "SUM(ROUND(COALESCE({$x}, 0), 2))" : "ROUND(COALESCE(SUM({$x}), 0), 2)";
        $all = 'A.net_of_vat + A.vat_exempt + A.non_vat + A.zero_rated';
        $rows = DB::select("
            SELECT A.added_by AS uid, 'S' AS k, {$r('A.net_of_vat')} AS nv, {$r('A.vat_exempt')} AS ve, {$r('A.zero_rated')} AS zr
            FROM t_sales_invoice_detail A
            WHERE A.added_by IN ({$in}) AND A.declared_month BETWEEN ? AND ?
              AND A.invoice_status_id <> {$void} AND A.amount != 0{$this->vsSalesPosted('A')} {$daSql}
            GROUP BY A.added_by
            UNION ALL
            SELECT A.added_by, CASE WHEN A.account_code LIKE '5%' THEN 'C' ELSE 'E' END,
                   ROUND(COALESCE(SUM({$all}), 0), 2), 0, 0
            FROM t_purchase_expense_detail A {$peJoin}
            WHERE A.added_by IN ({$in}) AND A.declared_month BETWEEN ? AND ?
              AND A.invoice_status_id <> {$void} AND {$this->vsPeTotal()} != 0
              AND (A.account_code LIKE '5%' OR A.account_code LIKE '6%') {$daSql}
            GROUP BY A.added_by, CASE WHEN A.account_code LIKE '5%' THEN 'C' ELSE 'E' END",
            [...$users, $from, $to, ...$daBind, ...$users, $from, $to, ...$daBind]);
        $by = [];
        foreach ($rows as $x) $by[(int) $x->uid][$x->k] = $x;
        return $by;
    }

    /** one row of the list (old formulas: gross = total sales − COGS · rate = gross ÷ VATable sales × 100) */
    private function isRow(array $by, array $uids): array
    {
        $nv = $ve = $zr = $cogs = $exp = 0.0;
        foreach ($uids as $u) {
            $x = $by[$u] ?? [];
            if (isset($x['S'])) { $nv += (float) $x['S']->nv; $ve += (float) $x['S']->ve; $zr += (float) $x['S']->zr; }
            if (isset($x['C'])) $cogs += (float) $x['C']->nv;
            if (isset($x['E'])) $exp += (float) $x['E']->nv;
        }
        $sales = $nv + $ve + $zr;
        $gross = $sales - $cogs;
        return [
            'total_sales'       => $this->vsMoney($sales),
            'cogs'              => $this->vsMoney($cogs),
            'gross_amount'      => $this->vsMoney($gross),
            'gross_profit_rate' => $nv > 0 ? round($gross / $nv * 100, 2) : 0.0,
            'expenses'          => $this->vsMoney($exp),
            'income_subject'    => $this->vsMoney($gross - $exp),
        ];
    }

    public function incomeClientsSummary(Request $request)
    {
        $d = $request->validate([
            'from'      => ['required', 'date_format:Y-m'],
            'to'        => ['required', 'date_format:Y-m'],
            'status_id' => ['nullable', 'integer'],
            'da_id'     => ['nullable', 'integer'],
        ]);
        $statusId = (int) ($d['status_id'] ?? 0);
        $daId = (int) ($d['da_id'] ?? 0);
        if ($mine = $this->reportDaOnly($request)) $daId = $mine;
        $ca = $this->vsUserCa();
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_client', ['active', 'is_active']);

        // every active client (no VAT filter here, like the old page)
        $where = ['1 = 1'];
        $bind = [];
        if ($active) $where[] = "A.{$active} = 1";
        if ($statusId > 0) { $where[] = 'A.status_id = ?'; $bind[] = $statusId; }
        if ($daId > 0) { $where[] = 'B.assigned_daid = ?'; $bind[] = $daId; }
        $clients = DB::select("
            SELECT A.client_num, MIN(A.id) AS client_id, MIN(A.last_name) AS sort_name,
                   MAX(TRIM(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name))) AS client_name,
                   " . ($sl ? "MAX(S.{$sl})" : "''") . " AS status,
                   GROUP_CONCAT(C.id ORDER BY C.id) AS user_ids, COUNT(C.id) AS count_acc
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($sl ? 'LEFT JOIN m_client_account_status S ON S.id = A.status_id' : '') . '
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY A.client_num
            ORDER BY sort_name, A.client_num', $bind);
        if (!$clients) return response()->json(['rows' => []]);

        $users = [];
        foreach ($clients as $c) foreach ($this->vsIds($c->user_ids) as $u) $users[$u] = true;
        $by = $this->isAmounts(array_keys($users), $d['from'], $d['to'], $daId, false);

        $out = [];
        foreach ($clients as $c) {
            $uids = $this->vsIds($c->user_ids);
            $out[] = [
                'client_id'  => (int) $c->client_id,
                'client_num' => $c->client_num,
                'client_name' => $c->client_name,
                'user_ids'   => implode(',', $uids),
                'count_acc'  => (int) $c->count_acc,
                'status'     => (string) $c->status,
            ] + $this->isRow($by, $uids);
        }
        return response()->json(['rows' => $out]);
    }

    public function incomeClientsBranches(Request $request)
    {
        $d = $request->validate([
            'from'       => ['required', 'date_format:Y-m'],
            'to'         => ['required', 'date_format:Y-m'],
            'client_num' => ['required', 'string', 'max:50'],
        ]);
        $ca = $this->vsUserCa();
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_client', ['active', 'is_active']);
        $accounts = DB::select("
            SELECT A.id AS client_id, A.client_num, C.id AS uid,
                   TRIM(CONCAT_WS(' : ', B.branch_code, B.trade_name)) AS client_name,
                   " . ($sl ? "S.{$sl}" : "''") . " AS status
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($sl ? 'LEFT JOIN m_client_account_status S ON S.id = B.status_id' : '') . '
            WHERE A.client_num = ? ' . ($active ? "AND A.{$active} = 1" : '') . '
            ORDER BY B.branch_code, B.trade_name', [$d['client_num']]);
        $by = $this->isAmounts(array_map(fn ($a) => (int) $a->uid, $accounts), $d['from'], $d['to'], 0, true);
        return response()->json(['rows' => array_map(fn ($a) => [
            'client_id'   => (int) $a->client_id,
            'client_num'  => $a->client_num,
            'client_name' => $a->client_name,
            'user_ids'    => (string) $a->uid,
            'count_acc'   => 1,
            'status'      => (string) $a->status,
        ] + $this->isRow($by, [(int) $a->uid]), $accounts)]);
    }

    /* ---------------- Income Statement popup ---------------- */

    public function incomeClientsStatement(Request $request)
    {
        $d = $request->validate([
            'user_ids' => ['required', 'string'],
            'from'     => ['required', 'date_format:Y-m'],
            'to'       => ['required', 'date_format:Y-m'],
        ]);
        abort_if($d['from'] > $d['to'], 422, 'From must be on or before To.');
        $users = $this->vsClientUsers($this->vsIds($d['user_ids']));
        abort_unless($users, 422, 'This client has no login.');
        $from = $d['from'];
        $to = $d['to'];
        $months = $this->vsMonths($from, $to);
        $zero = array_fill_keys($months, 0.0);
        $in = $this->vsIn($users);
        $b = [...$users, $from, $to];
        $void = self::VS_VOID;
        [$peJoin, $peType] = $this->vsPeType();
        $all = '(B.net_of_vat + B.vat_exempt + B.non_vat + B.zero_rated)';
        $peJoinB = str_replace(['A.invoice_no', 'A.record_status'], ['B.invoice_no', 'B.record_status'], $peJoin);
        $peTypeB = str_replace('A.', 'B.', $peType);
        $acName = $this->vsPick('m_charts_of_account', ['account_name', 'name']) ?? 'account_name';
        $acBy = $this->vsPick('m_charts_of_account', ['added_by']);
        // old: INNER JOIN tbl2_charts_account C ON C.AccountCode = B.AccountCode AND C.AddedBy = B.AddedBy
        $chart = fn (string $by) => "INNER JOIN m_charts_of_account C ON C.account_code = B.account_code" . ($acBy ? " AND C.added_by = B.{$by}" : '');

        // month buckets: values per month + total (the total also counts rows whose month isn't a plain YYYY-MM, like the old Total)
        $line = fn () => ['values' => $zero, 'total' => 0.0];
        $put = function (array &$cell, $m, $v) {
            $v = (float) $v;
            $cell['total'] += $v;
            if (array_key_exists((string) $m, $cell['values'])) $cell['values'][$m] += $v;
        };
        $accounts = function (array $rows) use ($line, $put) {
            $out = [];
            foreach ($rows as $r) {
                $out[$r->code] ??= ['code' => $r->code, 'name' => $r->name] + $line();
                $put($out[$r->code], $r->m, $r->v);
            }
            ksort($out, SORT_STRING);
            return array_values($out);
        };
        $sum = function (array $lines) use ($line, $months) {
            $t = $line();
            foreach ($lines as $l) {
                foreach ($months as $m) $t['values'][$m] += $l['values'][$m];
                $t['total'] += $l['total'];
            }
            return $t;
        };
        $calc = function (callable $f) use ($months) {
            $t = ['values' => [], 'total' => 0.0];
            foreach ($months as $m) { $t['values'][$m] = $f($m); $t['total'] += $t['values'][$m]; }
            return $t;
        };

        /* SALES (accounts 4…, × exchange rate) */
        $sales = $accounts(DB::select("
            SELECT B.account_code AS code, MAX(C.{$acName}) AS name, B.declared_month AS m, SUM({$all} * B.ex_rate) AS v
            FROM t_sales_invoice_detail B {$chart('added_by')}
            WHERE B.added_by IN ({$in}) AND B.declared_month BETWEEN ? AND ?
              AND B.invoice_status_id <> {$void} AND B.account_code LIKE '4%'{$this->vsSalesPosted('B')}
            GROUP BY B.account_code, B.declared_month", $b));
        $totalSales = $sum($sales);

        /* Merchandise Inventory, Beginning (old tbl2_beg_balances_product.TotalCost) */
        $beg = $line();
        $bt = $this->vsTable(['t_beg_balance_product', 't_beg_balances_product', 'tbl2_beg_balances_product']);
        $bCost = $bt ? $this->vsPick($bt, ['total_cost', 'total', 'amount']) : null;
        $bMonth = $bt ? $this->vsPick($bt, ['declared_month']) : null;
        if ($bt && $bCost && $bMonth) {
            foreach (DB::select("SELECT {$bMonth} AS m, SUM({$bCost}) AS v FROM {$bt} WHERE added_by IN ({$in}) AND {$bMonth} BETWEEN ? AND ? GROUP BY {$bMonth}", $b) as $r) {
                $put($beg, $r->m, $r->v);
            }
        }

        /* COST OF SALES (accounts 5…): receive inventory + AP */
        $grnTotal = $this->vsPick('t_grn_detail', ['total']) ? 'B.total' : 'B.net_of_vat';
        $cogsParts = [];
        if ($this->vsPick('t_grn_detail', ['account_code'])) {
            $cogsParts[] = "
                SELECT B.account_code AS code, MAX(C.{$acName}) AS name, B.declared_month AS m, SUM({$all}) AS v
                FROM t_grn_detail B
                INNER JOIN t_grn_master GM ON GM.grn_no = B.grn_no AND GM.invoice_status_id <> {$void}
                {$chart('receive_by')}
                WHERE B.receive_by IN ({$in}) AND B.declared_month BETWEEN ? AND ?
                  AND B.account_code LIKE '5%' AND {$grnTotal} != 0
                GROUP BY B.account_code, B.declared_month";
        }
        $cogsParts[] = "
            SELECT B.account_code AS code, MAX(C.{$acName}) AS name, B.declared_month AS m, SUM({$all}) AS v
            FROM t_purchase_expense_detail B {$peJoinB} {$chart('added_by')}
            WHERE B.added_by IN ({$in}) AND B.invoice_status_id <> {$void} AND B.declared_month BETWEEN ? AND ?
              AND B.account_code LIKE '5%'
            GROUP BY B.account_code, B.declared_month";
        $cogs = $accounts(DB::select(implode(' UNION ALL ', $cogsParts), array_merge(...array_fill(0, count($cogsParts), $b))));
        $available = $sum($cogs);
        // old math: COGS = beginning + purchases − ending, ending = beginning + purchases − COGS
        $tcogs = $calc(fn ($m) => $beg['values'][$m] + $available['values'][$m]);
        $ending = $calc(fn ($m) => $beg['values'][$m] + $available['values'][$m] - $tcogs['values'][$m]);
        $totalCogs = $calc(fn ($m) => $beg['values'][$m] + $available['values'][$m] - $ending['values'][$m]);
        $gross = $calc(fn ($m) => $totalSales['values'][$m] - $tcogs['values'][$m]);
        $rate = $calc(fn ($m) => ($gross['values'][$m] != 0 && $totalSales['values'][$m] != 0) ? $gross['values'][$m] / $totalSales['values'][$m] : 0.0);

        /* EXPENSES (accounts 6… and 8…) */
        $expenses = $accounts(DB::select("
            SELECT B.account_code AS code, MAX(C.{$acName}) AS name, B.declared_month AS m, SUM({$all}) AS v
            FROM t_purchase_expense_detail B {$peJoinB} {$chart('added_by')}
            WHERE B.added_by IN ({$in}) AND B.invoice_status_id <> {$void} AND B.declared_month BETWEEN ? AND ?
              AND (B.account_code LIKE '6%' OR B.account_code LIKE '8%')
            GROUP BY B.account_code, B.declared_month", $b));
        $totalExp = $sum($expenses);
        $income = $calc(fn ($m) => $gross['values'][$m] - $totalExp['values'][$m]);

        /* 2550-M / 2550-Q */
        $output = $line();
        foreach (DB::select("
            SELECT B.declared_month AS m, SUM(B.input_vat * B.ex_rate) AS v FROM t_sales_invoice_detail B
            WHERE B.added_by IN ({$in}) AND B.declared_month BETWEEN ? AND ? AND B.invoice_status_id <> {$void}{$this->vsSalesPosted('B')}
            GROUP BY B.declared_month", $b) as $r) $put($output, $r->m, $r->v);

        $inPurch = $line();
        $inExp = $line();
        foreach (DB::select("
            SELECT B.declared_month AS m, SUM(B.input_vat) AS v FROM t_grn_detail B
            INNER JOIN t_grn_master GM ON GM.grn_no = B.grn_no AND GM.invoice_status_id <> {$void}
            WHERE B.receive_by IN ({$in}) AND B.declared_month BETWEEN ? AND ? AND B.input_vat != 0
            GROUP BY B.declared_month", $b) as $r) $put($inPurch, $r->m, $r->v);
        foreach (DB::select("
            SELECT B.declared_month AS m, {$peTypeB} AS t, SUM(B.input_vat) AS v
            FROM t_purchase_expense_detail B {$peJoinB}
            WHERE B.added_by IN ({$in}) AND B.invoice_status_id <> {$void} AND B.declared_month BETWEEN ? AND ?
              AND B.input_vat != 0 AND {$peTypeB} IN ('Purchases', 'Expenses')
              AND ({$peTypeB} = 'Purchases' OR B.account_code IS NOT NULL)
            GROUP BY B.declared_month, {$peTypeB}", $b) as $r) {
            if (strcasecmp((string) $r->t, 'Purchases') === 0) $put($inPurch, $r->m, $r->v);
            else $put($inExp, $r->m, $r->v);
        }
        $inTotal = $calc(fn ($m) => $inPurch['values'][$m] + $inExp['values'][$m]);
        // Output VAT − total input VAT (the old page took Total Expenses here by mistake)
        $payable = $calc(fn ($m) => $output['values'][$m] - $inTotal['values'][$m]);

        /* 0619-E / 1601-EQ: EWT (2307 IT) by its own declared month */
        $ewtP = $line();
        $ewtE = $line();
        $pmType = $this->vsPick('t_purchase_expense_master', ['trans_type', 'payment_type']) ?? 'trans_type';
        $pmPosted = $this->vsPick('t_purchase_expense_master', ['record_status']) ? " AND A.record_status = 'posted'" : '';
        foreach (DB::select("
            SELECT A.`2307_it_declared_month` AS m, SUM(A.`2307_it`) AS v FROM t_grn_master A
            WHERE A.receive_by IN ({$in}) AND A.invoice_status_id <> {$void}
              AND A.`2307_it_declared_month` BETWEEN ? AND ? AND A.`2307_it` != 0
            GROUP BY A.`2307_it_declared_month`", $b) as $r) $put($ewtP, $r->m, $r->v);
        foreach (DB::select("
            SELECT A.`2307_it_declared_month` AS m, A.{$pmType} AS t, SUM(A.`2307_it`) AS v FROM t_purchase_expense_master A
            WHERE A.added_by IN ({$in}) AND A.invoice_status_id <> {$void}{$pmPosted}
              AND A.`2307_it_declared_month` BETWEEN ? AND ?
              AND ((A.{$pmType} = 'Purchases' AND A.`2307_it` != 0) OR (A.{$pmType} = 'Expenses' AND A.`2307_it` > 0))
            GROUP BY A.`2307_it_declared_month`, A.{$pmType}", $b) as $r) {
            if (strcasecmp((string) $r->t, 'Purchases') === 0) $put($ewtP, $r->m, $r->v);
            else $put($ewtE, $r->m, $r->v);
        }
        $ewt = $calc(fn ($m) => $ewtP['values'][$m] + $ewtE['values'][$m]);

        return response()->json([
            'months'            => $months,
            'sales'             => $sales,
            'total_sales'       => $totalSales,
            'beginning'         => $beg,
            'cogs'              => $cogs,
            'cogs_available'    => $available,
            'ending'            => $ending,
            'total_cogs'        => $totalCogs,
            'gross_amount'      => $gross,
            'gross_profit_rate' => $rate,
            'expenses'          => $expenses,
            'total_expenses'    => $totalExp,
            'income_subject'    => $income,
            'output_vat'        => $output,
            'input_vat_purchases' => $inPurch,
            'input_vat_expenses'  => $inExp,
            'total_input_vat'   => $inTotal,
            'vat_payable'       => $payable,
            'ewt_purchases'     => $ewtP,
            'ewt_expenses'      => $ewtE,
            'total_ewt'         => $ewt,
        ]);
    }

    /* =====================================================================
       PERCENTAGE TAX SUMMARY (2551-Q)  (old pages/Reports/Percentage_Tax_Summary.php)
       Paste INSIDE ReportController, under the VAT Summary block — it reuses its helpers
       (vsPick, vsIds, vsIn, vsUserCa, vsStatusLabel, vsSalesPosted, vsMoney).
       Non-VAT clients (m_client_account.tax_registration = 'NonVAT'), posted sales only.

       GET /reports/pt-clients?from=&to=&status_id=&da_id=        main list   (old doGetPercentageTaxSummary)
       GET /reports/pt-clients/branches?from=&to=&client_num=     branch rows (old doGetPercentageTaxSummary_Detail)
       Status / Data analyst lists: /reports/vat-clients/lookups (VAT Summary).

       Sales      = SUM(invoice sub_total) in the period          PT Due = 3% of Sales
       2307 (PT)  = SUM(2307_vt) of those invoices
       Tax Paid Prev. Filed (only when "To" is the 3rd month of a quarter) =
                    for each of the quarter's first 2 months: 3% of that month's sales − 2307 VT declared that month
       Tax Paid   = PT Due − 2307 (PT) − Tax Paid Prev. Filed
       ===================================================================== */

    private const PT_RATE = 0.03;

    /** per login: current sales / 2307 + the quarter's earlier months (one query; old: 2 queries + employee joins) */
    private function ptAmounts(array $users, string $from, string $to, int $daId): array
    {
        if (!$users) return [];
        $in = $this->vsIn($users);
        $void = self::VS_VOID;
        $posted = $this->vsSalesPosted('A', 't_sales_invoice_master');
        $daSql = $daId > 0 ? ' AND A.assigned_daid = ?' : '';
        $daBind = $daId > 0 ? [$daId] : [];
        $aud = $this->vsPick('t_sales_invoice_master', ['assigned_auid', 'assigned_auditor_id']);

        $sql = "
            SELECT A.added_by AS uid, 'S' AS k, ROUND(COALESCE(SUM(A.sub_total), 0), 2) AS a, ROUND(COALESCE(SUM(A.`2307_vt`), 0), 2) AS b,
                   GROUP_CONCAT(DISTINCT A.assigned_daid) AS da, " . ($aud ? "GROUP_CONCAT(DISTINCT A.{$aud})" : 'NULL') . " AS au
            FROM t_sales_invoice_master A
            WHERE A.added_by IN ({$in}) AND A.declared_month BETWEEN ? AND ?
              AND A.invoice_status_id <> {$void} AND A.sub_total != 0{$posted}{$daSql}
            GROUP BY A.added_by";
        $bind = [...$users, $from, $to, ...$daBind];

        // the quarter's first 2 months (old: only when "To" is Mar / Jun / Sep / Dec)
        $m = (int) substr($to, 5, 2);
        if (in_array($m, [3, 6, 9, 12], true)) {
            $y = substr($to, 0, 4);
            $prev = [sprintf('%s-%02d', $y, $m - 2), sprintf('%s-%02d', $y, $m - 1)];
            $sql .= "
            UNION ALL
            SELECT A.added_by, 'P', ROUND(COALESCE(SUM(A.sub_total), 0) * " . self::PT_RATE . ", 2), 0, NULL, NULL
            FROM t_sales_invoice_master A
            WHERE A.added_by IN ({$in}) AND A.declared_month IN (?, ?)
              AND A.invoice_status_id <> {$void} AND A.sub_total != 0{$posted}{$daSql}
            GROUP BY A.added_by, A.declared_month
            UNION ALL
            SELECT A.added_by, 'V', ROUND(COALESCE(SUM(A.`2307_vt`), 0), 2), 0, NULL, NULL
            FROM t_sales_invoice_master A
            WHERE A.added_by IN ({$in}) AND A.`2307_vt_declared_month` IN (?, ?)
              AND A.invoice_status_id <> {$void} AND A.`2307_vt` != 0{$posted}{$daSql}
            GROUP BY A.added_by, A.`2307_vt_declared_month`";
            $bind = [...$bind, ...$users, ...$prev, ...$daBind, ...$users, ...$prev, ...$daBind];
        }

        $by = [];
        foreach (DB::select($sql, $bind) as $r) {
            $u = (int) $r->uid;
            $by[$u] ??= ['sales' => 0.0, 'vt' => 0.0, 'prev' => 0.0, 'da' => [], 'au' => []];
            if ($r->k === 'S') {
                $by[$u]['sales'] += (float) $r->a;
                $by[$u]['vt'] += (float) $r->b;
                $by[$u]['da'] = $this->vsIds($r->da);
                $by[$u]['au'] = $this->vsIds($r->au);
            } elseif ($r->k === 'P') {
                $by[$u]['prev'] += (float) $r->a;   // month's PT due …
            } else {
                $by[$u]['prev'] -= (float) $r->a;   // … less the 2307 declared that month
            }
        }
        return $by;
    }

    /** one row from the logins it covers */
    private function ptRow(array $by, array $uids, array $names): array
    {
        $sales = $vt = $prev = 0.0;
        $da = $au = [];
        foreach ($uids as $u) {
            if (!isset($by[$u])) continue;
            $sales += $by[$u]['sales'];
            $vt += $by[$u]['vt'];
            $prev += $by[$u]['prev'];
            foreach ($by[$u]['da'] as $id) if (!empty($names[$id])) $da[$names[$id]] = true;
            foreach ($by[$u]['au'] as $id) if (!empty($names[$id])) $au[$names[$id]] = true;
        }
        $due = round($sales * self::PT_RATE, 2);
        $prev = round($prev, 2);
        return [
            'sales'               => $this->vsMoney($sales),
            'pt_due'              => $due,
            'creditable_pt'       => $this->vsMoney($vt),
            'tax_paid_prev_filed' => $prev,
            'tax_paid'            => round($due - $vt - $prev, 2),
            'da_assigned'         => array_keys($da),
            'auditor_assigned'    => array_keys($au),
        ];
    }

    private function ptNames(array $by): array
    {
        $ids = [];
        foreach ($by as $x) foreach (array_merge($x['da'], $x['au']) as $id) $ids[$id] = true;
        return $ids ? DB::table('m_employee')->whereIn('id', array_keys($ids))
            ->pluck(DB::raw("TRIM(CONCAT_WS(' ', last_name, first_name, middle_name))"), 'id')->all() : [];
    }

    public function ptClientsSummary(Request $request)
    {
        $d = $request->validate([
            'from'      => ['required', 'date_format:Y-m'],
            'to'        => ['required', 'date_format:Y-m'],
            'status_id' => ['nullable', 'integer'],
            'da_id'     => ['nullable', 'integer'],
        ]);
        $statusId = (int) ($d['status_id'] ?? 0);
        $daId = (int) ($d['da_id'] ?? 0);
        if ($mine = $this->reportDaOnly($request)) $daId = $mine;
        $ca = $this->vsUserCa();
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_client', ['active', 'is_active']);

        $where = ["B.tax_registration = 'NonVAT'"];
        $bind = [];
        if ($active) $where[] = "A.{$active} = 1";
        if ($statusId > 0) { $where[] = 'A.status_id = ?'; $bind[] = $statusId; }
        if ($daId > 0) { $where[] = 'B.assigned_daid = ?'; $bind[] = $daId; }
        $clients = DB::select("
            SELECT A.client_num, MIN(A.id) AS client_id, MIN(A.last_name) AS sort_name,
                   MAX(TRIM(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name))) AS client_name,
                   " . ($sl ? "MAX(S.{$sl})" : "''") . " AS status,
                   GROUP_CONCAT(C.id ORDER BY C.id) AS user_ids, COUNT(C.id) AS count_acc
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($sl ? 'LEFT JOIN m_client_account_status S ON S.id = A.status_id' : '') . '
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY A.client_num
            ORDER BY sort_name, A.client_num', $bind);
        if (!$clients) return response()->json(['rows' => []]);

        $users = [];
        foreach ($clients as $c) foreach ($this->vsIds($c->user_ids) as $u) $users[$u] = true;
        $by = $this->ptAmounts(array_keys($users), $d['from'], $d['to'], $daId);
        $names = $this->ptNames($by);

        return response()->json(['rows' => array_map(fn ($c) => [
            'client_id'   => (int) $c->client_id,
            'client_num'  => $c->client_num,
            'client_name' => $c->client_name,
            'user_ids'    => implode(',', $this->vsIds($c->user_ids)),
            'count_acc'   => (int) $c->count_acc,
            'status'      => (string) $c->status,
        ] + $this->ptRow($by, $this->vsIds($c->user_ids), $names), $clients)]);
    }

    public function ptClientsBranches(Request $request)
    {
        $d = $request->validate([
            'from'       => ['required', 'date_format:Y-m'],
            'to'         => ['required', 'date_format:Y-m'],
            'client_num' => ['required', 'string', 'max:50'],
        ]);
        $ca = $this->vsUserCa();
        $sl = $this->vsStatusLabel();
        $active = $this->vsPick('m_client', ['active', 'is_active']);
        // old: the Non-VAT accounts of the client, status of the client, sorted by trade name
        $accounts = DB::select("
            SELECT A.id AS client_id, A.client_num, C.id AS uid,
                   TRIM(CONCAT_WS(' : ', B.branch_code, B.trade_name)) AS client_name,
                   " . ($sl ? "S.{$sl}" : "''") . " AS status
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($sl ? 'LEFT JOIN m_client_account_status S ON S.id = A.status_id' : '') . "
            WHERE B.client_num = ? AND B.tax_registration = 'NonVAT' " . ($active ? "AND A.{$active} = 1" : '') . '
            ORDER BY B.trade_name', [$d['client_num']]);
        $by = $this->ptAmounts(array_map(fn ($a) => (int) $a->uid, $accounts), $d['from'], $d['to'], 0);
        $names = $this->ptNames($by);
        return response()->json(['rows' => array_map(fn ($a) => [
            'client_id'   => (int) $a->client_id,
            'client_num'  => $a->client_num,
            'client_name' => $a->client_name,
            'user_ids'    => (string) $a->uid,
            'count_acc'   => 1,
            'status'      => (string) $a->status,
        ] + $this->ptRow($by, [(int) $a->uid], $names), $accounts)]);
    }
    /* =====================================================================
       EXPANDED WITHHOLDING TAX  (old pages/Reports/Expanded_Withholding_Tax.php)
       Paste INSIDE ReportController, under the VAT Summary block — it reuses its helpers
       (vsPick, vsTable, vsIds, vsIn, vsUserCa, vsClientUsers, vsClientInfo, vsMoney).
       2307 IT on AP invoices (posted only) + receive inventory, by the 2307 IT declared month.

       GET /reports/ewt-clients?from=&to=                              main list   (old doGetExpandedWithholdingTax)
       GET /reports/ewt-clients/branches?from=&to=&client_num=         branch rows (old doGetExpandedWithholdingTax_Detail)
       GET /reports/ewt-clients/atc?user_ids=&from=&to=&client_num=    per ATC + its documents (old doGetSumATC / doGetSumATCDetails)
       ===================================================================== */

    /** old lib_atc → m_atc: [table, code column, rate column] */
    private function ewtAtc(): array
    {
        $t = $this->vsTable(['m_atc', 'lib_atc']) ?? 'm_atc';
        return [$t, $this->vsPick($t, ['atc', 'name', 'code']) ?? 'name', $this->vsPick($t, ['rate', 'tax_rate']) ?? 'rate'];
    }

    /** the 2 sources (AP invoices + receive inventory) as one UNION; $cols = SELECT list using A (invoice) / C (vendor) / D (ATC) */
    private function ewtSources(?array $users, string $from, string $to, string $cols, string $group = '', bool $joins = true): array
    {
        $void = self::VS_VOID;
        [$at] = $this->ewtAtc();
        $v = $this->vsVendor();
        $posted = $this->vsPick('t_purchase_expense_master', ['record_status']) ? " AND A.record_status = 'posted'" : '';
        $ap = str_replace(['{ref}', '{by}', '{doc}'], ['A.invoice_no', 'A.added_by', 'A.document_no'], $cols);
        $grn = str_replace(['{ref}', '{by}', '{doc}'], ['A.grn_no', 'A.receive_by', 'A.ref_data'], $cols);
        $join = $joins ? "LEFT JOIN m_vendor_customer C ON C.id = A.vendor_id LEFT JOIN {$at} D ON D.id = A.atc_it_id" : '';
        $who = fn (string $col) => $users === null ? '' : "A.{$col} IN (" . $this->vsIn($users) . ') AND';
        $sql = "
            SELECT {$ap}
            FROM t_purchase_expense_master A {$join}
            WHERE {$who('added_by')} A.`2307_it_declared_month` BETWEEN ? AND ?
              AND A.`2307_it` != 0 AND A.invoice_status_id <> {$void}{$posted} {$group}
            UNION ALL
            SELECT {$grn}
            FROM t_grn_master A {$join}
            WHERE {$who('receive_by')} A.`2307_it_declared_month` BETWEEN ? AND ?
              AND A.`2307_it` != 0 AND A.invoice_status_id <> {$void} {$group}";
        $u = $users ?? [];
        return [str_replace('C.VENDOR', "C.{$v}", $sql), [...$u, $from, $to, ...$u, $from, $to]];
    }

    private function ewtByUser(?array $users, string $from, string $to): array
    {
        if ($users === []) return [];
        [$sql, $bind] = $this->ewtSources($users, $from, $to, "{by} AS uid, SUM(A.`2307_it`) AS tw, SUM(A.sub_total) AS amt", 'GROUP BY uid', false);
        $by = [];
        foreach (DB::select($sql, $bind) as $r) {
            $u = (int) $r->uid;
            $by[$u] ??= ['tw' => 0.0, 'amt' => 0.0];
            $by[$u]['tw'] += (float) $r->tw;
            $by[$u]['amt'] += (float) $r->amt;
        }
        return $by;
    }

    public function ewtClientsSummary(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $ca = $this->vsUserCa();
        $active = $this->vsPick('m_client', ['active', 'is_active']);
        $mine = $this->reportDaOnly($request);
 
        $where = [];
        $bind = [];
        if ($active) $where[] = "A.{$active} = 1";
        if ($mine) { $where[] = 'B.assigned_daid = ?'; $bind[] = $mine; }   // data analyst → his clients only
 
        $clients = DB::select("
            SELECT A.client_num, MIN(A.id) AS client_id, MIN(A.last_name) AS sort_name,
                   MAX(TRIM(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name))) AS client_name,
                   GROUP_CONCAT(C.id ORDER BY C.id) AS user_ids, COUNT(C.id) AS count_acc
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
            GROUP BY A.client_num
            ORDER BY sort_name, A.client_num', $bind);
 
        // a DA only needs his own logins' amounts; everyone else → all (null)
        $users = null;
        if ($mine) {
            $users = [];
            foreach ($clients as $c) foreach ($this->vsIds($c->user_ids) as $u) $users[$u] = true;
            $users = array_keys($users);
        }
        $by = $this->ewtByUser($users, $d['from'], $d['to']);
 
        $out = [];
        foreach ($clients as $c) {
            $tw = $amt = 0.0;
            foreach ($this->vsIds($c->user_ids) as $u) { $tw += $by[$u]['tw'] ?? 0; $amt += $by[$u]['amt'] ?? 0; }
            if ($tw <= 0) continue; // old: only clients with tax withheld
            $out[] = [
                'client_id'    => (int) $c->client_id,
                'client_num'   => $c->client_num,
                'client_name'  => $c->client_name,
                'user_ids'     => implode(',', $this->vsIds($c->user_ids)),
                'count_acc'    => (int) $c->count_acc,
                'amount'       => $this->vsMoney($amt),
                'tax_withheld' => $this->vsMoney($tw),
            ];
        }
        return response()->json(['rows' => $out]);
    }

    public function ewtClientsBranches(Request $request)
    {
        $d = $request->validate([
            'from'       => ['required', 'date_format:Y-m'],
            'to'         => ['required', 'date_format:Y-m'],
            'client_num' => ['required', 'string', 'max:50'],
        ]);
        $ca = $this->vsUserCa();
        $active = $this->vsPick('m_client', ['active', 'is_active']);
        // old: the client's VAT accounts, by branch code
        $accounts = DB::select("
            SELECT A.id AS client_id, A.client_num, B.id AS caid, C.id AS uid, B.branch_code, B.trade_name
            FROM m_client A
            INNER JOIN m_client_account B ON B.client_num = A.client_num
            INNER JOIN m_user_client C ON C.{$ca} = B.id
            WHERE A.client_num = ? AND B.tax_registration = 'VAT' " . ($active ? "AND A.{$active} = 1" : '') . '
            ORDER BY B.branch_code', [$d['client_num']]);
        $by = $this->ewtByUser(array_map(fn ($a) => (int) $a->uid, $accounts), $d['from'], $d['to']);
        return response()->json(['rows' => array_map(fn ($a) => [
            'client_id'    => (int) $a->client_id,
            'client_num'   => $a->client_num,
            'client_name'  => trim("{$a->branch_code} {$a->trade_name}"),
            'trade_name'   => $a->trade_name,
            'user_ids'     => (string) $a->uid,
            'caid'         => (int) $a->caid,
            'count_acc'    => 1,
            'amount'       => $this->vsMoney($by[(int) $a->uid]['amt'] ?? 0),
            'tax_withheld' => $this->vsMoney($by[(int) $a->uid]['tw'] ?? 0),
        ], $accounts)]);
    }

    /** per ATC (old doGetSumATC) with its documents (old doGetSumATCDetails) in one call */
    public function ewtClientsAtc(Request $request)
    {
        $d = $request->validate([
            'user_ids'   => ['required', 'string'],
            'from'       => ['required', 'date_format:Y-m'],
            'to'         => ['required', 'date_format:Y-m'],
            'client_num' => ['nullable', 'string', 'max:50'],
        ]);
        $users = $this->vsClientUsers($this->vsIds($d['user_ids']));
        if (!$users) return response()->json(['atcs' => [], 'client' => null]);
        [, $code, $rate] = $this->ewtAtc();
        $vTin = $this->vsPick('m_vendor_customer', ['tin']);
        $vBr = $this->vsPick('m_vendor_customer', ['branch_code']);
        [$sql, $bind] = $this->ewtSources($users, $d['from'], $d['to'], "
            {ref} AS reference_no, {by} AS uid, " . ($vTin ? "C.{$vTin}" : "''") . " AS tin, " . ($vBr ? "C.{$vBr}" : "''") . " AS branch_code,
            C.VENDOR AS vendor_name, {doc} AS document_no, A.atc_it_id AS atc_id, D.{$code} AS atc, D.{$rate} AS rate,
            A.`2307_it` AS tax_withheld, A.sub_total AS amount");

        $atcs = [];
        foreach (DB::select($sql, $bind) as $r) {
            $k = (string) ($r->atc ?? '');               // old: grouped by the ATC code
            $atcs[$k] ??= ['atc' => $r->atc, 'atc_ids' => [], 'rate' => 0.0, 'amount' => 0.0, 'tax_withheld' => 0.0, 'documents' => []];
            $atcs[$k]['atc_ids'][(int) $r->atc_id] = true;
            $atcs[$k]['rate'] = max($atcs[$k]['rate'], round((float) $r->rate, 2));
            $atcs[$k]['documents'][] = [
                'reference_no' => $r->reference_no, 'tin' => $r->tin, 'branch_code' => $r->branch_code, 'vendor_name' => $r->vendor_name,
                'document_no' => $r->document_no, 'atc' => $r->atc, 'rate' => (float) $r->rate,
                'amount' => (float) $r->amount, 'tax_withheld' => (float) $r->tax_withheld,
            ];
        }
        foreach ($atcs as &$a) {
            $a['atc_ids'] = array_keys($a['atc_ids']);
            $a['amount'] = $this->vsMoney(array_sum(array_column($a['documents'], 'amount')));
            $a['tax_withheld'] = $this->vsMoney(array_sum(array_column($a['documents'], 'tax_withheld')));
        }
        unset($a);
        ksort($atcs, SORT_STRING);
        return response()->json([
            'atcs'   => array_values($atcs),
            'client' => !empty($d['client_num']) ? $this->vsClientInfo($d['client_num']) : null,
        ]);
    }


    /* =====================================================================
       REVERTED SUMMARY  (old pages/Reports/Reverted_Summary.php)
       Paste INSIDE ReportController, under the VAT Summary block — it reuses its helpers (vsPick, vsTable, vsOnly, vsVendor).
       Invoices the auditor sent back (audit_status_id = 3 REVERTED): Sales / Purchases / Expenses.

       GET  /reports/reverted                       the 3 lists + whether you may audit
       GET  /reports/reverted/{type}/{invoiceNo}    one invoice (old doGetMasterInfo + doGetTableDetails)   type = sales|purchase|expense
       GET  /reports/reverted/{type}/{invoiceNo}/attachment   the invoice's file (S3) for the popup viewer
       POST /reports/reverted/{type}/{invoiceNo}/audit   AUDITOR → Audited (old doAuditReview)
       ===================================================================== */

    private const RV_REVERTED = 3, RV_AUDITED = 2;
    private const RV_DA = 3, RV_AUDITOR = 5, RV_AUDIT_SUPERVISOR = 10;   // m_employee.designation_id

    /** the logged-in employee: [id, designation_id] */
    private function rvMe(Request $request): array
    {
        $u = $request->user();
        if (!$u) return [null, 0];
        $ids = [];
        foreach (['employee_id', 'emp_id', 'employeeid'] as $k) {
            $v = $u->getAttribute($k);
            if (is_numeric($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        $ids[] = (int) $u->id;
        foreach (array_unique($ids) as $id) {
            $e = DB::table('m_employee')->where('id', $id)->first(['id', 'designation_id']);
            if ($e) return [(int) $e->id, (int) $e->designation_id];
        }
        return [null, 0];
    }

    /** old page: the AUDITOR button only for UserTypeID 5 / 10 (m_user_staff.user_type_id); designation 5 / 10 also allowed */
    private function rvCanAudit(Request $request): bool
    {
        $u = $request->user();
        if (!$u) return false;
        $type = $u->getAttribute('user_type_id');
        if ($type === null && $this->vsCols('m_user_staff')) {
            $type = DB::table('m_user_staff')->where('id', $u->id)->value('user_type_id');
        }
        if (in_array((int) $type, [5, 10], true)) return true;
        [, $designation] = $this->rvMe($request);
        return in_array($designation, [self::RV_AUDITOR, self::RV_AUDIT_SUPERVISOR], true);
    }

    /** sales → sales tables, purchase / expense → AP tables */
    private function rvTables(string $type): array
    {
        return $type === 'sales'
            ? ['t_sales_invoice_master', 't_sales_invoice_detail', 'customer_id', null]
            : ['t_purchase_expense_master', 't_purchase_expense_detail', 'vendor_id', $type === 'purchase' ? 'Purchases' : 'Expenses'];
    }

    public function revertedList(Request $request)
    {
        [$me, $designation] = $this->rvMe($request);
        $ca = $this->vsUserCa();
        $name = "TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name))";
        // a data analyst sees his own reverted invoices; auditors / supervisors / admins see all
        $mine = $designation === self::RV_DA && $me ? ' AND A.assigned_daid = ' . (int) $me : '';
        $remarks = fn (string $t) => $this->vsPick($t, ['audit_remarks']) ? 'A.audit_remarks' : "''";
        $part = fn (string $t, string $kind, string $typeSql) => "
            SELECT '{$kind}' AS kind, A.id, A.invoice_no, A.document_no, A.declared_month, {$remarks($t)} AS audit_remarks,
                   B.trade_name, B.branch_code, {$name} AS da, A.updated_at
            FROM {$t} A
            LEFT JOIN m_user_client U ON U.id = A.added_by
            LEFT JOIN m_client_account B ON B.id = U.{$ca}
            LEFT JOIN m_employee E ON E.id = A.assigned_daid
            WHERE A.audit_status_id = " . self::RV_REVERTED . " {$typeSql}{$mine}";
        $sql = $part('t_sales_invoice_master', 'sales', '') . ' UNION ALL '
            . $part('t_purchase_expense_master', 'purchase', "AND A.trans_type = 'Purchases'") . ' UNION ALL '
            . $part('t_purchase_expense_master', 'expense', "AND A.trans_type = 'Expenses'")
            . ' ORDER BY updated_at DESC, id DESC';
        $out = ['sales' => [], 'purchase' => [], 'expense' => []];
        foreach (DB::select($sql) as $r) {
            $out[$r->kind][] = [
                'id' => (int) $r->id, 'invoice_no' => $r->invoice_no, 'document_no' => $r->document_no,
                'declared_month' => $r->declared_month, 'audit_remarks' => (string) $r->audit_remarks,
                'trade_name' => $r->trade_name, 'branch_code' => $r->branch_code, 'da' => $r->da,
            ];
        }
        return response()->json($out + [
            'can_audit' => $this->rvCanAudit($request),
        ]);
    }
    public function revertedShow(Request $request, string $type, string $invoiceNo)
    {
        abort_unless(in_array($type, ['sales', 'purchase', 'expense'], true), 404);
        [$mt, $dt, $partyCol] = $this->rvTables($type);
        $inv = DB::table($mt)->where('invoice_no', $invoiceNo)->first();
        abort_unless($inv, 404, 'Invoice not found.');

        $label = fn (?string $table, $id, array $cols) => ($table && $id && ($c = $this->vsPick($table, $cols)))
            ? DB::table($table)->where('id', $id)->value($c) : null;
        $atcT = $this->vsTable(['m_atc']);
        $atc = function ($id) use ($atcT) {
            if (!$atcT || !$id) return null;
            $a = DB::table($atcT)->where('id', $id)->first();
            return $a ? trim(($a->name ?? $a->atc ?? '') . (isset($a->rate) ? ' - ' . round((float) $a->rate * (((float) $a->rate) <= 1 ? 100 : 1)) . '%' : '')) : null;
        };
        $acName = $this->vsPick('m_charts_of_account', ['account_name', 'name']) ?? 'account_name';
        $acBy = $this->vsPick('m_charts_of_account', ['added_by']);
        $account = fn ($code) => $code ? DB::table('m_charts_of_account')->where('account_code', $code)
            ->when($acBy, fn ($q) => $q->where('added_by', $inv->added_by))->value($acName) : null;

        // lines
        $itemCode = $this->vsPick('m_items', ['item_code', 'code']);
        $itemDesc = $this->vsPick('m_items', ['description', 'item_name', 'name']);
        $unitT = $this->vsTable(['m_unit_type', 'm_unit_types']);
        $unitDesc = $unitT ? $this->vsPick($unitT, ['description', 'name']) : null;
        $q = DB::table("{$dt} as L")->where('L.invoice_no', $invoiceNo)->orderBy('L.id');
        $sel = ['L.id', 'L.account_code'];
        foreach (['quantity', 'unit_price', 'amount', 'discount_amount', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated'] as $c) {
            $sel[] = $this->vsPick($dt, [$c]) ? "L.{$c}" : DB::raw("0 AS {$c}");
        }
        if ($this->vsPick($dt, ['item_id']) && ($itemCode || $itemDesc)) {
            $q->leftJoin('m_items as I', 'I.id', '=', 'L.item_id');
            $sel[] = $itemCode ? "I.{$itemCode} as item_code" : DB::raw("'' AS item_code");
            $sel[] = $itemDesc ? "I.{$itemDesc} as description" : DB::raw("'' AS description");
        }
        if ($unitT && $unitDesc && $this->vsPick($dt, ['unit_type_id'])) {
            $q->leftJoin("{$unitT} as T", 'T.id', '=', 'L.unit_type_id');
            $sel[] = "T.{$unitDesc} as unit_type";
        }
        $q->leftJoin('m_charts_of_account as C', function ($j) use ($acBy, $inv) {
            $j->on('C.account_code', '=', 'L.account_code');
            if ($acBy) $j->where('C.added_by', '=', $inv->added_by);
        });
        $sel[] = "C.{$acName} as account_title";
        $lines = $q->get($sel)->map(fn ($l) => [
            'id' => (int) $l->id, 'item_code' => $l->item_code ?? '', 'description' => $l->description ?? '',
            'account' => trim(($l->account_code ?? '') . ' ' . ($l->account_title ?? '')), 'unit_type' => $l->unit_type ?? '',
            'quantity' => (float) $l->quantity, 'price' => (float) $l->unit_price, 'total' => (float) $l->amount,
            'discount' => (float) $l->discount_amount, 'vat' => (float) $l->input_vat,
            'net_of_vat' => round((float) $l->amount - (float) $l->input_vat, 4),   // old screen: Total − VAT
            'vat_exempt' => (float) $l->vat_exempt, 'non_vat' => (float) $l->non_vat, 'zero_rated' => (float) $l->zero_rated,
        ]);

        $docTypeT = $this->vsTable(['m_document_type', 'm_document_types', 'm_doc_ref']);
        $party = ($pid = $inv->{$partyCol} ?? null) ? DB::table('m_vendor_customer')->where('id', $pid)->first() : null;
        return response()->json([
            'type'           => $type,
            'invoice_no'     => $inv->invoice_no,
            'party'          => $label('m_vendor_customer', $inv->{$partyCol} ?? null, ['name', 'vendor_name']),
            'party_tin'      => $party->tin ?? '',
            'party_branch'   => $party->branch_code ?? '',
            'party_address'  => $this->rvAddress($party),
            'remarks'        => $inv->remarks ?? '',
            'invoice_status' => $label('m_invoice_status', $inv->invoice_status_id ?? null, ['name', 'status_name', 'description']),
            'audit_status'   => $label('m_audit_status', $inv->audit_status_id ?? null, ['name', 'audit_status', 'description']),
            'audit_remarks'  => $inv->audit_remarks ?? '',
            'date'           => $inv->transaction_date ? substr((string) $inv->transaction_date, 0, 10) : null,
            'currency'       => $inv->currency ?? 'PHP',
            'ex_rate'        => (float) ($inv->ex_rate ?? 1),
            'document_no'    => $inv->document_no ?? '',
            'document_type'  => $docTypeT ? $label($docTypeT, $inv->document_type_id ?? null, ['name', 'description', 'doc_ref']) : null,
            'declared_month' => $inv->declared_month ?? '',
            'account'        => trim(($inv->account_code ?? '') . ' ' . ($account($inv->account_code ?? null) ?? '')),
            'attachment'     => $inv->attachment ?? '',
            'sub_total'      => (float) ($inv->sub_total ?? 0),
            'atc_vt'         => $atc($inv->atc_vt_id ?? null),
            'vt_2307'        => (float) ($inv->{'2307_vt'} ?? 0),
            'vt_month'       => $inv->{'2307_vt_declared_month'} ?? '',
            'atc_it'         => $atc($inv->atc_it_id ?? null),
            'it_2307'        => (float) ($inv->{'2307_it'} ?? 0),
            'it_month'       => $inv->{'2307_it_declared_month'} ?? '',
            'discount_type_id' => (int) ($inv->discount_type_id ?? 0),
            'discount'       => (float) ($inv->discount_amount ?? 0),
            'total'          => (float) ($inv->total ?? 0),
            'lines'          => $lines,
        ]);
    }

    /** vendor / customer address lines: [street, city province] (only the columns that exist) */
    private function rvAddress(?object $p): array
    {
        if (!$p) return [];
        $street = $p->street ?? $p->address ?? $p->Street ?? '';
        $place = [];
        foreach ([['m_barangay', ['barangay_id', 'brgy_id']], ['m_city_mun', ['city_mun_id', 'city_id', 'citymun_id']], ['m_province', ['province_id', 'prov_id']]] as [$t, $keys]) {
            foreach ($keys as $k) {
                if (empty($p->{$k}) || !$this->vsCols($t)) continue;
                $col = $this->vsPick($t, ['name', 'description', "{$t}_name", 'city_mun_name', 'province_name', 'barangay_name']);
                if ($col && ($v = DB::table($t)->where('id', $p->{$k})->value($col))) $place[] = $v;
                break;
            }
        }
        return array_values(array_filter([trim((string) $street), implode(' ', $place)]));
    }

    /** the invoice's attachment from S3 (same keys as Sales / AP invoice pages), for anyone who can open the report */
    public function revertedAttachment(Request $request, string $type, string $invoiceNo)
    {
        abort_unless(in_array($type, ['sales', 'purchase', 'expense'], true), 404);
        [$mt] = $this->rvTables($type);
        $inv = DB::table($mt)->where('invoice_no', $invoiceNo)->first();
        $file = trim((string) ($inv->attachment ?? ''));
        if (!$inv || $file === '') return response()->json(['message' => 'No attachment for this invoice.'], 404);
        if (preg_match('/^https?:\/\//i', $file)) return response()->json(['url' => $file]);

        $own = ['sales' => 'Sales_Invoices', 'purchase' => 'Vendor_Invoices', 'expense' => 'Expenses_Transaction'][$type];
        $letter = match (strtoupper(substr($file, 0, 1))) {
            'S' => 'Sales_Invoices', 'P' => 'Vendor_Invoices', 'E' => 'Expenses_Transaction', default => $own,
        };
        // who uploaded it: the invoice owner, or the client who scanned it (t_mobile_transaction)
        $owners = [(int) $inv->added_by];
        if ($this->vsCols('t_mobile_transaction')) {
            foreach (DB::table('t_mobile_transaction')->where('filename', $file)->pluck('added_by') as $by) $owners[] = (int) $by;
        }
        $byOwner = fn (string $folder) => array_map(fn ($u) => "{$folder}/{$u}-{$file}", array_unique($owners));
        // apAttachmentKeys / attachmentKeys / scanKey order
        $keys = (int) ($inv->is_scanned ?? 0) === 1
            ? [...$byOwner($letter), "{$own}/{$inv->id}-{$file}"]
            : ["{$own}/{$inv->id}-{$file}", ...$byOwner($letter)];
        $keys = [...$keys, ...$byOwner($own), "{$letter}/{$file}", "{$own}/{$file}", "Scanned_Receipts/{$file}"];

        $disk = \Illuminate\Support\Facades\Storage::disk('s3');
        foreach (array_unique($keys) as $key) {
            if (!$disk->exists($key)) continue;
            return response()->stream(function () use ($disk, $key) {
                $s = $disk->readStream($key);
                fpassthru($s);
                fclose($s);
            }, 200, [
                'Content-Type'        => $disk->mimeType($key) ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="' . addslashes($file) . '"',
                'Cache-Control'       => 'private, max-age=300',
            ]);
        }
        // 200 + JSON so the popup can say why (a 404 there means the route is missing)
        return response()->json(['missing' => true, 'message' => "{$file} is not on S3."] + (config('app.debug') ? ['tried' => array_values(array_unique($keys))] : []));
    }

    /** AUDITOR → status Audited + remarks; history row (old doAuditReview) */
    public function revertedAudit(Request $request, string $type, string $invoiceNo)
    {
        abort_unless(in_array($type, ['sales', 'purchase', 'expense'], true), 404);
        $d = $request->validate([
            'audit_status_id' => ['required', 'integer', 'in:' . self::RV_AUDITED],
            'remarks'         => ['nullable', 'string', 'max:1000'],
        ], ['audit_status_id.required' => 'Please select audit status']);
        [$me] = $this->rvMe($request);
        abort_unless($this->rvCanAudit($request), 403, 'Only an auditor can do this.');
        [$mt, $dt] = $this->rvTables($type);
        abort_unless(DB::table($mt)->where('invoice_no', $invoiceNo)->exists(), 404, 'Invoice not found.');
        $aud = $this->vsPick($mt, ['assigned_auid', 'assigned_auditor_id']);
        $audD = $this->vsPick($dt, ['assigned_auid', 'assigned_auditor_id']);
        $now = now();

        DB::transaction(function () use ($mt, $dt, $invoiceNo, $d, $me, $aud, $audD, $now) {
            DB::table($mt)->where('invoice_no', $invoiceNo)->update($this->vsOnly($mt, [
                'audit_remarks' => $d['remarks'] ?? '', 'audit_status_id' => $d['audit_status_id'], 'updated_at' => $now,
            ] + ($aud ? [$aud => $me] : [])));
            $line = $this->vsOnly($dt, ['audit_status_id' => $d['audit_status_id']] + ($audD ? [$audD => $me] : []));
            if ($line) DB::table($dt)->where('invoice_no', $invoiceNo)->update($line);
            if ($this->vsCols('t_audit_history')) {
                DB::table('t_audit_history')->insert($this->vsOnly('t_audit_history', [
                    'reference_no' => $invoiceNo, 'audit_status_id' => $d['audit_status_id'], 'auditor_id' => $me,
                    'remarks' => $d['remarks'] ?? '', 'created_at' => $now, 'updated_at' => $now,
                ]));
            }
        });
        return response()->json(['ok' => true]);
    }
   public function vatClientsPending(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'from'      => ['required', 'date_format:Y-m'],
            'to'        => ['required', 'date_format:Y-m'],
        ]);
        $ut = $this->vsUndeclaredTable();
        $list = DB::table($ut)->where('client_id', (int) $d['client_id'])
            ->whereBetween('old_declared_month', [$d['from'], $d['to']])
            ->where('is_approved', 0)->where('report', 'EBIR')
            ->orderBy('old_declared_month')->orderBy('id')->get();
        if ($list->isEmpty()) return response()->json(['rows' => [], 'total' => 0, 'vat' => 0]);
 
        $rows = $this->vsUndeclaredRows($list);
        return response()->json([
            'rows'  => $rows,
            'total' => round(array_sum(array_column($rows, 'amount')), 2),
            'vat'   => round(array_sum(array_column($rows, 'vat')), 2),
        ]);
    }
    private function vsUndeclaredRows($list): array
    {
        $refs = $list->pluck('reference_no')->unique()->values()->all();
        $v = $this->vsVendor();
        $info = [];
 
        foreach (DB::table('t_sales_invoice_master as M')->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.customer_id')
            ->whereIn('M.invoice_no', $refs)
            ->get(['M.invoice_no AS ref', 'M.document_no', 'M.transaction_date AS date', "C.{$v} AS name"]) as $r) {
            $info[$r->ref] = ['module' => 'Sales', 'document_no' => $r->document_no, 'date' => $r->date, 'name' => $r->name];
        }
        foreach (DB::table('t_purchase_expense_master as M')->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.vendor_id')
            ->whereIn('M.invoice_no', $refs)
            ->get(['M.invoice_no AS ref', 'M.document_no', 'M.transaction_date AS date', "C.{$v} AS name", 'M.trans_type']) as $r) {
            $info[$r->ref] ??= ['module' => ucfirst(strtolower((string) $r->trans_type)) ?: 'Purchases', 'document_no' => $r->document_no,
                'date' => $r->date, 'name' => $r->name];
        }
        if ($this->vsCols('t_grn_master')) {
            foreach (DB::table('t_grn_master as M')->leftJoin('m_vendor_customer as C', 'C.id', '=', 'M.vendor_id')
                ->where(fn ($q) => $q->whereIn('M.grn_no', $refs)->orWhereIn('M.ref_data', $refs))
                ->get(['M.grn_no', 'M.ref_data', 'M.grn_date AS date', "C.{$v} AS name"]) as $r) {
                $row = ['module' => 'Receive Inventory', 'document_no' => $r->ref_data, 'date' => $r->date, 'name' => $r->name];
                foreach (array_filter([$r->grn_no, $r->ref_data]) as $k) $info[$k] ??= $row;
            }
        }
 
        $money = $this->vsPendingNet($refs);
        $ids = [];
        foreach ($list as $u) { $ids[] = $u->undeclared_by ?? 0; $ids[] = $u->approved_by ?? 0; }
        $who = $this->vsStaffNames($ids);
        $m7 = fn ($x) => $x ? substr((string) $x, 0, 7) : '';
 
        return $list->map(function ($u) use ($info, $money, $who, $m7) {
            $i = $info[$u->reference_no] ?? ['module' => '—', 'document_no' => '', 'date' => null, 'name' => ''];
            return [
                'id'                    => (int) $u->id,
                'reference'             => $u->reference_no,
                'document_no'           => (string) ($i['document_no'] ?? ''),
                'transaction_date'      => $i['date'] ? substr((string) $i['date'], 0, 10) : null,
                'fullname'              => (string) ($i['name'] ?? ''),
                'module'                => $i['module'],
                'amount'                => round($money[$u->reference_no]['net'] ?? 0, 2),   // NET (no VAT)
                'vat'                   => round($money[$u->reference_no]['vat'] ?? 0, 2),
                'old_declared_month'    => $m7($u->old_declared_month),
                'new_declared_month'    => $m7($u->new_declared_month),
                'new_wh_declared_month' => $m7($u->new_wh_declared_month),
                'undeclared_at'         => $this->vsLocalTime($u->created_at),
                'undeclared_by'         => $who[(int) ($u->undeclared_by ?? 0)] ?? '',
                'approved_at'           => $this->vsLocalTime(($u->approved_at ?? null) ?: ((int) $u->is_approved === 1 ? $u->updated_at : null)),
                'approved_by'           => $who[(int) ($u->approved_by ?? 0)] ?? '',
            ];
        })->values()->all();
    }
    public function vatClientsHistory(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'from'      => ['required', 'date_format:Y-m'],
            'to'        => ['required', 'date_format:Y-m'],
        ]);
        $ut = $this->vsUndeclaredTable();
        $hasNo = Schema::hasColumn($ut, 'approval_no');
        $hasAt = Schema::hasColumn($ut, 'approved_at');
        $list = DB::table($ut)->where('client_id', (int) $d['client_id'])->where('is_approved', 1)->where('report', 'EBIR')
            ->where(fn ($q) => $q->whereBetween('old_declared_month', [$d['from'], $d['to']])
                ->orWhereBetween('new_declared_month', [$d['from'], $d['to']]))
            ->orderByDesc($hasAt ? DB::raw('COALESCE(approved_at, updated_at)') : 'updated_at')->orderBy('id')
            ->get();
        if ($list->isEmpty()) return response()->json(['groups' => []]);

        $rows = $this->vsUndeclaredRows($list);
        $groups = [];
        foreach ($list->values() as $i => $u) {
            $r = $rows[$i];
            // one approval = same approval_no; older rows (before the migration) = same minute
            $key = ($hasNo && !empty($u->approval_no)) ? $u->approval_no : 'T' . ($r['approved_at'] ?? '?');
            $groups[$key] ??= ['key' => $key, 'approval_no' => $hasNo ? ($u->approval_no ?: null) : null,
                'approved_at' => $r['approved_at'], 'approved_by' => $r['approved_by'], 'count' => 0, 'net' => 0.0, 'vat' => 0.0, 'rows' => []];
            $groups[$key]['count']++;
            $groups[$key]['net'] += $r['amount'];
            $groups[$key]['vat'] += $r['vat'];
            $groups[$key]['rows'][] = $r;
        }
        foreach ($groups as &$g) { $g['net'] = round($g['net'], 2); $g['vat'] = round($g['vat'], 2); }
        unset($g);

        // before / after E-BIR table saved at approval time
        $nos = array_values(array_filter(array_column($groups, 'approval_no')));
        $snaps = $nos && Schema::hasTable('t_undeclared_approval')
            ? DB::table('t_undeclared_approval')->whereIn('approval_no', $nos)->get()->keyBy('approval_no') : collect();
        foreach ($groups as $k => $g) {
            $s = $g['approval_no'] ? $snaps->get($g['approval_no']) : null;
            $groups[$k]['snapshot'] = $s ? [
                'from' => $s->period_from, 'to' => $s->period_to,
                'old'  => json_decode($s->old_data, true), 'new' => json_decode($s->new_data, true),
            ] : null;
        }
        return response()->json(['groups' => array_values($groups)]);
    }
    private function reportDaOnly(Request $request): int
    {
        static $cache = [];
        $u = $request->user();
        if (!$u) return 0;
        $key = get_class($u) . ':' . $u->id;
        if (array_key_exists($key, $cache)) return $cache[$key];
 
        // the employee behind this login: employee_id, else m_user_staff.comid = m_employee.com_id
        $empId = (int) ($u->employee_id ?? 0);
        if ($empId <= 0 && !empty($u->comid)) {
            $empId = (int) DB::table('m_employee')->where('com_id', $u->comid)->value('id');
        }
        if ($empId <= 0) return $cache[$key] = 0;
 
        $type = (int) ($u->user_type_id ?? 0);
        $designation = (int) DB::table('m_employee')->where('id', $empId)->value('designation_id');
        $isDa = $type === 3 || (!$type && $designation === 3);
 
        return $cache[$key] = $isDa ? $empId : 0;
    }
    private function vsDaCannot(Request $request): void
    {
        abort_if($this->reportDaOnly($request) > 0, 403, 'Data analysts can view only — undeclare, undo and approve are not allowed.');
    }

    private function vsPendingNet(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map('strval', $refs), 'strlen')));
        if (!$refs) return [];
        $q = implode(',', array_fill(0, count($refs), '?'));
        $net = 'COALESCE(A.net_of_vat, 0) + COALESCE(A.vat_exempt, 0) + COALESCE(A.zero_rated, 0)';
        $out = [];
        $put = function ($ref, $n, $v) use (&$out) {
            $out[$ref] ??= ['net' => 0.0, 'vat' => 0.0];
            $out[$ref]['net'] += (float) $n;
            $out[$ref]['vat'] += (float) $v;
        };
 
        // Sales
        $rate = $this->vsPick('t_sales_invoice_detail', ['ex_rate']) ? 'COALESCE(NULLIF(A.ex_rate, 0), 1)' : '1';
        foreach (DB::select("
            SELECT A.invoice_no AS ref, SUM(({$net}) * {$rate}) AS n, SUM(COALESCE(A.input_vat, 0) * {$rate}) AS v
            FROM t_sales_invoice_detail A
            WHERE A.invoice_no IN ({$q}){$this->vsSalesPosted('A')}
            GROUP BY A.invoice_no", $refs) as $r) $put($r->ref, $r->n, $r->v);
 
        // Purchases / Expenses (AP invoices)
        $posted = $this->vsPick('t_purchase_expense_detail', ['record_status']) ? " AND A.record_status = 'posted'" : '';
        foreach (DB::select("
            SELECT A.invoice_no AS ref, SUM({$net}) AS n, SUM(COALESCE(A.input_vat, 0)) AS v
            FROM t_purchase_expense_detail A
            WHERE A.invoice_no IN ({$q}){$posted}
            GROUP BY A.invoice_no", $refs) as $r) $put($r->ref, $r->n, $r->v);
 
        // Receive Inventory
        if ($this->vsCols('t_grn_detail') && $this->vsCols('t_grn_master')) {
            foreach (DB::select("
                SELECT M.grn_no, M.ref_data, SUM({$net}) AS n, SUM(COALESCE(A.input_vat, 0)) AS v
                FROM t_grn_detail A INNER JOIN t_grn_master M ON M.grn_no = A.grn_no
                WHERE M.grn_no IN ({$q}) OR M.ref_data IN ({$q})
                GROUP BY M.grn_no, M.ref_data", [...$refs, ...$refs]) as $r) {
                foreach (array_unique(array_filter([$r->grn_no, $r->ref_data])) as $k) {
                    if (in_array((string) $k, $refs, true) && !isset($out[$k])) $put($k, $r->n, $r->v);
                }
            }
        }
        return $out;
    }
    private function vsStaffNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->vsCols('m_user_staff')) return [];
        $user = $this->vsPick('m_user_staff', ['username', 'user_name']);
        $rows = DB::table('m_user_staff as U')
            ->leftJoin('m_employee as E', 'E.com_id', '=', 'U.comid')
            ->whereIn('U.id', $ids)
            ->get(['U.id', DB::raw("NULLIF(TRIM(CONCAT_WS(' ', E.last_name, E.first_name)), '') AS name"),
                DB::raw(($user ? "U.{$user}" : "''") . ' AS username')]);
        $out = [];
        foreach ($rows as $r) $out[(int) $r->id] ??= $r->name ?: ($r->username ?: "User #{$r->id}");
        return $out;
    }
    /** saves the new who / when columns — checked live, not the 1-day vsCols cache */
    private function vsAudit(array $values): array
    {
        static $has = null;
        $t = $this->vsUndeclaredTable();
        $has ??= array_flip(array_filter(['undeclared_by', 'approved_by', 'approved_at', 'approval_no'], fn ($c) => Schema::hasColumn($t, $c)));
        return array_intersect_key($values, $has);
    }

    /** Laravel saves now() in UTC → show Philippine time */
    private function vsLocalTime($ts): ?string
    {
        if (!$ts) return null;
        try {
            return Carbon::parse((string) $ts, config('app.timezone') ?: 'UTC')->setTimezone('Asia/Manila')->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return substr((string) $ts, 0, 16);
        }
    }
    private function vsApprovalSnapshot(Request $request, array $d, $list): ?array
    {
        if (empty($d['user_ids']) || !Schema::hasTable('t_undeclared_approval')) return null;
        // reach the month the documents move to (e.g. Oct → Nov), so After shows where they landed
        $to = $d['to'];
        foreach ($list as $u) {
            $m = substr((string) $u->new_declared_month, 0, 7);
            if (preg_match('/^\d{4}-\d{2}$/', $m) && $m > $to) $to = $m;
        }
        try {
            $take = function (string $mode) use ($request, $d, $to) {
                $sub = Request::create('/', 'GET', array_filter([
                    'user_ids' => $d['user_ids'], 'client_id' => $d['client_id'], 'caid' => $d['caid'] ?? null,
                    'from' => $d['from'], 'to' => $to, 'mode' => $mode,
                ], fn ($v) => $v !== null && $v !== ''));
                $sub->setUserResolver($request->getUserResolver());
                $data = $this->vatClientsEbir($sub)->getData(true);
                unset($data['locked_months']);
                return $data;
            };
            return ['old' => $take('initial'), 'new' => $take('adjusted'), 'to' => $to];
        } catch (\Throwable $e) {
            report($e);          // never block the approval because of the snapshot
            return null;
        }
    }
}