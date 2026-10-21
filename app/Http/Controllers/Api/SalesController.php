<?php

namespace App\Http\Controllers\Api;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\UserClient;
use App\Models\UserClientStaff;
use App\Models\TicketHistory;
use App\Models\UserStaff;
use App\Models\SalesInvoiceMaster;
use App\Models\SalesInvoiceDetail;
use App\Models\ChartsOfAccount;
use App\Models\ATC;
use App\Models\AuditStatus;
use App\Models\DocumentType;
use App\Models\InvoiceStatus;
use App\Models\Items;
use App\Models\VendorCustomer;
use App\Models\ClientAccount;
use App\Models\MobileTransaction;
use App\Models\RequestTicket;
use App\Models\DeclaredMonthSettings;
use App\Models\TicketData;
use App\Models\RVoucherMaster;
use App\Models\RVoucherDetail;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Filesystem\FilesystemAdapter;
class SalesController extends Controller
{
    /* =====================================================================
       Helpers: who is logged in, which account, which added_by
       ===================================================================== */

    // caid stored on the token when a branch is selected
    private function tokenCaid(Request $request): ?int
    {
        return collect($request->user()->currentAccessToken()->abilities ?? [])
            ->map(fn ($a) => str_starts_with($a, 'caid:') ? (int) substr($a, 5) : null)
            ->filter()
            ->first();
    }

    protected  function caidFor(Request $request): ?int
    {
        $user = $request->user();
        if ($user instanceof UserClient || $user instanceof UserClientStaff) return (int) $user->caid ?: null;
        if ($user instanceof UserStaff) return $this->tokenCaid($request);
        return null;
    }

    protected function addedByFor(Request $request): ?int
    {
        $user = $request->user();
        if ($user instanceof UserClient) return (int) $user->id;
        if ($user instanceof UserClientStaff) return (int) $user->user_client_id;
        if ($user instanceof UserStaff) {
            $caid = $this->tokenCaid($request);
            return $caid ? (int) DB::table('m_user_client')->where('caid', $caid)->min('id') : null;
        }
        return null;
    }
    // First status whose name starts with one of $names
    private function statusId(string $table, array $names): ?int
    {
        foreach ($names as $n) {
            $id = DB::table($table)->whereRaw('UPPER(name) LIKE ?', [strtoupper($n) . '%'])->value('id');
            if ($id) return (int) $id;
        }
        return null;
    }

    protected function r4($n): float
    {
        return round((float) $n, 4);
    }
    /* =====================================================================
    GET /api/sales?from=2026-02&to=2026-09  (list)
    ===================================================================== */
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to' => ['required', 'date_format:Y-m'],
            'trans_type' => ['nullable', 'string', 'max:20'],
        ]);

        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $list = SalesInvoiceMaster::query()
            ->with([
                'customer:id,name',
                'invoiceStatus:id,name',
                'auditStatus:id,name',
            ])
            ->where('added_by', $addedBy)
            ->when($data['trans_type'] ?? null, fn ($q, $type) => $q->where('trans_type', $type))
            ->whereBetween('declared_month', [$data['from'], $data['to']])
            ->orderByDesc('id')
            ->get([
                'id', 'invoice_no', 'document_no', 'transaction_date', 'declared_month', 'grand_total',
                'customer_id', 'invoice_status_id', 'audit_status_id', 'invalid_reason',
            ]);

        // pending tickets for these invoices: invoice_no => ticket_no
        $tickets = RequestTicket::whereIn('reference_no', $list->pluck('invoice_no'))
            ->where('is_status', 0)
            ->pluck('ticket_no', 'reference_no');

        // receipt status (2 Invalid / 3 Partially Invalid / 5 Cancel) by invoice no
        $scanStatus = MobileTransaction::where('added_by', $addedBy)
            ->whereIn('reference_no', $list->pluck('invoice_no'))
            ->pluck('trans_status', 'reference_no');

        $rows = $list->map(fn ($r) => [
            'id'               => $r->id,
            'customer_name'    => trim((string) ($r->customer->name ?? '')) ?: 'WALK IN',
            'invoice_no'       => $r->invoice_no,
            'document_no'      => $r->document_no,
            'transaction_date' => $r->transaction_date?->format('Y-m-d'),
            'declared_month'   => $this->monthLabel($r->declared_month),
            'total'            => $r->grand_total,
            'status'           => $r->invoiceStatus->name ?? '',
            'audit_status'     => $r->auditStatus->name ?? '',
            'ticket_no'        => $tickets[$r->invoice_no] ?? null,
            'reason'           => $r->invalid_reason,
            'scan_status'      => $scanStatus[$r->invoice_no] ?? null,
        ]);

        return response()->json($rows);
    }

    // "2026-09" -> "September 2026"
    private function monthLabel(?string $ym): string{
        if (!$ym || !preg_match('/^\d{4}-\d{2}$/', $ym)) return (string) $ym;
        return Carbon::createFromFormat('Y-m-d', $ym . '-01')->format('F Y');
    }

    /* =====================================================================
       GET /api/sales/lookups  (dropdown lists for the invoice page)
       ===================================================================== */
    public function lookups(Request $request){
        $addedBy = $this->addedByFor($request);

        // Customers entered by this user
        $customers = VendorCustomer::query()
            ->where('user_type', 'Customer')
            ->where('entry_by', $addedBy)
            ->orderBy('name')
            ->get(['id', 'name', 'tin', 'branch_code', 'address', 'city_country']);

        // Items with tax type + unit name (one query, joins instead of extra lookups)
        // Items + tax type + unit (eager loaded: 3 small queries, no N+1)
        $items = Items::query()
            ->with(['taxType:id,name', 'unitType:id,name'])
            ->where('added_by', $addedBy)
            ->orderBy('item_code')
            ->get([
                'id', 'item_code', 'description', 'price', 'inventory_qty',
                'unit_type_id', 'tax_type_id', 'gl_sales_account',
            ])
            ->map(fn ($i) => [
                'id'               => $i->id,
                'item_code'        => $i->item_code,
                'description'      => $i->description,
                'price'            => $i->price,
                'inventory_qty'    => $i->inventory_qty,
                'unit_type_id'     => $i->unit_type_id,
                'gl_sales_account' => $i->gl_sales_account,
                'tax_type'         => $i->taxType->name ?? '',
                'unit'             => $i->unitType->name ?? '',
            ]);

        // Chart of accounts: ONE query, then split in PHP
        $accounts = ChartsOfAccount::query()
            ->where('added_by', $addedBy)
            ->where('root_name', '!=', '0')
            ->orderBy('account_code')
            ->get(['account_code', 'account_name', 'root_name']);

        $salesAccounts = $accounts
            ->filter(fn ($a) => str_starts_with((string) $a->account_code, '4'))
            ->values();

        // Fixed lists: same for everyone, cached for 24 hours
        $fixed = Cache::remember('sales-lookups-fixed', now()->addDay(), fn () => [
            'atc'              => ATC::orderBy('name')->get(['id', 'name', 'description', 'rate', 'category']),
            'document_types'   => DocumentType::whereRaw('CHAR_LENGTH(name) > 2')->orderBy('name')->get(['id', 'name']),
            'invoice_statuses' => InvoiceStatus::orderBy('id')->get(['id', 'name']),
            'audit_statuses'   => AuditStatus::orderBy('id')->get(['id', 'name']),
        ]);

        return response()->json([
            'customers'      => $customers,
            'items'          => $items,
            'accounts'       => $accounts,
            'sales_accounts' => $salesAccounts,
            'locked_months'  => DeclaredMonthSettings::where('ca_id', $this->caidFor($request))->pluck('declared_month'),
        ] + $fixed);
    }
    /* =====================================================================
    GET /api/sales/invoice/{invoiceNo}  (one invoice with its lines)
    ===================================================================== */
    public function show(Request $request, string $invoiceNo){
        $inv = SalesInvoiceMaster::query()
            ->with([
                'invoiceStatus:id,name',
                'auditStatus:id,name',
                'customer:id,name,tin,branch_code,address,city_country',
                'details' => fn ($q) => $q->orderBy('id'),
                'details.item:id,item_code,description',
                'details.unitType:id,name',
            ])
            ->where('invoice_no', $invoiceNo)
            ->where('added_by', $this->addedByFor($request))
            ->first();

        if (!$inv) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        // Invoice: all columns (with casts) + the extra names the form uses
        $invoice = $inv->attributesToArray() + [
            'declared_month_ym' => $inv->declared_month,
            'vt_month_ym'       => $inv->{'2307_vt_declared_month'},
            'it_month_ym'       => $inv->{'2307_it_declared_month'},
            'status_name'       => $inv->invoiceStatus->name ?? '',
            'audit_status_name' => $inv->auditStatus->name ?? '',
        ];

        // Lines: all columns + item code / description / unit name
        $lines = $inv->details->map(fn ($l) => $l->attributesToArray() + [
            'item_code'        => $l->item->item_code ?? '',
            'item_description' => $l->item->description ?? '',
            'unit'             => $l->unitType->name ?? '',
        ]);

        $ticket = RequestTicket::where('reference_no', $inv->invoice_no)
            ->where('is_status', 0)
            ->latest('id')
            ->first(['ticket_no', 'remarks', 'action', 'created_at']);

        // the scanned receipt this invoice came from (if any)
        $scanRec = MobileTransaction::where('reference_no', $inv->invoice_no)
            ->where('added_by', $inv->added_by)
            ->first(['id', 'filename', 'trans_status']);

        return response()->json([
            'invoice'  => $invoice,
            'lines'    => $lines,
            'customer' => $inv->customer,
            'ticket'   => $ticket,
            'scan'     => $scanRec,
        ]);
    }
        /* GET /api/sales/ticket/{ticketNo}  – pending ticket with proposed data */
    public function ticketShow(Request $request, string $ticketNo)
    {
        $addedBy = $this->addedByFor($request);

        $ticket = RequestTicket::where('ticket_no', $ticketNo)
            ->where('request_by', $addedBy)
            ->first(['ticket_no', 'reference_no', 'remarks', 'action', 'is_status', 'created_at']);
        if (!$ticket) return response()->json(['message' => 'Ticket not found.'], 404);

        $data    = TicketData::where('ticket_no', $ticketNo)->first();
        $master  = $data->data_master ?? [];
        $details = collect($data->data_detail ?? []);

        $items = Items::whereIn('id', $details->pluck('item_id')->filter())
            ->get(['id', 'item_code', 'description'])
            ->keyBy('id');

        $codes = $details->pluck('account_code')->push($master['account_code'] ?? null)->filter()->unique();
        $accNames = ChartsOfAccount::where('added_by', $addedBy)
            ->whereIn('account_code', $codes)
            ->pluck('account_name', 'account_code');

        $customer = !empty($master['customer_id'])
            ? VendorCustomer::find($master['customer_id'], ['id', 'name'])
            : null;

        return response()->json([
            'ticket'  => $ticket,
            'master'  => $master + [
                'customer_name' => $customer->name ?? 'WALK IN',
                'account_name'  => $accNames[$master['account_code'] ?? ''] ?? '',
            ],
            'details' => $details->map(fn ($l) => $l + [
                'item_code'    => $items[$l['item_id']]->item_code ?? '',
                'description'  => $items[$l['item_id']]->description ?? '',
                'account_name' => $accNames[$l['account_code'] ?? ''] ?? '',
            ])->values(),
        ]);
    }
    /* =====================================================================
    GET /api/sales/invoice/{invoiceNo}/neighbors?from=2026-02&to=2026-09
    Previous / next invoice in the same order as the Sales list
    ===================================================================== */
    public function neighbors(Request $request, string $invoiceNo)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to'   => ['required', 'date_format:Y-m'],
        ]);
        $addedBy = $this->addedByFor($request);

        $cur = DB::table('t_sales_invoice_master')
            ->where('invoice_no', $invoiceNo)->where('added_by', $addedBy)
            ->select('id', 'transaction_date')->first();
        if (!$cur) return response()->json(['prev' => null, 'next' => null]);

        $base = fn () => DB::table('t_sales_invoice_master')
            ->where('added_by', $addedBy)
            ->whereBetween('declared_month', [$data['from'], $data['to']]);

        // list is newest first: "previous" = the row above (newer)
        $prev = $base()
            ->where(function ($q) use ($cur) {
                $q->where('transaction_date', '>', $cur->transaction_date)
                ->orWhere(fn ($q) => $q->where('transaction_date', $cur->transaction_date)->where('id', '>', $cur->id));
            })
            ->orderBy('transaction_date')->orderBy('id')
            ->value('invoice_no');

        // "next" = the row below (older)
        $next = $base()
            ->where(function ($q) use ($cur) {
                $q->where('transaction_date', '<', $cur->transaction_date)
                ->orWhere(fn ($q) => $q->where('transaction_date', $cur->transaction_date)->where('id', '<', $cur->id));
            })
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->value('invoice_no');

        return response()->json(['prev' => $prev, 'next' => $next]);
    }

    /* =====================================================================
    POST /api/sales/{id}/audit  { "audit_status_id": 2, "audit_remarks": "..." }
    ===================================================================== */
    public function audit(Request $request, int $id)
    {
        $d = $request->validate([
            'audit_status_id' => ['required', 'integer', 'exists:m_audit_status,id'],
            'audit_remarks'   => ['nullable', 'string', 'max:255'],
        ]);
        $addedBy = $this->addedByFor($request);

        $inv = DB::table('t_sales_invoice_master')->where('id', $id)->where('added_by', $addedBy)->first();
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        DB::transaction(function () use ($inv, $d) {
            DB::table('t_sales_invoice_master')->where('id', $inv->id)->update([
                'audit_status_id' => $d['audit_status_id'],
                'audit_remarks'   => $d['audit_remarks'] ?? null,
                'updated_at'      => now(),
            ]);
            DB::table('t_sales_invoice_detail')->where('invoice_no', $inv->invoice_no)->update([
                'audit_status_id' => $d['audit_status_id'],
                'updated_at'      => now(),
            ]);
        });

        return response()->json(['ok' => true]);
    }
    /* =====================================================================
   GET /api/sales/invoice/{invoiceNo}/journal   (same as doGetJournalList)
   3 queries total: master, lines (+ item output VAT account), account names
   ===================================================================== */
    public function journal(Request $request, string $invoiceNo){
        $userId = $this->addedByFor($request);
        if (!$userId) return response()->json([]);

        // 1) Master – once
        $inv = SalesInvoiceMaster::query()
            ->where('added_by', $userId)
            ->where('invoice_no', $invoiceNo)
            ->where('invoice_status_id', '<>', 5)
            ->first(['id', 'invoice_no', 'account_code', 'grand_total', 'ex_rate', '2307_it', '2307_vt']);

        if (!$inv) return response()->json([]);

        // 2) Lines + the item's output VAT account – once
        $lines = SalesInvoiceDetail::query()
            ->leftJoin('m_items as I', 'I.id', '=', 't_sales_invoice_detail.item_id')
            ->where('t_sales_invoice_detail.invoice_no', $inv->invoice_no)
            ->get([
                't_sales_invoice_detail.account_code',
                't_sales_invoice_detail.input_vat',
                't_sales_invoice_detail.net_of_vat',
                't_sales_invoice_detail.vat_exempt',
                't_sales_invoice_detail.non_vat',
                't_sales_invoice_detail.zero_rated',
                't_sales_invoice_detail.ex_rate',
                'I.output_vat_account',
            ]);

        $rvs = RVoucherMaster::where('added_by', $userId)
            ->where('invoice_no', $inv->invoice_no)
            ->orderBy('id')
            ->get(['id', 'bank', 'sub_total', 'atc_it_id', 'atc_vt_id', '2307_it', '2307_vt']);

        $rvLines = RVoucherDetail::where('added_by', $userId)
            ->where('invoice_no', $inv->invoice_no)
            ->get(['account_code', 'sub_total']);

        $atcs = ATC::whereIn('id', $rvs->pluck('atc_it_id')->merge($rvs->pluck('atc_vt_id'))->filter()->unique())
            ->get(['id', 'name', 'rate'])
            ->keyBy('id');

        // 3) Account names for every code we need – once
        $codes = collect([$inv->account_code])
            ->merge($lines->pluck('account_code'))
            ->merge($lines->pluck('output_vat_account'))
            ->merge($rvs->pluck('bank'))
            ->merge($rvLines->pluck('account_code'))
            ->filter()->unique()->values();

        $names = $codes->isEmpty()
            ? collect()
            : ChartsOfAccount::query()
                ->where('added_by', $userId)
                ->whereIn('account_code', $codes)
                ->pluck('account_name', 'account_code');

        // ---- build the entry (same rules and order as the old UNION) ----
        $rows = [];
        $add = function (int $sort, $code, string $name, float $dr, float $cr) use (&$rows) {
            $key = $sort . '|' . $code . '|' . ($dr != 0 ? 'D' : 'C');
            $rows[$key] ??= ['sort' => $sort, 'account_code' => (string) $code, 'account_name' => $name, 'debit' => 0.0, 'credit' => 0.0];
            $rows[$key]['debit']  += $dr;
            $rows[$key]['credit'] += $cr;
        };

        // Debit: customer account (grand total)
        $add(1, $inv->account_code, (string) ($names[$inv->account_code] ?? ''), (float) $inv->grand_total, 0);

        // Debit: withholding taxes
        if ((float) $inv->{'2307_it'} != 0) $add(2, '21910', 'Withholding Tax - Income Tax', (float) $inv->{'2307_it'}, 0);
        if ((float) $inv->{'2307_vt'} != 0) $add(3, '21910', 'Withholding Tax - VAT', (float) $inv->{'2307_vt'}, 0);

        foreach ($lines as $l) {
            $rate = (float) ($l->ex_rate ?: 1);

            // Credit: output VAT (account from the item settings)
            if ((float) $l->input_vat != 0) {
                $code = $l->output_vat_account ?: '0';
                $add(4, $code, (string) ($names[$code] ?? 'No Account Settings'), 0, (float) $l->input_vat * (float) ($inv->ex_rate ?: 1));
            }

            // Credit: sales account per line
            $amount = ((float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated) * $rate;
            $code = $l->account_code ?: '0';
            $add(5, $code, (string) ($names[$code] ?? ''), 0, $amount);
        }

        $list = collect($rows)
            ->sortBy([['sort', 'asc'], ['account_code', 'asc']])
            ->values()
            ->map(fn ($r) => [
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
                'debit'        => round($r['debit'], 2),
                'credit'       => round($r['credit'], 2),
            ]);

        // ---- collection rows (shown under COLLECTION, not in the totals) ----
        $atcLabel = function ($id) use ($atcs) {
            $a = $atcs[$id] ?? null;
            if (!$a) return 'Withholding Tax - Receivable';
            $rate = (float) $a->rate;
            $pct  = $rate > 1 ? $rate : $rate * 100; // stored as 0.01 or 1
            return 'Withholding Tax - Receivable - ' . $a->name . ' ' . round($pct) . '%';
        };

        $collection = [];
        foreach ($rvs as $rv) {
            // Debit: bank / cash account
            $collection[] = [
                'account_code' => (string) $rv->bank,
                'account_name' => (string) ($names[$rv->bank] ?? ''),
                'debit'        => round((float) $rv->sub_total, 2),
                'credit'       => 0,
            ];
            // Debit: withholding (IT / VT)
            if ((float) $rv->{'2307_it'} > 0) {
                $collection[] = ['account_code' => '21910', 'account_name' => $atcLabel($rv->atc_it_id), 'debit' => round((float) $rv->{'2307_it'}, 2), 'credit' => 0];
            }
            if ((float) $rv->{'2307_vt'} > 0) {
                $collection[] = ['account_code' => '21910', 'account_name' => $atcLabel($rv->atc_vt_id), 'debit' => round((float) $rv->{'2307_vt'}, 2), 'credit' => 0];
            }
        }
        // Credit: accounts from the collection lines (usually Accounts Receivable)
        $byCode = [];
        foreach ($rvLines as $l) {
            $code = $l->account_code ?: '0';
            $byCode[$code] = ($byCode[$code] ?? 0.0) + (float) $l->sub_total;
        }
        foreach ($byCode as $code => $total) {
            $collection[] = [
                'account_code' => (string) $code,
                'account_name' => (string) ($names[$code] ?? ''),
                'debit'        => 0,
                'credit'       => round($total, 2),
            ];
        }

        return response()->json([
            'journal'    => $list,
            'collection' => $collection,
        ]);
    }
    /* =====================================================================
       POST /api/sales/{id}/ticket   (same as save_transaction_ticket.php)
       t_request_ticket : the ticket
       t_ticket_data    : proposed master + details (JSON)
       The invoice itself is NOT changed until the ticket is approved.
       ===================================================================== */
    public function requestTicket(Request $request, int $id){
        // attachment is OPTIONAL
        // Body: FormData ("data" = JSON) or plain JSON — both work
        $payload = $request->filled('data')
            ? (json_decode($request->input('data'), true) ?? [])
            : $request->except('attachment');

        $d = Validator::make(
            $payload,
            [
                'ticket_remarks' => ['required', 'string', 'max:1000'],
                'action'         => ['nullable', 'in:EDIT,VOID,CANCEL'],
            ] + $this->invoiceRules(),
            ['ticket_remarks.required' => 'Remarks / Reason is required.']
        )->validate();

        // Attachment: only checked when a real file was sent
        if ($request->hasFile('attachment')) {
            Validator::make(
                ['attachment' => $request->file('attachment')],
                ['attachment' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']]
            )->validate();
        }

        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);

        $inv = SalesInvoiceMaster::where('id', $id)
            ->where('added_by', $addedBy)
            ->first(['id', 'invoice_no', 'declared_month', 'invoice_status_id', 'attachment', 'assigned_daid', 'assigned_auid']);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        if ($this->lockedMonth($caid, [$inv->declared_month, $d['declared_month'], $d['vt_month'] ?? null, $d['it_month'] ?? null])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        if (RequestTicket::where('reference_no', $inv->invoice_no)->where('is_status', 0)->exists()) {
            return response()->json(['message' => 'Ticket is pending for this invoice. Please wait for approval.'], 422);
        }

        $ticketNo = 'TCKT' . date('ymd-his') . random_int(10000, 99999);
        $action = $d['action'] ?? 'EDIT';

        // no file → keep current attachment
        $attachment = $inv->attachment;
        if ($request->hasFile('attachment')) {
            $file  = $request->file('attachment');
            $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
            try {
                Storage::disk('s3')->putFileAs('Sales_Invoices', $file, $inv->id . '-' . $clean);
                $attachment = $clean;
            } catch (\Throwable $e) {
                report($e);
                return response()->json(['message' => 'Attachment upload failed. Please try again.'], 422);
            }
        }

        $dataMaster = [
            'table_id'               => $inv->id,
            'invoice_no'             => $inv->invoice_no,
            'transaction_date'       => $d['transaction_date'],
            'customer_id'            => $d['customer_id'] ?: null,
            'sub_total'              => $this->r4($d['sub_total']),
            'total'                  => $this->r4($d['total']),
            'grand_total'            => $this->r4($d['grand_total']),
            'invoice_status_id'      => $inv->invoice_status_id,
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
            '2307_vt_declared_month' => ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null,
            '2307_it_declared_month' => ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null,
            'added_by'               => $addedBy,
            'assigned_daid'          => $inv->assigned_daid,
            'assigned_auid'          => $inv->assigned_auid,
        ];

        $dataDetail = array_map(fn ($l) => $l + [
            'invoice_no'             => $inv->invoice_no,
            'table_id'               => $inv->id,
            'currency'               => $dataMaster['currency'],
            'ex_rate'                => $dataMaster['ex_rate'],
            'added_by'               => $addedBy,
            'declared_month'         => $dataMaster['declared_month'],
            'invoice_status_id'      => $dataMaster['invoice_status_id'],
            'transaction_date'       => $dataMaster['transaction_date'],
            'document_no'            => $dataMaster['document_no'],
            'customer_id'            => $dataMaster['customer_id'],
            'assigned_daid'          => $dataMaster['assigned_daid'],
            'assigned_auid'          => $dataMaster['assigned_auid'],
            '2307_vt_declared_month' => $dataMaster['2307_vt_declared_month'],
            '2307_it_declared_month' => $dataMaster['2307_it_declared_month'],
        ], $this->buildLineRows($d['lines']));

        // VOID: only the status changes
        if ($action === 'VOID') {
            $dataMaster['invoice_status_id'] = 5;
            $dataDetail = array_map(function ($l) {
                $l['invoice_status_id'] = 5;
                return $l;
            }, $dataDetail);
        }

        // CANCEL: all amounts become negative
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

        (new RequestTicket)->getConnection()->transaction(function () use ($inv, $d, $addedBy, $ticketNo, $dataMaster, $dataDetail, $action) {
            RequestTicket::create([
                'ticket_no'    => $ticketNo,
                'reference_no' => $inv->invoice_no,
                'remarks'      => $d['ticket_remarks'],
                'module'       => 'Sales/Invoices',
                'action'       => $action,
                'request_by'   => $addedBy,
                'is_status'    => 0,
            ]);

            TicketData::create([
                'ticket_no'   => $ticketNo,
                'data_master' => $dataMaster,
                'data_detail' => $dataDetail,
            ]);
        });

        return response()->json([
            'message'   => 'Ticket successfully saved',
            'ticket_no' => $ticketNo,
        ]);
    }
    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $id)
    {
        return $this->save($request, $id);
    }
    private function save(Request $request, ?int $id){
        $d = $request->validate([
            'customer_id'      => ['nullable', 'integer'],
            'transaction_date' => ['required', 'date'],
            'declared_month'   => ['required', 'date_format:Y-m'],
            'document_no'      => ['nullable', 'string', 'max:100'],
            'document_type_id' => ['nullable', 'integer'],
            'account_code'     => ['nullable', 'string', 'max:15'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'ex_rate'          => ['nullable', 'numeric', 'gt:0'],
            'remarks'          => ['nullable', 'string', 'max:255'],
            'attachment'       => ['nullable', 'string', 'max:255'],
            'mobile_transaction_id' => ['nullable', 'integer'],
            'trans_type'            => ['nullable', 'in:posted,billing,invalid'],
            'scan_action'           => ['nullable', 'in:invalid,partial_invalid,cancel'],
            'invalid_reason'        => ['required_if:scan_action,invalid,partial_invalid', 'nullable', 'string', 'max:1000'],
            'atc_vt_id'        => ['nullable', 'integer'],
            'atc_it_id'        => ['nullable', 'integer'],
            'vt_month'         => ['nullable', 'date_format:Y-m'],
            'it_month'         => ['nullable', 'date_format:Y-m'],
            'discount_type_id' => ['nullable', 'integer'],
            // totals from the form
            'sub_total'        => ['required', 'numeric'],
            '2307_vt'          => ['nullable', 'numeric', 'min:0'],
            '2307_it'          => ['nullable', 'numeric', 'min:0'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'total'            => ['required', 'numeric'],
            'grand_total'      => ['required', 'numeric', 'min:0'],
            // lines
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.item_id'      => ['required', 'integer'],
            'lines.*.unit_type_id' => ['nullable', 'integer'],
            'lines.*.qty'          => ['required', 'numeric', 'gt:0'],
            'lines.*.price'        => ['required', 'numeric', 'min:0'],
            'lines.*.discount'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_type'     => ['required', 'in:vatable,exempt,zero,nonvat'],
            'lines.*.account_code' => ['nullable', 'string', 'max:10'],
        ], [
            'invalid_reason.required_if' => 'Reason is required.',
        ]);

        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        // ---------- LOCKED MONTH CHECK ----------
        if ($this->lockedMonth($caid, [$d['declared_month'], $d['vt_month'] ?? null, $d['it_month'] ?? null])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        $existing = null;
        if ($id) {
            $existing = SalesInvoiceMaster::where('id', $id)->where('added_by', $addedBy)->first();
            if (!$existing) return response()->json(['message' => 'Invoice not found.'], 404);

            // invoice already sits in a locked month
            if ($this->lockedMonth($caid, [$existing->declared_month])) {
                return response()->json(['message' => 'Declared Month is disabled'], 422);
            }
        }

        // ---------- line amounts (always calculated here) ----------
        $lineRows = [];
        $linesNet = 0;
        foreach ($d['lines'] as $l) {
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
            $linesNet += $amount;

            $lineRows[] = [
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

        // ---------- totals from the form + safety check ----------
        $wtaxVt = $this->r4($d['2307_vt'] ?? 0);
        $wtaxIt = $this->r4($d['2307_it'] ?? 0);
        $grand  = $this->r4($d['grand_total']);
        $exRate = (float) ($d['ex_rate'] ?? 1) ?: 1;

        $expected = $this->r4($linesNet * $exRate - ($wtaxVt + $wtaxIt));
        if (abs($expected - $grand) > 0.01) {
            return response()->json([
                'message' => "Grand total doesn't match the items (expected {$expected}, got {$grand}). Please refresh and try again.",
            ], 422);
        }

        $scanAction = $d['scan_action'] ?? null;
        $transType  = $scanAction ? 'invalid' : ($d['trans_type'] ?? 'posted');

        // receipt status after saving (same as old TransStatus)
        $scanStatus = match ($scanAction) {
            'invalid'         => 2,
            'partial_invalid' => 3,
            'cancel'          => 5,
            default           => $transType === 'billing' ? 4 : 1,
        };
        // invoice created from a scanned receipt
        $scan = (!$existing && !empty($d['mobile_transaction_id']))
            ? MobileTransaction::where('id', $d['mobile_transaction_id'])->where('added_by', $addedBy)->first()
            : null;

        return (new SalesInvoiceMaster)->getConnection()->transaction(function () use ($d, $existing, $addedBy, $caid, $lineRows, $wtaxVt, $wtaxIt, $grand, $scan, $transType, $scanStatus) {
            $account  = $caid ? ClientAccount::find($caid) : null;
            $invoice  = $existing ?? new SalesInvoiceMaster();
            // Cash / Cash on Hand / Cash in Bank (or ticked "for paid") → PAID right away.
            // Otherwise: new invoice = UNPAID; edited invoice = based on what was already collected
            // (VOID / CANCEL stay as they are).
            if (\App\Support\PaymentAccounts::isCash($addedBy, $d['account_code'] ?? null)) {
                $statusId = \App\Support\PaymentAccounts::statusId('PAID');
            } elseif ($existing && preg_match('/VOID|CANCEL/i', (string) InvoiceStatus::where('id', $existing->invoice_status_id)->value('name'))) {
                $statusId = $existing->invoice_status_id;
            } elseif ($existing) {
                $collected = (float) DB::table('t_rvoucher_detail')->where('invoice_no', $existing->invoice_no)->sum('sub_total');
                $statusId = \App\Support\PaymentAccounts::statusFor((float) $grand, $collected);
            } else {
                $statusId = \App\Support\PaymentAccounts::statusId('UNPAID');
            }
            // new invoice: invoice no + seq no
            if (!$existing) {
                do {
                    $invoiceNo = 'INV-' . date('ymd-his') . random_int(10000, 99999);
                } while (SalesInvoiceMaster::where('invoice_no', $invoiceNo)->exists());

                $invoice->invoice_no = $invoiceNo;
                $invoice->seq_no     = (int) SalesInvoiceMaster::where('added_by', $addedBy)
                    ->lockForUpdate()->max('seq_no') + 1;
                $invoice->assigned_daid = ($account->assigned_daid ?? 0) ?: null;
                $invoice->assigned_auid = ($account->assigned_auid ?? 0) ?: null;
            }
            if ($scan) {
                $invoice->is_scanned = 1;
                $invoice->attachment = $scan->filename;
            }
            $invoice->fill([
                'customer_id'            => $d['customer_id'] ?: null,
                'added_by'               => $addedBy,
                'transaction_date'       => $d['transaction_date'],
                'declared_month'         => $d['declared_month'],
                'account_code'           => $d['account_code'] ?? null,
                'document_no'            => $d['document_no'] ?? null,
                'document_type_id'       => $d['document_type_id'] ?? null,
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
                '2307_vt_declared_month' => ($d['atc_vt_id'] ?? null) ? ($d['vt_month'] ?? null) : null,
                '2307_it_declared_month' => ($d['atc_it_id'] ?? null) ? ($d['it_month'] ?? null) : null,
                'remarks'                => $d['remarks'] ?? null,
                'invalid_reason'         => $d['invalid_reason'] ?? null,
                'invoice_status_id'      => $statusId,
                'trans_type'             => $transType,
            ])->save();

            // replace the lines
            $invoice->details()->delete();
            $invoice->details()->createMany(array_map(fn ($l) => $l + [
                'currency'               => $invoice->currency,
                'ex_rate'                => $invoice->ex_rate,
                'added_by'               => $addedBy,
                'declared_month'         => $invoice->declared_month,
                'invoice_status_id'      => $statusId,
                'transaction_date'       => $invoice->transaction_date,
                'document_no'            => $invoice->document_no,
                'customer_id'            => $invoice->customer_id,
                'assigned_daid'          => $invoice->assigned_daid,
                'assigned_auid'          => $invoice->assigned_auid,
                '2307_vt_declared_month' => $invoice->{'2307_vt_declared_month'},
                '2307_it_declared_month' => $invoice->{'2307_it_declared_month'},
                'trans_type'             => $transType,
            ], $lineRows));
            if ($scan) {
                $scan->update([
                    'trans_status' => $scanStatus,
                    'reference_no' => $invoice->invoice_no,
                ]);
            }
            return response()->json(['id' => $invoice->id, 'invoice_no' => $invoice->invoice_no]);
        });
    }
    /* =====================================================================
    POST /api/sales/{id}/attachment  (multipart: file)
    S3 key: Sales_Invoices/{id}-{clean name}  (same as old uploadToS3)
    ===================================================================== */
    public function uploadAttachment(Request $request, int $id)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif'],
        ]);

        $inv = SalesInvoiceMaster::where('id', $id)
            ->where('added_by', $this->addedByFor($request))
            ->first(['id', 'attachment']);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $file  = $request->file('file');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());

        Storage::disk('s3')->putFileAs('Sales_Invoices', $file, $inv->id . '-' . $clean);
        $inv->update(['attachment' => $clean]);

        return response()->json(['attachment' => $clean]);
    }
    /* =====================================================================
       POST /api/sales/{id}/status  { "status": "VOID" | "CANCELLED" }
       ===================================================================== */
    public function setStatus(Request $request, int $id)
    {
        $d = $request->validate(['status' => ['required', 'in:VOID,CANCELLED']]);
        $addedBy = $this->addedByFor($request);

        $inv = SalesInvoiceMaster::where('id', $id)
            ->where('added_by', $this->addedByFor($request))
            ->first(['id', 'added_by', 'attachment', 'is_scanned']);
            
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $statusId = $this->statusId('m_invoice_status', $d['status'] === 'VOID' ? ['VOID'] : ['CANCEL']);
        if (!$statusId) return response()->json(['message' => "Add a '{$d['status']}' row to m_invoice_status first."], 422);

        DB::transaction(function () use ($inv, $statusId) {
            DB::table('t_sales_invoice_master')->where('id', $inv->id)->update(['invoice_status_id' => $statusId, 'updated_at' => now()]);
            DB::table('t_sales_invoice_detail')->where('invoice_no', $inv->invoice_no)->update(['invoice_status_id' => $statusId, 'updated_at' => now()]);
        });

        return response()->json(['ok' => true]);
    }

    /* =====================================================================
       POST /api/sales/declared-month  { "ids": [..], "declared_month": "2026-09" }
       ===================================================================== */
    public function updateDeclaredMonth(Request $request)
    {
        $d = $request->validate([
            'ids'            => ['required', 'array', 'min:1'],
            'ids.*'          => ['integer'],
            'declared_month' => ['required', 'date_format:Y-m'],
        ]);

        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);

        return DB::transaction(function () use ($d, $addedBy) {
            $invoiceNos = DB::table('t_sales_invoice_master')
                ->whereIn('id', $d['ids'])->where('added_by', $addedBy)->pluck('invoice_no');

            $updated = DB::table('t_sales_invoice_master')->whereIn('invoice_no', $invoiceNos)
                ->update(['declared_month' => $d['declared_month'], 'updated_at' => now()]);

            DB::table('t_sales_invoice_detail')->whereIn('invoice_no', $invoiceNos)
                ->update(['declared_month' => $d['declared_month'], 'updated_at' => now()]);

            return response()->json(['updated' => $updated]);
        });
    }

    /* =====================================================================
       Quick add from the invoice page
       ===================================================================== */

    // POST /api/customers
    public function storeCustomer(Request $request)
    {
        $d = $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'tin'          => ['nullable', 'string', 'max:20'],
            'branch_code'  => ['nullable', 'string', 'max:10'],
            'address'      => ['nullable', 'string', 'max:255'],
            'city_country' => ['nullable', 'string', 'max:255'],
        ]);

        $id = DB::table('m_vendor_customer')->insertGetId($d + [
            'link_by_caid'  => $this->caidFor($request),
            'entry_by_caid' => $this->addedByFor($request),
            'user_type'     => 'Customer',
            'is_default'    => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return response()->json(DB::table('m_vendor_customer')
            ->select('id', 'name', 'tin', 'branch_code', 'address', 'city_country')->find($id));
    }

    // POST /api/items
    public function storeItem(Request $request)
    {
        $addedBy = $this->addedByFor($request);

        $d = $request->validate([
            'item_code'   => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'price'       => ['required', 'numeric', 'min:0'],
            'cost'        => ['nullable', 'numeric', 'min:0'],
        ]);
              
        $id = DB::table('m_items')->insertGetId([
            'item_code'      => $d['item_code'],
            'description'    => $d['description'],
            'price'          => $d['price'],
            'last_unit_cost' => $d['cost'] ?? 0,
            'added_by'       => $addedBy,
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'id' => $id, 'item_code' => $d['item_code'], 'description' => $d['description'],
            'price' => $d['price'], 'inventory_qty' => 0, 'unit_type_id' => null, 'unit' => '',
            'tax_type' => '', 'gl_sales_account' => null,
        ]);
    }
    /* =====================================================================
   GET /api/sales/{id}/attachment
   Same lookup as the old DisplayData() + get_attachment2.php:
     - normal invoice : Sales_Invoices/{invoice id}-{file}
     - scanned invoice: {folder by 1st letter}/{added_by}-{file}
     - link           : returns { url } instead of a file
   ===================================================================== */
    public function attachment(Request $request, int $id)
    {
        $inv = DB::table('t_sales_invoice_master')
            ->where('id', $id)->where('added_by', $this->addedByFor($request))
            ->first();

        if (!$inv) {
            return response()->json(['message' => 'No attachment for this invoice.'], 404);
        }

        $file = trim((string) ($inv->attachment ?? ''));
        if ($file === '') {
            return response()->json(['message' => 'No attachment for this invoice.'], 404);
        }

        // External link (old "Collection"/link attachments)
        if (preg_match('/^https?:\/\//i', $file)) {
            return response()->json(['url' => $file]);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        // Try the same keys the old system used, most likely first
        foreach ($this->attachmentKeys($inv, $file) as $key) {
            if ($disk->exists($key)) {
                $mime = $disk->mimeType($key) ?: 'application/octet-stream';
                return response()->stream(function () use ($disk, $key) {
                    $stream = $disk->readStream($key);
                    if ($stream) {
                        fpassthru($stream);
                        fclose($stream);
                    }
                }, 200, [
                    'Content-Type'        => $mime,
                    'Content-Disposition' => 'inline; filename="' . addslashes($file) . '"',
                    'Cache-Control'       => 'private, max-age=300',
                ]);
            }
        }

        return response()->json(['message' => 'File not found on storage.'], 404);
    }

    /** getModelFromFileName(): S = Sales, P = Vendor, E = Expenses */
    private function folderFromFileName(string $file): string
    {
        return match (strtoupper(substr($file, 0, 1))) {
            'P'     => 'Vendor_Invoices',
            'E'     => 'Expenses_Transaction',
            default => 'Sales_Invoices',
        };
    }
    /** Possible S3 keys, in the order the old code would use them */
    private function attachmentKeys(object $inv, string $file): array
    {
        $scannedFolder = $this->folderFromFileName($file);

        $keys = (int) ($inv->is_scanned ?? 0) === 1
            ? [
                "{$scannedFolder}/{$inv->added_by}-{$file}",   // scanned: user id + folder by letter
                "Sales_Invoices/{$inv->id}-{$file}",
            ]
            : [
                "Sales_Invoices/{$inv->id}-{$file}", // normal: invoice id
                "{$scannedFolder}/{$inv->added_by}-{$file}",
            ];

        $keys[] = "Scanned_Receipts/{$file}"; // no id prefix in this folder

        return array_values(array_unique($keys));
    }
    /* Is any of these months locked for this client account? (m_delared_month_settings) */
    // Is one of these months locked for this client account? (m_delared_month_settings)
    private function lockedMonth(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        if (!$caid || !$months) return false;

        return DeclaredMonthSettings::where('ca_id', $caid)
            ->whereIn('declared_month', $months)
            ->exists();
    }
    private function invoiceRules(): array
    {
        return [
            'customer_id'          => ['nullable', 'integer'],
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
    // First status whose name starts with one of $names (Eloquent)
    private function statusIdFor(string $model, array $names): ?int
    {
        foreach ($names as $n) {
            $id = $model::whereRaw('UPPER(name) LIKE ?', [strtoupper($n) . '%'])->value('id');
            if ($id) return (int) $id;
        }
        return null;
    }
    private function buildLineRows(array $lines): array
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
    /* GET /api/sales/scanned  – Scanned Receipts tab (t_mobile_transaction) */
    /* ================= SCANNED RECEIPTS ================= */

    // list (Scanned Receipts tab)
    public function scanned(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);

        $rows = MobileTransaction::where('added_by', $addedBy)
            ->whereRaw('LOWER(trans_type) = ?', ['sales'])
            ->whereNotIn('trans_status', [2, 3, 4, 5])
            ->orderByDesc('id')
            ->get(['id', 'filename', 'reference_no', 'trans_status', 'created_at', 'updated_at']);

        return response()->json($rows->map(fn ($r) => [
            'id'           => $r->id,
            'filename'     => $r->filename,
            'reference_no' => $r->reference_no,
            'trans_status' => $r->trans_status,
            'date_added'   => $r->created_at?->format('Y-m-d H:i:s'),
            'date_updated' => $r->updated_at?->format('Y-m-d H:i:s'),
        ]));
    }

    // S3 key of a scanned file
    private function scanKey(MobileTransaction $scan): ?string
    {
        $folder = match (strtoupper(substr($scan->filename, 0, 1))) {
            'P'     => 'Vendor_Invoices',
            'E'     => 'Expenses_Transaction',
            default => 'Sales_Invoices',
        };
        $disk = Storage::disk('s3');
        foreach ([
            "{$folder}/{$scan->added_by}-{$scan->filename}",
            "{$folder}/{$scan->filename}",
            "Scanned_Receipts/{$scan->filename}",
        ] as $key) {
            if ($disk->exists($key)) return $key;
        }
        return null;
    }

    // stream the file (preview)
    public function scannedFile(Request $request, int $id)
    {
        $scan = MobileTransaction::where('id', $id)
            ->where('added_by', $this->addedByFor($request))
            ->first(['id', 'filename', 'added_by']);
        if (!$scan) return response()->json(['message' => 'File not found.'], 404);

        $key = $this->scanKey($scan);
        if (!$key) return response()->json(['message' => 'File not found in storage.'], 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        return $disk->response($key, $scan->filename);
    }

    // Textract client (same keys as the s3 disk)
    private function textract(): TextractClient
    {
        $c = config('filesystems.disks.s3');
        return new TextractClient([
            'version'     => 'latest',
            'region'      => $c['region'],
            'credentials' => ['key' => $c['key'], 'secret' => $c['secret']],
            'http'        => ['verify' => $c['http']['verify'] ?? true],
        ]);
    }

    /**
     * all the text lines of a scanned receipt on S3 (Amazon Textract).
     * 1) quick read (DetectDocumentText): one page → answer in 1–3 seconds
     * 2) only when that can't read it (multi-page PDF): the slow job, polled every second, with more time allowed
     */
    protected function scanText(TextractClient $tx, string $key): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        $lines = fn ($blocks) => implode('', array_map(
            fn ($b) => ($b['BlockType'] ?? '') === 'LINE' && isset($b['Text']) ? $b['Text'] . "\n" : '',
            $blocks ?? []
        ));

        try {
            $res = $tx->detectDocumentText(['Document' => ['S3Object' => ['Bucket' => $bucket, 'Name' => $key]]]);
            return $lines($res['Blocks'] ?? []);
        } catch (\Aws\Textract\Exception\TextractException $e) {
            // multi-page PDF / not supported by the quick read → the slow job below
            if (!in_array($e->getAwsErrorCode(), ['UnsupportedDocumentException', 'DocumentTooLargeException', 'InvalidParameterException'], true)) throw $e;
        }

        @set_time_limit(150);                                // the slow job can take a minute
        $jobId = $tx->startDocumentTextDetection(['DocumentLocation' => ['S3Object' => ['Bucket' => $bucket, 'Name' => $key]]])['JobId'];
        $tries = 0;
        do {
            sleep(1);
            $res = $tx->getDocumentTextDetection(['JobId' => $jobId]);
            $status = $res['JobStatus'];
        } while ($status === 'IN_PROGRESS' && ++$tries < 120);
        if ($status !== 'SUCCEEDED') throw new \RuntimeException('Scanning took too long. Please try again.');

        $text = $lines($res['Blocks'] ?? []);
        $next = $res['NextToken'] ?? null;
        while ($next) {
            $res = $tx->getDocumentTextDetection(['JobId' => $jobId, 'NextToken' => $next]);
            $text .= $lines($res['Blocks'] ?? []);
            $next = $res['NextToken'] ?? null;
        }
        return $text;
    }

    // SCAN: read the receipt (old read_pdf.php)
    public function readScan(Request $request, int $id)
    {
        $addedBy = $this->addedByFor($request);

        $scan = MobileTransaction::where('id', $id)->where('added_by', $addedBy)->first();
        if (!$scan) return response()->json(['message' => 'Receipt not found.'], 404);

        if ($scan->reference_no) {
            return response()->json(['reference_no' => $scan->reference_no]);
        }

        $key = $this->scanKey($scan);
        if (!$key) return response()->json(['message' => 'File not found in storage.'], 404);

        try {
            $text = $this->scanText($this->textract(), $key);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Scanning failed: ' . $e->getMessage()], 422);
        }

        // TINs
        preg_match_all('/\b\d{3}-?\d{3}-?\d{3}\b/', $text, $m);
        $tins = array_values(array_unique($m[0] ?? []));

        // names after keywords
        $names = [];
        foreach (['Company Name', 'Store Name', 'Client Name', 'Payor Name', 'Payor', 'Customer', 'Merchant', 'Business', 'Sold To'] as $kw) {
            if (preg_match_all('/' . preg_quote($kw, '/') . '\s*[:\-]?\s*([\w\s&.,\-]{2,50})/i', $text, $mm)) {
                foreach ($mm[1] as $n) {
                    $n = trim(strtok($n, "\n"));
                    if ($n !== '') $names[] = $n;
                }
            }
        }
        $names = array_values(array_unique($names));

        // invoice / document numbers
        $docNos = [];
        foreach ([
            '/Invoice\s*No\.?\s*[:\-]?\s*([\w\-]+)/i',
            '/Document\s*No\.?\s*[:\-]?\s*([\w\-]+)/i',
            '/Invoice\s*#\s*[:\-]?\s*([\w\-]+)/i',
            '/Doc\s*#\s*[:\-]?\s*([\w\-]+)/i',
        ] as $p) {
            if (preg_match_all($p, $text, $mm)) {
                foreach ($mm[1] as $n) if (trim($n) !== '') $docNos[] = trim($n);
            }
        }

        // match customer by TIN or name
        $customers = collect();
        if ($tins || $names) {
            $customers = VendorCustomer::where('user_type', 'Customer')
                ->where('entry_by', $addedBy)
                ->where(function ($q) use ($tins, $names) {
                    foreach ($tins as $t)  $q->orWhere('tin', 'like', '%' . $t . '%');
                    foreach ($names as $n) $q->orWhere('name', 'like', '%' . $n . '%');
                })
                ->limit(10)
                ->get(['id', 'name', 'tin']);
        }

        // default item: "VARIOUS SALES VAT" / "SALES VAT" (same as old doGetDefaultAccounts)
        $defaultItemId = Items::where('added_by', $addedBy)
            ->where(function ($q) {
                $q->whereRaw("UPPER(TRIM(description)) REGEXP '^VARIOUS SALES[[:space:]-]*(VAT|VT|VATABLE|VATABLES)$'")
                  ->orWhereRaw("UPPER(TRIM(description)) REGEXP '^SALES[[:space:]-]*(VAT|VT|VATABLE|VATABLES)$'");
            })
            ->value('id');

        return response()->json([
            'scan_id'     => $scan->id,
            'filename'    => $scan->filename,
            'document_no' => implode('-', array_unique($docNos)),
            'customer_id' => $customers->count() === 1 ? $customers->first()->id : null,
            'customers'   => $customers,
            'tins'        => $tins,
            'names'       => $names,
            'default_item_id' => $defaultItemId,
            'raw_text'    => mb_substr($text, 0, 500),
        ]);
    }

    // TRANSFER: move receipt to Purchases / Expenses
    public function transferScan(Request $request, int $id)
    {
        $d = $request->validate(['trans_type' => ['required', 'in:purchases,expenses']]);

        $scan = MobileTransaction::where('id', $id)
            ->where('added_by', $this->addedByFor($request))
            ->where('trans_status', 0)
            ->first();
        if (!$scan) return response()->json(['message' => 'Receipt not found or already processed.'], 404);

        $scan->update(['trans_type' => $d['trans_type']]);

        return response()->json(['message' => 'Receipt transferred.']);
    }
        /* POST /api/sales/{id}/post  – Partially Invalid → posted */
    public function postInvoice(Request $request, int $id)
    {
        $addedBy = $this->addedByFor($request);

        $inv = SalesInvoiceMaster::where('id', $id)
            ->where('added_by', $addedBy)
            ->first(['id', 'invoice_no', 'trans_type', 'declared_month']);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        if ($inv->trans_type !== 'invalid') {
            return response()->json(['message' => 'Only invalid records can be posted.'], 422);
        }

        if ($this->lockedMonth($this->caidFor($request), [$inv->declared_month])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        (new SalesInvoiceMaster)->getConnection()->transaction(function () use ($inv, $addedBy) {
            $inv->update(['trans_type' => 'posted']);

            SalesInvoiceDetail::where('invoice_no', $inv->invoice_no)
                ->where('added_by', $addedBy)
                ->update(['trans_type' => 'posted']);
        });

        return response()->json(['message' => "{$inv->invoice_no} posted"]);
    }
        /* =====================================================================
       POST /api/sales/override   (old save_transaction_override.php)
       Supervisor moves the selected invoices to another declared month.
       ===================================================================== */
    public function override(Request $request)
    {
        $d = $request->validate([
            'ids'            => ['required', 'array', 'min:1'],
            'ids.*'          => ['integer'],
            'declared_month' => ['required', 'date_format:Y-m'],
            'remarks'        => ['nullable', 'string', 'max:1000'],
            'username'       => ['required', 'string'],
            'password'       => ['required', 'string'],
        ], [
            'declared_month.required' => 'Declared Month is required',
            'username.required'       => 'Username is required',
            'password.required'       => 'Password is required',
        ]);

        $addedBy = $this->addedByFor($request);
        $caid    = $this->caidFor($request);

        // 1) locked month
        if ($this->lockedMonth($caid, [$d['declared_month']])) {
            return response()->json([
                'message' => Carbon::createFromFormat('Y-m', $d['declared_month'])->format('F Y') . ' is locked',
            ], 422);
        }

        // 2) supervisor (same as old doGetSupervisorList: UserTypeID 10, plain password)
        $supervisor = UserStaff::where('username', $d['username'])
            ->where('user_type_id', 10)
            ->where('is_active', 1) // remove this line if m_user_staff has no "active" column
            ->first(['id', 'password']);

        if (!$supervisor || !\Illuminate\Support\Facades\Hash::check((string) $d['password'], (string) $supervisor->getRawOriginal('password'))) {
            return response()->json(['message' => 'User not found'], 422);
        }

        // 3) invoices of this account only
        $invoices = SalesInvoiceMaster::where('added_by', $addedBy)->whereIn('id', $d['ids'])->get();
        if ($invoices->isEmpty()) {
            return response()->json(['message' => 'No invoices found.'], 404);
        }

        $ticketNo = 'TCKT' . date('ymd-his') . random_int(10000, 99999);

        (new SalesInvoiceMaster)->getConnection()->transaction(function () use ($invoices, $d, $addedBy, $ticketNo, $supervisor) {
            foreach ($invoices as $inv) {
                $details = SalesInvoiceDetail::where('invoice_no', $inv->invoice_no)
                    ->where('added_by', $addedBy)
                    ->get();

                // ticket (already approved, like the old Status = 1)
                RequestTicket::create([
                    'ticket_no'      => $ticketNo,
                    'reference_no'   => $inv->invoice_no,
                    'remarks'        => $d['remarks'] ?? null,
                    'module'         => 'Sales/Invoices',
                    'action'         => 'UNDECLARED',
                    'request_by'     => $addedBy,
                    'is_status'      => 1,
                ]);

                // snapshot BEFORE the change (old tbl2_history)
                TicketHistory::create([
                    'ticket_no'  => $ticketNo,
                    'reference_no'  => $inv->invoice_no,
                    'table_data' => [
                        'master'  => $inv->toArray(),
                        'details' => $details->toArray(),
                    ],
                ]);

                // move to the new declared month (master + details)
                $inv->update(['declared_month' => $d['declared_month']]);
                SalesInvoiceDetail::where('invoice_no', $inv->invoice_no)
                    ->where('added_by', $addedBy)
                    ->update(['declared_month' => $d['declared_month']]);
            }
        });

        return response()->json([
            'message'   => 'Invoices successfully updated',
            'updated'   => $invoices->count(),
            'ticket_no' => $ticketNo,
        ]);
    }
    /* GET /api/sales/blank-attachment  – old get_attachment2.php?ID=0&file=blank.pdf */
    public function blankAttachment()
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        foreach (['Sales_Invoices/0-blank.pdf', 'Sales_Invoices/blank.pdf', 'blank.pdf'] as $key) {
            if ($disk->exists($key)) {
                return $disk->response($key, 'blank.pdf', ['Content-Type' => 'application/pdf']);
            }
        }

        return response()->json(['message' => 'blank.pdf not found'], 404);
    }
}