<?php

namespace App\Http\Controllers\Api;

use App\Models\ATC;
use App\Models\AuditStatus;
use App\Models\ChartsOfAccount;
use App\Models\ClientAccount;
use App\Models\DeclaredMonthSettings;
use App\Models\DocumentType;
use App\Models\InvoiceStatus;
use App\Models\MobileTransaction;
use App\Models\RequestTicket;
use App\Models\TicketData;
use App\Models\TicketHistory;
use App\Models\UserStaff;
use App\Models\VendorCustomer;
use Aws\Textract\TextractClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Purchases and Expenses (old Vendor_Invoices.php / Expenses page).
 *
 * Both live in t_purchase_expense_master / _detail, split by trans_type ('Purchases' / 'Expenses').
 * The API answers with the SAME field names as SalesController (customers, sales_accounts,
 * customer_id, customer_name, trans_type = posted|billing|invalid …) so the React pages are
 * the same as the Sales pages:
 *     customer      = vendor
 *     sales account = purchase / expense account
 *     trans_type    = record_status column (posted = MAIN, billing = FOR PAYMENT, invalid = INVALID)
 *
 * PurchaseController and ExpenseController only set the settings below.
 */
abstract class ApInvoiceController extends SalesController
{
    protected const M = 't_purchase_expense_master';
    protected const D = 't_purchase_expense_detail';

    protected string $apType;          // 'Purchases' | 'Expenses'
    protected string $apScanType;      // t_mobile_transaction.trans_type: 'purchases' | 'expenses'
    protected string $apFolder;        // S3 folder: 'Vendor_Invoices' | 'Expenses_Transaction'
    protected string $apModule;        // ticket module name
    protected array  $apTransferTo;    // where a scanned receipt can be moved
    protected string $apDefaultItem;   // REGEXP for the default scan line item
    protected array  $apAccountPrefix = ['1', '5', '6', '8']; // 1 = Assets (equipment, supplies, prepaid …) + old doGetChartsOfDetails

    /* =====================================================================
       Helpers (prefixed with "ap" so they never clash with SalesController)
       ===================================================================== */

    private static array $apColCache = [];

    /** document no. check: purchase / expense invoices of this module */
    protected function docScope(): array
    {
        return [self::M, 'vendor_id', $this->apType];
    }

    protected function apCols(string $table): array
    {
        return self::$apColCache[$table] ??= Schema::getColumnListing($table);
    }

    protected function apOnly(string $table, array $row): array
    {
        return array_intersect_key($row, array_flip($this->apCols($table)));
    }

    protected function apFirstCol(string $table, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, $this->apCols($table), true)) return $n;
        return null;
    }

    protected function apLocked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        if (!$caid || !$months) return false;
        return DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    protected function apStatusId(string $model, array $names): ?int
    {
        foreach ($names as $n) {
            $id = $model::whereRaw('UPPER(name) LIKE ?', [strtoupper($n) . '%'])->value('id');
            if ($id) return (int) $id;
        }
        return null;
    }

    protected function apMonthLabel(?string $ym): string
    {
        if (!$ym || !preg_match('/^\d{4}-\d{2}$/', $ym)) return (string) $ym;
        return Carbon::createFromFormat('Y-m-d', $ym . '-01')->format('F Y');
    }

    // this module's records of this account
    protected function apBase(int $addedBy)
    {
        return DB::table(self::M)
            ->where('added_by', $addedBy)
            ->whereRaw('LOWER(trans_type) = ?', [strtolower($this->apType)]);
    }

    protected function apFind(int $addedBy, string $col, $value): ?object
    {
        return $this->apBase($addedBy)->where($col, $value)->first();
    }

    protected function apRecord(?object $inv): string
    {
        return ($inv->record_status ?? null) ?: 'posted';
    }

    protected function apVendorCols(): array
    {
        return array_values(array_intersect(['id', 'name', 'tin', 'branch_code', 'address', 'city_country'], $this->apCols('m_vendor_customer')));
    }

    // invoice status: PAID when "Accounts" is a for-paid account (old doGetAccountForPaidStatus)
    // invoice status:
    //   Cash / Cash on Hand / Cash in Bank (or ticked "for paid") → PAID right away
    //   otherwise: new bill = UNPAID; edited bill = based on what was already paid (VOID / CANCEL stay)
    protected function apStatusFor(int $addedBy, ?string $accountCode, ?int $current = null, ?object $inv = null, ?float $grandTotal = null): ?int
    {
        if (\App\Support\PaymentAccounts::isCash($addedBy, $accountCode)) {
            return \App\Support\PaymentAccounts::statusId('PAID');
        }
        if ($current && preg_match('/VOID|CANCEL/i', (string) InvoiceStatus::where('id', $current)->value('name'))) {
            return $current;
        }
        if ($inv) {
            $paid = (float) DB::table('t_pvoucher_detail')->where('ap_invoice_no', $inv->invoice_no)->sum('amount');
            return \App\Support\PaymentAccounts::statusFor((float) ($grandTotal ?? $inv->grand_total), $paid);
        }
        return \App\Support\PaymentAccounts::statusId('UNPAID');
    }
    protected function apRules(): array
    {
        return [
            'customer_id'          => ['nullable', 'integer'],     // = vendor
            'transaction_date'     => ['required', 'date'],
            'declared_month'       => ['required', 'date_format:Y-m'],
            'document_no'          => ['nullable', 'string', 'max:100'],
            'document_type_id'     => ['nullable', 'integer'],
            'account_code'         => ['nullable', 'string', 'max:15'],
            'currency'             => ['nullable', 'string', 'max:10'],
            'ex_rate'              => ['nullable', 'numeric', 'gt:0'],
            'remarks'              => ['nullable', 'string', 'max:255'],
            'atc_vt_id'            => ['nullable', 'integer'],
            'atc_it_id'            => ['nullable', 'integer'],
            'vt_month'             => ['nullable', 'date_format:Y-m'],
            'it_month'             => ['nullable', 'date_format:Y-m'],
            'discount_type_id'     => ['nullable', 'integer'],
            'sub_total'            => ['required', 'numeric'],
            '2307_vt'              => ['nullable', 'numeric', 'min:0'],
            '2307_it'              => ['nullable', 'numeric', 'min:0'],
            'discount_amount'      => ['nullable', 'numeric', 'min:0'],
            'total'                => ['required', 'numeric'],
            'grand_total'          => ['required', 'numeric', 'min:0'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.item_id'      => ['required', 'integer'],
            'lines.*.unit_type_id' => ['nullable', 'integer'],
            'lines.*.qty'          => ['required', 'numeric', 'gt:0'],
            'lines.*.price'        => ['required', 'numeric', 'min:0'],
            'lines.*.discount'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_type'     => ['required', 'in:vatable,exempt,zero,nonvat'],
            'lines.*.account_code' => ['nullable', 'string', 'max:10'],
        ];
    }

    protected function apLineRows(array $lines): array
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
                'item_id'         => $l['item_id'],
                'unit_type_id'    => $l['unit_type_id'] ?? null,
                'quantity'        => $l['qty'],
                'unit_price'      => $l['price'],
                'amount'          => $gross,
                'discount_amount' => $disc,
                'input_vat'       => $vat,
                'net_of_vat'      => $net,
                'vat_exempt'      => $exempt,
                'non_vat'         => $non,
                'zero_rated'      => $zero,
                'account_code'    => $l['account_code'] ?? null,
            ];
        }
        return $rows;
    }

    protected function apTextract(): TextractClient
    {
        $c = config('filesystems.disks.s3');
        return new TextractClient([
            'version'     => 'latest',
            'region'      => $c['region'],
            'credentials' => ['key' => $c['key'], 'secret' => $c['secret']],
            'http'        => ['verify' => $c['http']['verify'] ?? true],
        ]);
    }

    protected function apFolderOf(string $file): string
    {
        return match (strtoupper(substr($file, 0, 1))) {
            'S'     => 'Sales_Invoices',
            'P'     => 'Vendor_Invoices',
            'E'     => 'Expenses_Transaction',
            default => $this->apFolder,
        };
    }

    protected function apScanKey(MobileTransaction $scan): ?string
    {
        $folder = $this->apFolderOf((string) $scan->filename);
        $disk = Storage::disk('s3');
        foreach (array_unique([
            "{$folder}/{$scan->added_by}-{$scan->filename}",
            "{$folder}/{$scan->filename}",
            "{$this->apFolder}/{$scan->added_by}-{$scan->filename}",
            "Scanned_Receipts/{$scan->filename}",
        ]) as $key) {
            if ($disk->exists($key)) return $key;
        }
        return null;
    }

    protected function apAttachmentKeys(object $inv, string $file): array
    {
        $letter = $this->apFolderOf($file);
        $keys = (int) ($inv->is_scanned ?? 0) === 1
            ? ["{$letter}/{$inv->added_by}-{$file}", "{$this->apFolder}/{$inv->id}-{$file}"]
            : ["{$this->apFolder}/{$inv->id}-{$file}", "{$letter}/{$inv->added_by}-{$file}"];
        $keys[] = "{$this->apFolder}/{$file}";
        $keys[] = "Scanned_Receipts/{$file}";
        return array_values(array_unique($keys));
    }

    /* =====================================================================
       GET /api/{module}?from=2026-01&to=2026-09&trans_type=posted|billing|invalid
       ===================================================================== */
    public function apIndex(Request $request)
    {
        $d = $request->validate([
            'from'       => ['required', 'date_format:Y-m'],
            'to'         => ['required', 'date_format:Y-m'],
            'trans_type' => ['nullable', 'in:posted,billing,invalid'],
        ]);
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $q = $this->apBase($addedBy)->whereBetween('declared_month', [$d['from'], $d['to']]);
        $type = $d['trans_type'] ?? null;
        if ($type === 'posted') $q->where(fn ($w) => $w->where('record_status', 'posted')->orWhereNull('record_status'));
        elseif ($type) $q->where('record_status', $type);

        $cols = ['id', 'invoice_no', 'document_no', 'transaction_date', 'declared_month', 'grand_total', 'vendor_id', 'invoice_status_id', 'audit_status_id'];
        if (in_array('invalid_reason', $this->apCols(self::M), true)) $cols[] = 'invalid_reason';
        $list = $q->orderByDesc('id')->get($cols);

        $vendors  = VendorCustomer::whereIn('id', $list->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');
        $statuses = InvoiceStatus::pluck('name', 'id');
        $audits   = AuditStatus::pluck('name', 'id');
        $tickets  = RequestTicket::whereIn('reference_no', $list->pluck('invoice_no'))->where('is_status', 0)->pluck('ticket_no', 'reference_no');
        $scans    = MobileTransaction::where('added_by', $addedBy)->whereIn('reference_no', $list->pluck('invoice_no'))->pluck('trans_status', 'reference_no');

        return response()->json($list->map(fn ($r) => [
            'id'               => $r->id,
            'customer_name'    => trim((string) ($vendors[$r->vendor_id] ?? '')) ?: '—',
            'invoice_no'       => $r->invoice_no,
            'document_no'      => $r->document_no,
            'transaction_date' => $r->transaction_date ? substr((string) $r->transaction_date, 0, 10) : null,
            'declared_month'   => $this->apMonthLabel($r->declared_month),
            'total'            => (float) $r->grand_total,
            'status'           => $statuses[$r->invoice_status_id] ?? '',
            'audit_status'     => $audits[$r->audit_status_id] ?? '',
            'ticket_no'        => $tickets[$r->invoice_no] ?? null,
            'reason'           => $r->invalid_reason ?? null,
            'scan_status'      => $scans[$r->invoice_no] ?? null,
        ])->values());
    }

    /* GET /api/{module}/lookups */
    public function apLookups(Request $request)
    {
        $addedBy = $this->addedByFor($request);

        $vendors = VendorCustomer::where('user_type', 'Vendor')
            ->where('entry_by', $addedBy)
            ->orderBy('name')
            ->get($this->apVendorCols());

        $glCol  = $this->apFirstCol('m_items', ['gl_cost_of_sales_account', 'gl_cost_of_sales_acount', 'gl_inventory_account', 'gl_inventory_acount']);
        $items = DB::table('m_items as I')
            ->leftJoin('m_tax_type as T', 'T.id', '=', 'I.tax_type_id')
            ->leftJoin('m_unit_type as U', 'U.id', '=', 'I.unit_type_id')
            ->where('I.added_by', $addedBy)
            ->orderBy('I.item_code')
            ->get(array_filter([
                'I.id', 'I.item_code', 'I.description', 'I.price', 'I.last_unit_cost', 'I.inventory_qty', 'I.unit_type_id',
                $glCol ? "I.{$glCol} as gl_account" : null,
                DB::raw("COALESCE(T.name, '') as tax_type"),
                DB::raw("COALESCE(U.name, '') as unit"),
            ]))
            ->map(fn ($i) => [
                'id'               => $i->id,
                'item_code'        => $i->item_code,
                'description'      => $i->description,
                // purchases / expenses use the cost
                'price'            => (float) $i->last_unit_cost > 0 ? (float) $i->last_unit_cost : (float) $i->price,
                'inventory_qty'    => $i->inventory_qty,
                'unit_type_id'     => $i->unit_type_id,
                'gl_sales_account' => $i->gl_account ?? null,   // = purchase / expense account
                'tax_type'         => $i->tax_type,
                'unit'             => $i->unit,
            ]);

        $accounts = ChartsOfAccount::where('added_by', $addedBy)
            ->where('root_name', '!=', '0')
            ->orderBy('account_code')
            ->get(['account_code', 'account_name', 'root_name']);

        $lineAccounts = $accounts
            ->filter(fn ($a) => in_array(substr((string) $a->account_code, 0, 1), $this->apAccountPrefix, true))
            ->values();

        return response()->json([
            'customers'        => $vendors,
            'items'            => $items,
            'accounts'         => $accounts,
            'sales_accounts'   => $lineAccounts,
            'locked_months'    => DeclaredMonthSettings::where('ca_id', $this->caidFor($request))->pluck('declared_month'),
            'atc'              => ATC::orderBy('name')->get(['id', 'name', 'description', 'rate', 'category']),
            'document_types'   => DocumentType::whereRaw('CHAR_LENGTH(name) > 2')->orderBy('name')->get(['id', 'name']),
            'invoice_statuses' => InvoiceStatus::orderBy('id')->get(['id', 'name']),
            'audit_statuses'   => AuditStatus::orderBy('id')->get(['id', 'name']),
        ]);
    }

    /* GET /api/{module}/invoice/{invoiceNo} */
    public function apShow(Request $request, string $invoiceNo)
    {
        $addedBy = $this->addedByFor($request);
        $inv = $this->apFind($addedBy, 'invoice_no', $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $lines = DB::table(self::D . ' as L')
            ->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->leftJoin('m_unit_type as U', 'U.id', '=', 'L.unit_type_id')
            ->where('L.invoice_no', $inv->invoice_no)
            ->orderBy('L.id')
            ->get(['L.*', DB::raw("COALESCE(I.item_code, '') as item_code"), DB::raw("COALESCE(I.description, '') as item_description"), DB::raw("COALESCE(U.name, '') as unit")]);

        $invoice = (array) $inv;
        $invoice['customer_id']       = $inv->vendor_id;
        $invoice['trans_type']        = $this->apRecord($inv);        // posted | billing | invalid
        $invoice['declared_month_ym'] = $inv->declared_month;
        $invoice['vt_month_ym']       = $inv->{'2307_vt_declared_month'} ?? null;
        $invoice['it_month_ym']       = $inv->{'2307_it_declared_month'} ?? null;
        $invoice['status_name']       = InvoiceStatus::where('id', $inv->invoice_status_id)->value('name') ?? '';
        $invoice['audit_status_name'] = AuditStatus::where('id', $inv->audit_status_id)->value('name') ?? '';

        $ticket = RequestTicket::where('reference_no', $inv->invoice_no)->where('is_status', 0)
            ->latest('id')->first(['ticket_no', 'remarks', 'action', 'created_at']);

        $scanRec = MobileTransaction::where('reference_no', $inv->invoice_no)->where('added_by', $inv->added_by)
            ->first(['id', 'filename', 'trans_status']);

        return response()->json([
            'invoice'  => $invoice,
            'lines'    => $lines,
            'customer' => $inv->vendor_id ? VendorCustomer::find($inv->vendor_id, $this->apVendorCols()) : null,
            'ticket'   => $ticket,
            'scan'     => $scanRec,
        ]);
    }

    /* GET /api/{module}/ticket/{ticketNo} */
    public function apTicketShow(Request $request, string $ticketNo)
    {
        $addedBy = $this->addedByFor($request);
        $ticket = RequestTicket::where('ticket_no', $ticketNo)->where('request_by', $addedBy)
            ->first(['ticket_no', 'reference_no', 'remarks', 'action', 'is_status', 'created_at']);
        if (!$ticket) return response()->json(['message' => 'Ticket not found.'], 404);

        $data    = TicketData::where('ticket_no', $ticketNo)->first();
        $master  = $data->data_master ?? [];
        $details = collect($data->data_detail ?? []);

        $items = DB::table('m_items')->whereIn('id', $details->pluck('item_id')->filter())->get(['id', 'item_code', 'description'])->keyBy('id');
        $codes = $details->pluck('account_code')->push($master['account_code'] ?? null)->filter()->unique();
        $accNames = ChartsOfAccount::where('added_by', $addedBy)->whereIn('account_code', $codes)->pluck('account_name', 'account_code');
        $vendorId = $master['vendor_id'] ?? $master['customer_id'] ?? null;
        $vendor = $vendorId ? VendorCustomer::find($vendorId, ['id', 'name']) : null;

        return response()->json([
            'ticket'  => $ticket,
            'master'  => $master + ['customer_name' => $vendor->name ?? '—', 'account_name' => $accNames[$master['account_code'] ?? ''] ?? ''],
            'details' => $details->map(fn ($l) => $l + [
                'item_code'    => $items[$l['item_id']]->item_code ?? '',
                'description'  => $items[$l['item_id']]->description ?? '',
                'account_name' => $accNames[$l['account_code'] ?? ''] ?? '',
            ])->values(),
        ]);
    }

    /* GET /api/{module}/invoice/{invoiceNo}/neighbors?from=&to= */
    public function apNeighbors(Request $request, string $invoiceNo)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $addedBy = $this->addedByFor($request);
        $cur = $this->apFind($addedBy, 'invoice_no', $invoiceNo);
        if (!$cur) return response()->json(['prev' => null, 'next' => null]);

        $base = fn () => $this->apBase($addedBy)->whereBetween('declared_month', [$d['from'], $d['to']]);

        $prev = $base()
            ->where(fn ($q) => $q->where('transaction_date', '>', $cur->transaction_date)
                ->orWhere(fn ($q) => $q->where('transaction_date', $cur->transaction_date)->where('id', '>', $cur->id)))
            ->orderBy('transaction_date')->orderBy('id')->value('invoice_no');
        $next = $base()
            ->where(fn ($q) => $q->where('transaction_date', '<', $cur->transaction_date)
                ->orWhere(fn ($q) => $q->where('transaction_date', $cur->transaction_date)->where('id', '<', $cur->id)))
            ->orderByDesc('transaction_date')->orderByDesc('id')->value('invoice_no');

        return response()->json(['prev' => $prev, 'next' => $next]);
    }

    /* POST /api/{module}/{id}/audit */
    public function apAudit(Request $request, int $id)
    {
        $d = $request->validate([
            'audit_status_id' => ['required', 'integer', 'exists:m_audit_status,id'],
            'audit_remarks'   => ['nullable', 'string', 'max:255'],
        ]);
        $inv = $this->apFind($this->addedByFor($request), 'id', $id);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        DB::transaction(function () use ($inv, $d) {
            DB::table(self::M)->where('id', $inv->id)->update($this->apOnly(self::M, [
                'audit_status_id' => $d['audit_status_id'], 'audit_remarks' => $d['audit_remarks'] ?? null, 'updated_at' => now(),
            ]));
            $detail = $this->apOnly(self::D, ['audit_status_id' => $d['audit_status_id']]);
            if ($detail) DB::table(self::D)->where('invoice_no', $inv->invoice_no)->update($detail);
        });
        return response()->json(['ok' => true]);
    }

    /* GET /api/{module}/invoice/{invoiceNo}/journal   (old doGetJournalList, flipped for purchases)
       + PAYMENT (Pay Bill, t_pvoucher): DR payable account · CR cash / bank — shown under PAYMENT, not in the totals (same as Sales → COLLECTION) */
    public function apJournal(Request $request, string $invoiceNo)
    {
        $addedBy = $this->addedByFor($request);
        $inv = $this->apFind($addedBy, 'invoice_no', $invoiceNo);
        if (!$inv || (int) $inv->invoice_status_id === 5) return response()->json(['journal' => [], 'collection' => []]);

        $vatCol = $this->apFirstCol('m_items', ['input_vat_account']);
        $lines = DB::table(self::D . ' as L')
            ->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->where('L.invoice_no', $inv->invoice_no)
            ->get(array_filter(['L.account_code', 'L.input_vat', 'L.net_of_vat', 'L.vat_exempt', 'L.non_vat', 'L.zero_rated', 'L.ex_rate',
                $vatCol ? "I.{$vatCol} as vat_account" : null]));

        // payments made on this bill (Pay Bill)
        $pays = Schema::hasTable('t_pvoucher_detail')
            ? DB::table('t_pvoucher_detail as D')->join('t_pvoucher_master as M', 'M.pvoucher_no', '=', 'D.pvoucher_no')
                ->where('D.ap_invoice_no', $inv->invoice_no)->orderBy('M.pv_date')->orderBy('M.id')
                ->get(['M.pvoucher_no', 'M.pv_date', 'M.bank', 'D.acc_code', 'D.amount'])
            : collect();

        $codes = collect([$inv->account_code])->merge($lines->pluck('account_code'))->merge($lines->pluck('vat_account'))
            ->merge($pays->pluck('bank'))->merge($pays->pluck('acc_code'))->filter()->unique();
        $names = ChartsOfAccount::where('added_by', $addedBy)->whereIn('account_code', $codes)->pluck('account_name', 'account_code');

        $rows = [];
        $add = function (int $sort, $code, string $name, float $dr, float $cr) use (&$rows) {
            $key = $sort . '|' . $code . '|' . ($dr != 0 ? 'D' : 'C');
            $rows[$key] ??= ['sort' => $sort, 'account_code' => (string) $code, 'account_name' => $name, 'debit' => 0.0, 'credit' => 0.0];
            $rows[$key]['debit'] += $dr;
            $rows[$key]['credit'] += $cr;
        };

        // Credit: payable / cash account (grand total) and withholding taxes
        $add(1, $inv->account_code, (string) ($names[$inv->account_code] ?? ''), 0, (float) $inv->grand_total);
        if ((float) ($inv->{'2307_it'} ?? 0) != 0) $add(2, '21910', 'Withholding Tax - Income Tax', 0, (float) $inv->{'2307_it'});
        if ((float) ($inv->{'2307_vt'} ?? 0) != 0) $add(3, '21910', 'Withholding Tax - VAT', 0, (float) $inv->{'2307_vt'});

        $exRate = (float) ($inv->ex_rate ?: 1);
        foreach ($lines as $l) {
            if ((float) $l->input_vat != 0) {           // Debit: input VAT (account from the item settings)
                $code = ($l->vat_account ?? null) ?: '0';
                $add(4, $code, (string) ($names[$code] ?? 'Input VAT'), (float) $l->input_vat * $exRate, 0);
            }
            $amount = ((float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated) * (float) ($l->ex_rate ?: 1);
            $code = $l->account_code ?: '0';           // Debit: purchase / expense account per line
            $add(5, $code, (string) ($names[$code] ?? ''), $amount, 0);
        }

        // PAYMENT: Debit the payable account(s), Credit the cash / bank account(s)
        $payment = [];
        foreach ($pays->groupBy(fn ($p) => $p->acc_code ?: $inv->account_code) as $code => $g) {
            $payment[] = ['account_code' => (string) $code, 'account_name' => (string) ($names[$code] ?? ''), 'debit' => round((float) $g->sum('amount'), 2), 'credit' => 0];
        }
        foreach ($pays->groupBy(fn ($p) => $p->bank ?: '0') as $code => $g) {
            $payment[] = ['account_code' => (string) $code, 'account_name' => (string) ($names[$code] ?? ''), 'debit' => 0, 'credit' => round((float) $g->sum('amount'), 2)];
        }

        return response()->json([
            'journal' => collect($rows)->sortBy([['sort', 'asc'], ['account_code', 'asc']])->values()->map(fn ($r) => [
                'account_code' => $r['account_code'], 'account_name' => $r['account_name'],
                'debit' => round($r['debit'], 2), 'credit' => round($r['credit'], 2),
            ]),
            'collection'       => $payment,   // the popup shows these under the section label
            'collection_label' => 'PAYMENT',
            'payments'         => $pays->map(fn ($p) => ['pvoucher_no' => $p->pvoucher_no, 'date' => substr((string) $p->pv_date, 0, 10), 'amount' => round((float) $p->amount, 2)])->values(),
        ]);
    }

    /* =====================================================================
       POST /api/{module}/{id}/ticket   EDIT / VOID / CANCEL for admin approval (same as Sales)
       ===================================================================== */
    public function apTicket(Request $request, int $id)
    {
        $payload = $request->filled('data') ? (json_decode($request->input('data'), true) ?? []) : $request->except('attachment');
        $d = Validator::make($payload,
            ['ticket_remarks' => ['required', 'string', 'max:1000'], 'action' => ['nullable', 'in:EDIT,VOID,CANCEL']] + $this->apRules(),
            ['ticket_remarks.required' => 'Remarks / Reason is required.']
        )->validate();

        if ($request->hasFile('attachment')) {
            Validator::make(['attachment' => $request->file('attachment')],
                ['attachment' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']])->validate();
        }

        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);
        $inv = $this->apFind($addedBy, 'id', $id);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        if ($msg = $this->lockedMessage($caid, ['Declared Month' => $d['declared_month'],
            '2307 (VT/PT) Declared Month' => $d['vt_month'] ?? null, '2307 (IT) Declared Month' => $d['it_month'] ?? null])) {
            return response()->json(['message' => $msg], 422);
        }
        if (RequestTicket::where('reference_no', $inv->invoice_no)->where('is_status', 0)->exists()) {
            return response()->json(['message' => 'Ticket is pending for this invoice. Please wait for approval.'], 422);
        }

        $ticketNo = 'TCKT' . date('ymd-his') . random_int(10000, 99999);
        $action   = $d['action'] ?? 'EDIT';

        $attachment = $inv->attachment;
        if ($request->hasFile('attachment')) {
            $file  = $request->file('attachment');
            $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
            try {
                Storage::disk('s3')->putFileAs($this->apFolder, $file, $inv->id . '-' . $clean);
                $attachment = $clean;
            } catch (\Throwable $e) {
                report($e);
                return response()->json(['message' => 'Attachment upload failed. Please try again.'], 422);
            }
        }

        $vt = ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null;
        $it = ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null;

        $dataMaster = [
            'table_id'               => $inv->id,
            'invoice_no'             => $inv->invoice_no,
            'transaction_date'       => $d['transaction_date'],
            'vendor_id'              => $d['customer_id'] ?: null,
            'sub_total'              => $this->r4($d['sub_total']),
            'total'                  => $this->r4($d['total']),
            'grand_total'            => $this->r4($d['grand_total']),
            'invoice_status_id'      => $this->apStatusFor($addedBy, $d['account_code'] ?? null, $inv->invoice_status_id, $inv, (float) $d['grand_total']),
            'atc_vt_id'              => $d['atc_vt_id'] ?: null,
            'atc_it_id'              => $d['atc_it_id'] ?: null,
            '2307_vt'                => $this->r4($d['2307_vt'] ?? 0),
            '2307_it'                => $this->r4($d['2307_it'] ?? 0),
            'document_no'            => $d['document_no'] ?? null,
            'document_type_id'       => $d['document_type_id'] ?? null,
            'remarks'                => $d['remarks'] ?? null,
            'declared_month'         => $d['declared_month'],
            'account_code'           => $d['account_code'] ?? null,
            'currency'               => $d['currency'] ?? 'PHP',
            'discount_type_id'       => $d['discount_type_id'] ?? null,
            'discount_amount'        => $this->r4($d['discount_amount'] ?? 0),
            'ex_rate'                => $d['ex_rate'] ?? 1,
            'attachment'             => $attachment,
            '2307_vt_declared_month' => $vt,
            '2307_it_declared_month' => $it,
            'added_by'               => $addedBy,
            'assigned_daid'          => ($inv->assigned_daid ?? 0) ?: null,
            'assigned_auid'          => ($inv->assigned_auid ?? 0) ?: null,
            'trans_type'             => $this->apType,
        ];

        $dataDetail = array_map(fn ($l) => $l + [
            'invoice_no'             => $inv->invoice_no,
            'table_id'               => $inv->id,
            'document_no'            => $dataMaster['document_no'],
            'declared_month'         => $dataMaster['declared_month'],
            'currency'               => $dataMaster['currency'],
            'ex_rate'                => $dataMaster['ex_rate'],
            'invoice_status_id'      => $dataMaster['invoice_status_id'],
            '2307_vt_declared_month' => $vt,
            '2307_it_declared_month' => $it,
        ], $this->apLineRows($d['lines']));

        if ($action === 'VOID') {
            $dataMaster['invoice_status_id'] = 5;
            $dataDetail = array_map(fn ($l) => ['invoice_status_id' => 5] + $l, $dataDetail);
        }
        if ($action === 'CANCEL') {
            foreach (['sub_total', 'total', 'grand_total', 'discount_amount', '2307_vt', '2307_it'] as $k) {
                $dataMaster[$k] = $this->r4(-abs((float) ($dataMaster[$k] ?? 0)));
            }
            $dataDetail = array_map(function ($l) {
                foreach (['amount', 'discount_amount', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated'] as $k) {
                    $l[$k] = $this->r4(-abs((float) ($l[$k] ?? 0)));
                }
                return $l;
            }, $dataDetail);
        }

        DB::transaction(function () use ($inv, $d, $addedBy, $ticketNo, $action, $dataMaster, $dataDetail) {
            RequestTicket::create([
                'ticket_no' => $ticketNo, 'reference_no' => $inv->invoice_no, 'remarks' => $d['ticket_remarks'],
                'module' => $this->apModule, 'action' => $action, 'request_by' => $addedBy, 'is_status' => 0,
            ]);
            TicketData::create(['ticket_no' => $ticketNo, 'data_master' => $dataMaster, 'data_detail' => $dataDetail]);
        });

        return response()->json(['message' => 'Ticket successfully saved', 'ticket_no' => $ticketNo]);
    }

    /* =====================================================================
       POST /api/{module}   new record: SAVE / FOR PAYMENT (billing) / INVALID / PARTIAL INVALID / CANCEL
       ===================================================================== */
    public function apStore(Request $request)
    {
        $d = $request->validate($this->apRules() + [
            'attachment'            => ['nullable', 'string', 'max:255'],
            'mobile_transaction_id' => ['nullable', 'integer'],
            'trans_type'            => ['nullable', 'in:posted,billing,invalid'],
            'scan_action'           => ['nullable', 'in:invalid,partial_invalid,cancel'],
            'invalid_reason'        => ['required_if:scan_action,invalid,partial_invalid', 'nullable', 'string', 'max:1000'],
        ], ['invalid_reason.required_if' => 'Reason is required.']);

        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        if ($this->apLocked($caid, [$d['declared_month'], $d['vt_month'] ?? null, $d['it_month'] ?? null])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        $scanAction = $d['scan_action'] ?? null;
        $record     = $scanAction ? 'invalid' : ($d['trans_type'] ?? 'posted');

        // document number already used (123 vs 123-155-125 …) → supervisor override (doc_override_token)
        if (!$scanAction && ($refuse = $this->docGuard($request, (int) $addedBy, $d['document_no'] ?? null, null))) {
            return $refuse;
        }

        $lineRows = $this->apLineRows($d['lines']);
        $linesNet = array_sum(array_map(fn ($l) => $l['amount'] - $l['discount_amount'], $lineRows));
        $wtaxVt = $this->r4($d['2307_vt'] ?? 0);
        $wtaxIt = $this->r4($d['2307_it'] ?? 0);
        $grand  = $this->r4($d['grand_total']);
        $exRate = (float) ($d['ex_rate'] ?? 1) ?: 1;

        $expected = $this->r4($linesNet * $exRate - ($wtaxVt + $wtaxIt));
        if (abs($expected - $grand) > 0.01) {
            return response()->json(['message' => "Grand total doesn't match the items (expected {$expected}, got {$grand}). Please refresh and try again."], 422);
        }

        $scanStatus = match ($scanAction) {
            'invalid' => 2, 'partial_invalid' => 3, 'cancel' => 5,
            default   => $record === 'billing' ? 4 : 1,
        };
        $scan = !empty($d['mobile_transaction_id'])
            ? MobileTransaction::where('id', $d['mobile_transaction_id'])->where('added_by', $addedBy)->first()
            : null;

        return DB::transaction(function () use ($d, $addedBy, $caid, $lineRows, $wtaxVt, $wtaxIt, $grand, $scan, $record, $scanStatus) {
            $account = $caid ? ClientAccount::find($caid) : null;

            do {
                $invoiceNo = 'AP-' . date('ymd-his') . random_int(10000, 99999);
            } while (DB::table(self::M)->where('invoice_no', $invoiceNo)->exists());

            $statusId = $this->apStatusFor($addedBy, $d['account_code'] ?? null);
            $auditId  = $this->apStatusId(AuditStatus::class, ['PENDING']);
            $vt = ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null;
            $it = ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null;

            $master = [
                'vendor_id'              => $d['customer_id'] ?: null,
                'added_by'               => $addedBy,
                'transaction_date'       => $d['transaction_date'],
                'declared_month'         => $d['declared_month'],
                'account_code'           => $d['account_code'] ?? null,
                'document_no'            => $d['document_no'] ?? null,
                'invoice_no'             => $invoiceNo,
                'currency'               => $d['currency'] ?? 'PHP',
                'ex_rate'                => $d['ex_rate'] ?? 1,
                'sub_total'              => $this->r4($d['sub_total']),
                'total'                  => $this->r4($d['total']),
                'grand_total'            => $grand,
                'discount_amount'        => $this->r4($d['discount_amount'] ?? 0),
                'discount_type_id'       => $d['discount_type_id'] ?? null,
                'atc_vt_id'              => $d['atc_vt_id'] ?: null,
                'atc_it_id'              => $d['atc_it_id'] ?: null,
                '2307_vt'                => $wtaxVt,
                '2307_it'                => $wtaxIt,
                '2307_vt_declared_month' => $vt,
                '2307_it_declared_month' => $it,
                'remarks'                => $d['remarks'] ?? null,
                'invalid_reason'         => $d['invalid_reason'] ?? null,
                'attachment'             => $scan ? $scan->filename : null,
                'is_scanned'             => $scan ? 1 : 0,
                'document_type_id'       => $d['document_type_id'] ?? null,
                'invoice_status_id'      => $statusId,
                'audit_status_id'        => $auditId,
                'assigned_daid'          => ($account->assigned_daid ?? 0) ?: null,
                'assigned_auid'          => ($account->assigned_auid ?? 0) ?: null,
                'seq_no'                 => (int) DB::table(self::M)->where('added_by', $addedBy)->lockForUpdate()->max('seq_no') + 1,
                'trans_type'             => $this->apType,
                'record_status'          => $record,
                'created_at'             => now(),
                'updated_at'             => now(),
            ];
            $id = DB::table(self::M)->insertGetId($this->apOnly(self::M, $master));

            foreach ($lineRows as $l) {
                DB::table(self::D)->insert($this->apOnly(self::D, $l + [
                    'invoice_no'             => $invoiceNo,
                    'document_no'            => $master['document_no'],
                    'declared_month'         => $master['declared_month'],
                    'currency'               => $master['currency'],
                    'ex_rate'                => $master['ex_rate'],
                    '2307_vt_declared_month' => $vt,
                    '2307_it_declared_month' => $it,
                    'added_by'               => $addedBy,
                    'vendor_id'              => $master['vendor_id'],
                    'transaction_date'       => $master['transaction_date'],
                    'invoice_status_id'      => $statusId,
                    'audit_status_id'        => $auditId,
                    'trans_type'             => $this->apType,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]));
            }

            if ($scan) $scan->update(['trans_status' => $scanStatus, 'reference_no' => $invoiceNo]);

            return response()->json(['id' => $id, 'invoice_no' => $invoiceNo]);
        });
    }

    /* POST /api/{module}/{id}/attachment */
    public function apUploadAttachment(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']]);
        $inv = $this->apFind($this->addedByFor($request), 'id', $id);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $file  = $request->file('file');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
        Storage::disk('s3')->putFileAs($this->apFolder, $file, $inv->id . '-' . $clean);
        DB::table(self::M)->where('id', $inv->id)->update(['attachment' => $clean, 'updated_at' => now()]);

        return response()->json(['attachment' => $clean]);
    }

    /* GET /api/{module}/{id}/attachment */
    public function apAttachment(Request $request, int $id)
    {
        $inv  = $this->apFind($this->addedByFor($request), 'id', $id);
        $file = trim((string) ($inv->attachment ?? ''));
        if (!$inv || $file === '') return response()->json(['message' => 'No attachment for this invoice.'], 404);
        if (preg_match('/^https?:\/\//i', $file)) return response()->json(['url' => $file]);

        $disk = Storage::disk('s3');
        foreach ($this->apAttachmentKeys($inv, $file) as $key) {
            if ($disk->exists($key)) {
                $mime = $disk->mimeType($key) ?: 'application/octet-stream';
                return response()->stream(function () use ($disk, $key) {
                    $stream = $disk->readStream($key);
                    fpassthru($stream);
                    fclose($stream);
                }, 200, [
                    'Content-Type'        => $mime,
                    'Content-Disposition' => 'inline; filename="' . addslashes($file) . '"',
                    'Cache-Control'       => 'private, max-age=300',
                ]);
            }
        }
        return response()->json(['message' => 'File not found on storage.'], 404);
    }

    /* GET /api/{module}/blank-attachment */
    public function apBlankAttachment()
    {
        $disk = Storage::disk('s3');
        foreach (["{$this->apFolder}/0-blank.pdf", "{$this->apFolder}/blank.pdf", 'Sales_Invoices/0-blank.pdf', 'Sales_Invoices/blank.pdf', 'blank.pdf'] as $key) {
            if ($disk->exists($key)) return $disk->response($key, 'blank.pdf', ['Content-Type' => 'application/pdf']);
        }
        return response()->json(['message' => 'blank.pdf not found'], 404);
    }

    /* POST /api/{module}/{id}/post   Partially Invalid -> posted */
    public function apPost(Request $request, int $id)
    {
        $inv = $this->apFind($this->addedByFor($request), 'id', $id);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);
        if ($this->apRecord($inv) !== 'invalid') return response()->json(['message' => 'Only invalid records can be posted.'], 422);
        if ($this->apLocked($this->caidFor($request), [$inv->declared_month])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        DB::table(self::M)->where('id', $inv->id)->update(['record_status' => 'posted', 'updated_at' => now()]);
        return response()->json(['message' => "{$inv->invoice_no} posted"]);
    }

    /* POST /api/{module}/override   supervisor moves records to another declared month */
    public function apOverride(Request $request)
    {
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'],
            'declared_month' => ['required', 'date_format:Y-m'], 'remarks' => ['nullable', 'string', 'max:1000'],
            'username' => ['required', 'string'], 'password' => ['required', 'string'],
        ], [
            'declared_month.required' => 'Declared Month is required',
            'username.required' => 'Username is required',
            'password.required' => 'Password is required',
        ]);

        $addedBy = $this->addedByFor($request);
        if ($this->apLocked($this->caidFor($request), [$d['declared_month']])) {
            return response()->json(['message' => Carbon::createFromFormat('Y-m', $d['declared_month'])->format('F Y') . ' is locked'], 422);
        }

        $supervisor = UserStaff::where('username', $d['username'])->where('user_type_id', 10)->where('is_active', 1)->first(['id', 'password']);
        if (!$supervisor || !\Illuminate\Support\Facades\Hash::check((string) $d['password'], (string) $supervisor->getRawOriginal('password'))) {
            return response()->json(['message' => 'User not found'], 422);
        }

        $invoices = $this->apBase($addedBy)->whereIn('id', $d['ids'])->get();
        if ($invoices->isEmpty()) return response()->json(['message' => 'No invoices found.'], 404);

        $ticketNo = 'TCKT' . date('ymd-his') . random_int(10000, 99999);
        DB::transaction(function () use ($invoices, $d, $addedBy, $ticketNo) {
            foreach ($invoices as $inv) {
                $details = DB::table(self::D)->where('invoice_no', $inv->invoice_no)->get();
                RequestTicket::create([
                    'ticket_no' => $ticketNo, 'reference_no' => $inv->invoice_no, 'remarks' => $d['remarks'] ?? null,
                    'module' => $this->apModule, 'action' => 'UNDECLARED', 'request_by' => $addedBy, 'is_status' => 1,
                ]);
                TicketHistory::create([
                    'ticket_no' => $ticketNo, 'reference_no' => $inv->invoice_no,
                    'table_data' => ['master' => (array) $inv, 'details' => $details->map(fn ($x) => (array) $x)->all()],
                ]);
                DB::table(self::M)->where('id', $inv->id)->update(['declared_month' => $d['declared_month'], 'updated_at' => now()]);
                DB::table(self::D)->where('invoice_no', $inv->invoice_no)->update(['declared_month' => $d['declared_month']]);
            }
        });

        return response()->json(['message' => 'Invoices successfully updated', 'updated' => $invoices->count(), 'ticket_no' => $ticketNo]);
    }

    /* ================= SCANNED RECEIPTS ================= */

    /* GET /api/{module}/scanned */
    public function apScanned(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $rows = MobileTransaction::where('added_by', $addedBy)
            ->whereRaw('LOWER(trans_type) = ?', [$this->apScanType])
            ->whereNotIn('trans_status', [2, 3, 4, 5])
            ->orderByDesc('id')
            ->get(['id', 'filename', 'reference_no', 'trans_status', 'created_at', 'updated_at']);

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'filename' => $r->filename, 'reference_no' => $r->reference_no, 'trans_status' => $r->trans_status,
            'date_added' => $r->created_at?->format('Y-m-d H:i:s'), 'date_updated' => $r->updated_at?->format('Y-m-d H:i:s'),
        ]));
    }

    /* GET /api/{module}/scanned/{id}/file */
    public function apScannedFile(Request $request, int $id)
    {
        $scan = MobileTransaction::where('id', $id)->where('added_by', $this->addedByFor($request))->first(['id', 'filename', 'added_by']);
        if (!$scan) return response()->json(['message' => 'File not found.'], 404);
        $key = $this->apScanKey($scan);
        if (!$key) return response()->json(['message' => 'File not found in storage.'], 404);
        return Storage::disk('s3')->response($key, $scan->filename);
    }

    /* POST /api/{module}/scanned/{id}/read   (old read_pdf.php) */
    public function apReadScan(Request $request, int $id)
    {
        $addedBy = $this->addedByFor($request);
        $scan = MobileTransaction::where('id', $id)->where('added_by', $addedBy)->first();
        if (!$scan) return response()->json(['message' => 'Receipt not found.'], 404);
        if ($scan->reference_no) return response()->json(['reference_no' => $scan->reference_no]);

        $key = \Illuminate\Support\Facades\Cache::has("scan-text:{$scan->id}") ? 'cached' : $this->apScanKey($scan);   // no S3 lookups when already read
        if (!$key) return response()->json(['message' => 'File not found in storage.'], 404);

        try {
            // read once, then remembered 7 days: Scan again (or the list's pre-read) answers instantly
            $text = \Illuminate\Support\Facades\Cache::remember("scan-text:{$scan->id}", now()->addDays(7), fn () => $this->scanText($this->apTextract(), $key));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Scanning failed: ' . $e->getMessage()], 422);
        }

        preg_match_all('/\b\d{3}-?\d{3}-?\d{3}\b/', $text, $m);
        $tins = array_values(array_unique($m[0] ?? []));

        $names = [];
        foreach (['Company Name', 'Store Name', 'Supplier', 'Vendor', 'Sold By', 'Seller', 'Merchant', 'Business', 'Registered Name'] as $kw) {
            if (preg_match_all('/' . preg_quote($kw, '/') . '\s*[:\-]?\s*([\w\s&.,\-]{2,50})/i', $text, $mm)) {
                foreach ($mm[1] as $n) { $n = trim(strtok($n, "\n")); if ($n !== '') $names[] = $n; }
            }
        }
        $names = array_values(array_unique($names));

        $docNos = [];
        foreach (['/Invoice\s*No\.?\s*[:\-]?\s*([\w\-]+)/i', '/Document\s*No\.?\s*[:\-]?\s*([\w\-]+)/i', '/Invoice\s*#\s*[:\-]?\s*([\w\-]+)/i',
                  '/OR\s*No\.?\s*[:\-]?\s*([\w\-]+)/i', '/SI\s*No\.?\s*[:\-]?\s*([\w\-]+)/i'] as $p) {
            if (preg_match_all($p, $text, $mm)) foreach ($mm[1] as $n) if (trim($n) !== '') $docNos[] = trim($n);
        }

        $vendors = collect();
        if ($tins || $names) {
            $vendors = VendorCustomer::where('user_type', 'Vendor')->where('entry_by', $addedBy)
                ->where(function ($q) use ($tins, $names) {
                    foreach ($tins as $t)  $q->orWhereRaw("REPLACE(tin, '-', '') LIKE ?", ['%' . str_replace('-', '', $t) . '%']);
                    foreach ($names as $n) $q->orWhere('name', 'like', '%' . $n . '%');
                })
                ->limit(10)->get(['id', 'name', 'tin']);
        }

        $defaultItemId = DB::table('m_items')->where('added_by', $addedBy)
            ->whereRaw('UPPER(TRIM(description)) REGEXP ?', [$this->apDefaultItem])->value('id');

        return response()->json([
            'scan_id'         => $scan->id,
            'filename'        => $scan->filename,
            'document_no'     => implode('-', array_unique($docNos)),
            'customer_id'     => $vendors->count() === 1 ? $vendors->first()->id : null,   // = vendor
            'customers'       => $vendors,
            'tins'            => $tins,
            'names'           => $names,
            'default_item_id' => $defaultItemId,
            'raw_text'        => mb_substr($text, 0, 500),
        ]);
    }

    /* POST /api/{module}/scanned/{id}/transfer */
    public function apTransferScan(Request $request, int $id)
    {
        $d = $request->validate(['trans_type' => ['required', 'in:' . implode(',', $this->apTransferTo)]]);
        $scan = MobileTransaction::where('id', $id)->where('added_by', $this->addedByFor($request))->where('trans_status', 0)->first();
        if (!$scan) return response()->json(['message' => 'Receipt not found or already processed.'], 404);

        $scan->update(['trans_type' => $d['trans_type']]);
        return response()->json(['message' => 'Receipt transferred.']);
    }
}