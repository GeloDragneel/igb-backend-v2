<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequestTicket;
use App\Models\TicketData;
use App\Models\TicketHistory;
use App\Models\DeclaredMonthSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/* =========================================================================
   ADMINISTRATION → TICKET  (old pages/Administration/List.php + function/List.php)

   A ticket is made on Sales / Purchases / Expenses (EDIT · VOID · CANCEL):
     t_request_ticket  the ticket (is_status 0 pending · 1 approved · 2 declined)
     t_ticket_data     the requested master + lines (JSON)
     t_ticket_history  snapshot of the invoice BEFORE it was changed

   GET  /admin/tickets?status=all|0|1|2           list + counts
   GET  /admin/tickets/{ticketNo}                 requested data + the invoice before it
   GET  /admin/tickets/{ticketNo}/attachment      file for the popup (?which=before)
   POST /admin/tickets/{ticketNo}/approve         EDIT / CANCEL → write it · VOID → status 5
   POST /admin/tickets/{ticketNo}/decline
   ========================================================================= */
class AdministrationController extends Controller
{
    private const VOID = 5, AUDIT_PENDING = 1, AUDIT_REVERTED = 3;
    private const PENDING = 0, APPROVED = 1, DECLINED = 2;

    private static array $cols = [];

    /* ---------------- helpers ---------------- */

    private function cols(string $table): array
    {
        return self::$cols[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }

    private function pick(string $table, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, $this->cols($table), true)) return $n;
        return null;
    }

    /** keep only the keys that are real columns of $table */
    private function only(string $table, array $row): array
    {
        return array_intersect_key($row, array_flip($this->cols($table)));
    }

    private function when($v): ?string
    {
        if (!$v) return null;
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : substr((string) $v, 0, 19);
    }

    private function arr($v): array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') return json_decode($v, true) ?: [];
        if (is_object($v)) return json_decode(json_encode($v), true) ?: [];
        return [];
    }

    /** staff only (client logins can't approve their own tickets) */
    private function staffOnly(Request $request): void
    {
        $u = $request->user();
        abort_if(!$u, 401);
        foreach (['App\Models\UserClient', 'App\Models\UserClientStaff'] as $c) {
            abort_if(class_exists($c) && $u instanceof $c, 403, 'Only admin staff can do this.');
        }
    }

    /**
     * foreign-key columns: 0 / '' means "nobody" → NULL (MySQL refuses 0, there is no row with id 0).
     * A NOT NULL foreign-key column is left alone.
     */
    private function fkClean(string $table, array $row): array
    {
        static $fks = [];
        if (!isset($fks[$table])) {
            $nullable = collect(Schema::getColumns($table))->filter(fn ($c) => $c['nullable'])->pluck('name')->flip();
            $fks[$table] = collect(Schema::getForeignKeys($table))->flatMap(fn ($f) => $f['columns'])
                ->filter(fn ($c) => isset($nullable[$c]))->unique()->values()->all();
        }
        foreach ($fks[$table] as $c) {
            if (array_key_exists($c, $row) && ($row[$c] === 0 || $row[$c] === '0' || $row[$c] === '')) $row[$c] = null;
        }
        return $row;
    }

    /** where the invoice lives: [type, master table, detail table, party column] */
    private function locate(string $referenceNo): ?array
    {
        if (DB::table('t_sales_invoice_master')->where('invoice_no', $referenceNo)->exists()) {
            return ['sales', 't_sales_invoice_master', 't_sales_invoice_detail', 'customer_id'];
        }
        $t = DB::table('t_purchase_expense_master')->where('invoice_no', $referenceNo)->value('trans_type');
        if ($t !== null) {
            return [strtolower((string) $t) === 'expenses' ? 'expense' : 'purchase', 't_purchase_expense_master', 't_purchase_expense_detail', 'vendor_id'];
        }
        return null;
    }

    private function ticket(string $ticketNo): RequestTicket
    {
        $t = RequestTicket::where('ticket_no', $ticketNo)->latest('id')->first();
        abort_unless($t, 404, 'Ticket not found.');
        return $t;
    }

    /** m_user_client id → "username" + trade name */
    private function requesters(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $uc = 'm_user_client';
        $name = $this->pick($uc, ['username', 'user_name', 'name', 'email']);
        $ca = $this->pick($uc, ['caid', 'client_account_id']);
        $q = DB::table("{$uc} as U")->whereIn('U.id', $ids);
        $sel = ['U.id', $name ? "U.{$name} as uname" : DB::raw("'' as uname")];
        if ($ca && $this->cols('m_client_account')) {
            $q->leftJoin('m_client_account as B', 'B.id', '=', "U.{$ca}");
            $sel[] = 'B.trade_name';
            $sel[] = 'B.branch_code';
        }
        $out = [];
        foreach ($q->get($sel) as $r) {
            $out[(int) $r->id] = [
                'username'   => (string) $r->uname,
                'trade_name' => (string) ($r->trade_name ?? ''),
                'branch'     => (string) ($r->branch_code ?? ''),
            ];
        }
        return $out;
    }

    /* ---------------- list ---------------- */

    /** GET /admin/tickets?status=all|0|1|2&q=&page=1&per=15   (paged on the server) */
    public function ticketList(Request $request)
    {
        $this->staffOnly($request);
        $d = $request->validate([
            'status' => ['nullable', 'in:all,0,1,2'],
            'q'      => ['nullable', 'string', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1'],
            'per'    => ['nullable', 'integer', 'in:15,20,50,100'],
        ]);
        $status = $d['status'] ?? '0';            // default: Pending
        $per = (int) ($d['per'] ?? 15);
        $page = (int) ($d['page'] ?? 1);
        $term = trim($d['q'] ?? '');

        // search: ticket / reference / module / remarks / action / requester's username
        $search = function ($q) use ($term) {
            if ($term === '') return;
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $uname = $this->pick('m_user_client', ['username', 'user_name', 'name', 'email']);
            $q->where(function ($w) use ($like, $uname) {
                $w->where('ticket_no', 'like', $like)->orWhere('reference_no', 'like', $like)
                    ->orWhere('module', 'like', $like)->orWhere('remarks', 'like', $like)->orWhere('action', 'like', $like);
                if ($uname) $w->orWhereIn('request_by', DB::table('m_user_client')->where($uname, 'like', $like)->select('id'));
            });
        };

        $base = RequestTicket::query()->tap($search);
        $counts = (clone $base)->selectRaw('is_status, COUNT(*) AS n')->groupBy('is_status')->pluck('n', 'is_status');
        $list = (clone $base)->when($status !== 'all', fn ($q) => $q->where('is_status', (int) $status));
        $total = $status === 'all' ? (int) $counts->sum() : (int) ($counts[(int) $status] ?? 0);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);

        $tickets = $list->orderByDesc('id')->forPage($page, $per)
            ->get(['id', 'ticket_no', 'reference_no', 'remarks', 'module', 'action', 'request_by', 'is_status', 'created_at']);

        // type of each reference (sales / purchase / expense) — only for this page
        $refs = $tickets->pluck('reference_no')->filter()->unique()->values();
        $sales = DB::table('t_sales_invoice_master')->whereIn('invoice_no', $refs)->pluck('invoice_no')->flip();
        $ap = DB::table('t_purchase_expense_master')->whereIn('invoice_no', $refs)->pluck('trans_type', 'invoice_no');
        $who = $this->requesters($tickets->pluck('request_by')->all());

        $rows = $tickets->map(function ($t) use ($sales, $ap, $who) {
            $type = isset($sales[$t->reference_no]) ? 'sales'
                : (isset($ap[$t->reference_no]) ? (strtolower((string) $ap[$t->reference_no]) === 'expenses' ? 'expense' : 'purchase') : null);
            $w = $who[(int) $t->request_by] ?? null;
            return [
                'id'           => (int) $t->id,
                'ticket_no'    => $t->ticket_no,
                'reference_no' => $t->reference_no,
                'module'       => $t->module,
                'type'         => $type,
                'remarks'      => (string) $t->remarks,
                'action'       => strtoupper((string) $t->action),
                'requested_by' => $w['username'] ?? '',
                'trade_name'   => $w['trade_name'] ?? '',
                'status'       => (int) $t->is_status,
                'date_added'   => $this->when($t->created_at),
            ];
        })->values();

        return response()->json([
            'rows'   => $rows,
            'total'  => $total,
            'page'   => $page,
            'per'    => $per,
            'counts' => [
                'all' => (int) $counts->sum(),
                '0'   => (int) ($counts[0] ?? 0),
                '1'   => (int) ($counts[1] ?? 0),
                '2'   => (int) ($counts[2] ?? 0),
            ],
        ]);
    }

    /* ---------------- one ticket ---------------- */

    /** master + lines (any source) → the shape the popup shows */
    private function shape(string $type, string $mt, string $dt, array $m, array $lines, ?object $live): array
    {
        $partyId = $m['customer_id'] ?? $m['vendor_id'] ?? null;
        $party = $partyId ? DB::table('m_vendor_customer')->where('id', $partyId)->first() : null;
        $addedBy = $m['added_by'] ?? $live->added_by ?? null;

        $accName = $this->pick('m_charts_of_account', ['account_name', 'name']) ?? 'account_name';
        $codes = collect($lines)->pluck('account_code')->push($m['account_code'] ?? null)->filter()->unique()->values();
        $accNames = $codes->isEmpty() ? collect() : DB::table('m_charts_of_account')
            ->whereIn('account_code', $codes)
            ->when($addedBy && $this->pick('m_charts_of_account', ['added_by']), fn ($q) => $q->where('added_by', $addedBy))
            ->pluck($accName, 'account_code');
        $acc = fn ($code) => $code ? trim($code . ' ' . ($accNames[$code] ?? '')) : '';

        $items = DB::table('m_items')->whereIn('id', collect($lines)->pluck('item_id')->filter()->unique())
            ->get(array_filter(['id', $this->pick('m_items', ['item_code']), $this->pick('m_items', ['description'])]))->keyBy('id');
        $unitName = $this->pick('m_unit_type', ['name', 'description']);
        $units = $unitName ? DB::table('m_unit_type')->whereIn('id', collect($lines)->pluck('unit_type_id')->filter()->unique())->pluck($unitName, 'id') : collect();

        $label = function (string $table, $id) {
            if (!$id || !$this->cols($table)) return '';
            $c = $this->pick($table, ['name', 'description']);
            return $c ? (string) DB::table($table)->where('id', $id)->value($c) : '';
        };
        $atc = function ($id) {
            if (!$id || !$this->cols('m_atc')) return '';
            $a = DB::table('m_atc')->where('id', $id)->first();
            if (!$a) return '';
            $rate = (float) ($a->rate ?? 0);
            return trim(($a->name ?? '') . ($rate ? ' - ' . round($rate <= 1 ? $rate * 100 : $rate, 2) . '%' : ''));
        };
        $docType = $this->cols('m_document_type') ? 'm_document_type' : ($this->cols('m_document_types') ? 'm_document_types' : null);

        return [
            'type'             => $type,
            'id'               => (int) ($m['table_id'] ?? $m['id'] ?? $live->id ?? 0),
            'invoice_no'       => $m['invoice_no'] ?? $live->invoice_no ?? '',
            'party'            => (string) ($party->name ?? ''),
            'party_tin'        => (string) ($party->tin ?? ''),
            'party_branch'     => (string) ($party->branch_code ?? ''),
            'party_address'    => array_values(array_filter([trim((string) ($party->address ?? '')), trim((string) ($party->city_country ?? ''))])),
            'date'             => !empty($m['transaction_date']) ? substr((string) $m['transaction_date'], 0, 10) : null,
            'currency'         => $m['currency'] ?? 'PHP',
            'ex_rate'          => (float) ($m['ex_rate'] ?? 1),
            'invoice_status'   => $label('m_invoice_status', $m['invoice_status_id'] ?? null),
            'invoice_status_id'=> (int) ($m['invoice_status_id'] ?? 0),
            'remarks'          => (string) ($m['remarks'] ?? ''),
            'declared_month'   => (string) ($m['declared_month'] ?? ''),
            'document_no'      => (string) ($m['document_no'] ?? ''),
            'document_type'    => $docType ? $label($docType, $m['document_type_id'] ?? null) : '',
            'account'          => $acc($m['account_code'] ?? null),
            'attachment'       => (string) ($m['attachment'] ?? ''),
            'sub_total'        => (float) ($m['sub_total'] ?? 0),
            'atc_vt'           => $atc($m['atc_vt_id'] ?? null),
            'vt_2307'          => (float) ($m['2307_vt'] ?? 0),
            'vt_month'         => (string) ($m['2307_vt_declared_month'] ?? ''),
            'atc_it'           => $atc($m['atc_it_id'] ?? null),
            'it_2307'          => (float) ($m['2307_it'] ?? 0),
            'it_month'         => (string) ($m['2307_it_declared_month'] ?? ''),
            'discount_type_id' => (int) ($m['discount_type_id'] ?? 0),
            'discount'         => (float) ($m['discount_amount'] ?? 0),
            'total'            => (float) ($m['total'] ?? 0),
            'grand_total'      => (float) ($m['grand_total'] ?? 0),
            'lines'            => array_values(array_map(fn ($l) => [
                'item_code'   => (string) ($items[$l['item_id'] ?? 0]->item_code ?? ''),
                'description' => (string) ($items[$l['item_id'] ?? 0]->description ?? ''),
                'account'     => $acc($l['account_code'] ?? null),
                'unit_type'   => (string) ($units[$l['unit_type_id'] ?? 0] ?? ''),
                'quantity'    => (float) ($l['quantity'] ?? 0),
                'price'       => (float) ($l['unit_price'] ?? 0),
                'total'       => (float) ($l['amount'] ?? 0),
                'vat'         => (float) ($l['input_vat'] ?? 0),
                'net_of_vat'  => (float) ($l['net_of_vat'] ?? 0),
                'vat_exempt'  => (float) ($l['vat_exempt'] ?? 0),
                'non_vat'     => (float) ($l['non_vat'] ?? 0),
                'zero_rated'  => (float) ($l['zero_rated'] ?? 0),
            ], $lines)),
        ];
    }

    public function ticketShow(Request $request, string $ticketNo)
    {
        $this->staffOnly($request);
        $t = $this->ticket($ticketNo);
        $where = $this->locate((string) $t->reference_no);
        abort_unless($where, 404, 'The invoice of this ticket was not found.');
        [$type, $mt, $dt] = $where;

        $live = DB::table($mt)->where('invoice_no', $t->reference_no)->first();
        $liveLines = DB::table($dt)->where('invoice_no', $t->reference_no)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $data = TicketData::where('ticket_no', $ticketNo)->latest('id')->first();
        $hist = TicketHistory::where('ticket_no', $ticketNo)->latest('id')->first();
        $snap = $hist ? $this->arr($hist->table_data) : null;

        // requested = the ticket data (UNDECLARED tickets have none → the invoice as it is now)
        $requested = $data
            ? $this->shape($type, $mt, $dt, $this->arr($data->data_master), $this->arr($data->data_detail), $live)
            : $this->shape($type, $mt, $dt, (array) $live, $liveLines, $live);
        // before = pending / declined: the invoice now (unchanged) · approved: the snapshot saved on approval
        $before = (int) $t->is_status !== self::APPROVED
            ? $this->shape($type, $mt, $dt, (array) $live, $liveLines, $live)
            : ($snap ? $this->shape($type, $mt, $dt, $this->arr($snap['master'] ?? []), $this->arr($snap['details'] ?? []), $live) : null);

        $who = $this->requesters([$t->request_by])[(int) $t->request_by] ?? [];
        return response()->json([
            'ticket' => [
                'ticket_no'    => $t->ticket_no,
                'reference_no' => $t->reference_no,
                'module'       => $t->module,
                'action'       => strtoupper((string) $t->action),
                'remarks'      => (string) $t->remarks,
                'status'       => (int) $t->is_status,
                'requested_by' => $who['username'] ?? '',
                'trade_name'   => $who['trade_name'] ?? '',
                'branch'       => $who['branch'] ?? '',
                'date_added'   => $this->when($t->created_at),
            ],
            'type'      => $type,
            'requested' => $requested,
            'before'    => $before,
        ]);
    }

    /* ---------------- attachment (same S3 keys as the Sales / AP pages) ---------------- */

    public function ticketAttachment(Request $request, string $ticketNo)
    {
        $this->staffOnly($request);
        $t = $this->ticket($ticketNo);
        $where = $this->locate((string) $t->reference_no);
        abort_unless($where, 404, 'Invoice not found.');
        [$type, $mt] = $where;
        $live = DB::table($mt)->where('invoice_no', $t->reference_no)->first();

        $file = trim((string) ($live->attachment ?? ''));
        if ($request->query('which') !== 'before' && ($data = TicketData::where('ticket_no', $ticketNo)->latest('id')->first())) {
            $file = trim((string) ($this->arr($data->data_master)['attachment'] ?? $file));
        }
        if ($file === '') return response()->json(['missing' => true, 'message' => 'No attachment.']);
        if (preg_match('/^https?:\/\//i', $file)) return response()->json(['url' => $file]);

        $own = ['sales' => 'Sales_Invoices', 'purchase' => 'Vendor_Invoices', 'expense' => 'Expenses_Transaction'][$type];
        $letter = match (strtoupper(substr($file, 0, 1))) {
            'S' => 'Sales_Invoices', 'P' => 'Vendor_Invoices', 'E' => 'Expenses_Transaction', default => $own,
        };
        $owners = [(int) $live->added_by];
        if ($this->cols('t_mobile_transaction')) {
            foreach (DB::table('t_mobile_transaction')->where('filename', $file)->pluck('added_by') as $by) $owners[] = (int) $by;
        }
        $byOwner = fn (string $folder) => array_map(fn ($u) => "{$folder}/{$u}-{$file}", array_unique($owners));
        $keys = (int) ($live->is_scanned ?? 0) === 1
            ? [...$byOwner($letter), "{$own}/{$live->id}-{$file}"]
            : ["{$own}/{$live->id}-{$file}", ...$byOwner($letter)];
        $keys = array_values(array_unique([...$keys, ...$byOwner($own), "{$letter}/{$file}", "{$own}/{$file}", "Scanned_Receipts/{$file}"]));

        $disk = Storage::disk('s3');
        foreach ($keys as $key) {
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
        return response()->json(['missing' => true, 'message' => "{$file} is not on S3."] + (config('app.debug') ? ['tried' => $keys] : []));
    }

    /* ---------------- approve / decline ---------------- */

    /** who clicked (m_user_staff id) on the ticket, when the column exists */
    private function stamp(Request $request): array
    {
        $by = $this->pick('t_request_ticket', ['approved_by', 'processed_by', 'process_by', 'action_by']);
        return array_filter([
            $by          => $by ? (int) $request->user()->id : null,
            'updated_at' => in_array('updated_at', $this->cols('t_request_ticket'), true) ? now() : null,
        ], fn ($v, $k) => $k !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * snapshot of the invoice BEFORE the change → t_ticket_history.
     * Plain insert (not TicketHistory::create): JSON-encoded here, only real columns,
     * and NOT NULL columns without a default get an empty value, so it can't fail on the table's setup.
     */
    private function saveHistory(object $t, ?object $live, $liveLines): void
    {
        $table = (new TicketHistory)->getTable();
        $json = json_encode(
            ['master' => (array) $live, 'details' => collect($liveLines)->map(fn ($r) => (array) $r)->all()],
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        $now = now();
        $row = $this->only($table, [
            'ticket_no' => $t->ticket_no, 'reference_no' => $t->reference_no, 'table_data' => $json,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (Schema::getColumns($table) as $c) {
            if (array_key_exists($c['name'], $row) || $c['nullable'] || $c['default'] !== null || $c['auto_increment']) continue;
            $type = strtolower((string) $c['type_name']);
            $row[$c['name']] = preg_match('/int|decimal|float|double|bit/', $type) ? 0
                : (preg_match('/date|time/', $type) ? $now : '');
        }
        DB::table($table)->insert($row);
    }

    public function ticketApprove(Request $request, string $ticketNo)
    {
        $this->staffOnly($request);

        try {
            return $this->approveTicket($request, $ticketNo);
        } catch (\Illuminate\Database\QueryException $e) {
            // show the real database reason instead of "Server Error" (full error still in laravel.log)
            report($e);
            abort(422, 'Could not approve: ' . ($e->errorInfo[2] ?? $e->getMessage()));
        }
    }

    private function approveTicket(Request $request, string $ticketNo)
    {
        return DB::transaction(function () use ($request, $ticketNo) {
            $t = RequestTicket::where('ticket_no', $ticketNo)->lockForUpdate()->latest('id')->first();
            abort_unless($t, 404, 'Ticket not found.');
            abort_unless((int) $t->is_status === self::PENDING, 422, 'This ticket was already ' . ((int) $t->is_status === self::APPROVED ? 'approved.' : 'declined.'));
            $where = $this->locate((string) $t->reference_no);
            abort_unless($where, 404, 'The invoice of this ticket was not found.');
            [$type, $mt, $dt, $partyCol] = $where;

            $live = DB::table($mt)->where('invoice_no', $t->reference_no)->lockForUpdate()->first();
            $liveLines = DB::table($dt)->where('invoice_no', $t->reference_no)->orderBy('id')->get();
            $action = strtoupper((string) $t->action);
            $now = now();

            // 1) snapshot BEFORE the change (old tbl2_history)
            $this->saveHistory($t, $live, $liveLines);

            if ($action === 'VOID') {
                // 2) VOID (old doVoidInvoice / doVoidAP): status 5, lines removed (they stay in the snapshot)
                DB::table($mt)->where('id', $live->id)->update($this->only($mt, ['invoice_status_id' => self::VOID, 'updated_at' => $now]));
                DB::table($dt)->where('invoice_no', $live->invoice_no)->delete();
            } else {
                // 2) EDIT / CANCEL (old doUpdateTicketSalesAll / doUpdateTickeAPAll): the requested data becomes the invoice
                $data = TicketData::where('ticket_no', $t->ticket_no)->latest('id')->first();
                abort_unless($data, 422, 'This ticket has no data to apply.');
                $m = $this->arr($data->data_master);
                $lines = $this->arr($data->data_detail);
                abort_if(!$lines, 422, 'No details inserted');

                // sales tickets carry customer_id, AP tickets vendor_id
                if (array_key_exists('customer_id', $m) && $partyCol === 'vendor_id' && !array_key_exists('vendor_id', $m)) $m['vendor_id'] = $m['customer_id'];
                unset($m['id'], $m['table_id'], $m['invoice_no'], $m['created_at']);
                $m['updated_at'] = $now;
                // a REVERTED invoice goes back to the auditor as PENDING (old: AuditStatusID 3 → 1)
                $audit = (int) ($live->audit_status_id ?? 0) === self::AUDIT_REVERTED ? self::AUDIT_PENDING : ($live->audit_status_id ?? null);
                if ($audit !== null) $m['audit_status_id'] = $audit;
                DB::table($mt)->where('id', $live->id)->update($this->fkClean($mt, $this->only($mt, $m)));

                $master = DB::table($mt)->where('id', $live->id)->first();
                $first = (array) ($liveLines->first() ?? []);
                $fk = $this->pick($dt, ['invoice_master_id', 'sales_invoice_master_id', 'purchase_expense_master_id', 'master_id', 'invoice_id']);

                DB::table($dt)->where('invoice_no', $live->invoice_no)->delete();
                $saved = 0;
                foreach ($lines as $l) {
                    unset($l['id'], $l['table_id']);
                    $row = $l + array_filter([
                        'invoice_no'             => $master->invoice_no,
                        $fk ?? ''                => $fk ? $master->id : null,
                        'added_by'               => $master->added_by,
                        $partyCol                => $master->{$partyCol} ?? null,
                        'transaction_date'       => $master->transaction_date ?? null,
                        'declared_month'         => $master->declared_month ?? null,
                        'document_no'            => $master->document_no ?? null,
                        'currency'               => $master->currency ?? null,
                        'ex_rate'                => $master->ex_rate ?? null,
                        'invoice_status_id'      => $master->invoice_status_id ?? null,
                        'audit_status_id'        => $master->audit_status_id ?? null,
                        'assigned_daid'          => $master->assigned_daid ?? null,
                        'assigned_auid'          => $master->assigned_auid ?? null,
                        'trans_type'             => $master->trans_type ?? ($first['trans_type'] ?? null),
                        '2307_vt_declared_month' => $master->{'2307_vt_declared_month'} ?? null,
                        '2307_it_declared_month' => $master->{'2307_it_declared_month'} ?? null,
                        'created_at'             => $first['created_at'] ?? $now,
                        'updated_at'             => $now,
                    ], fn ($v, $k) => $k !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
                    // the master's values win for the shared columns (same as the old copy)
                    foreach (['invoice_no', 'added_by', 'invoice_status_id', 'audit_status_id', 'declared_month', 'document_no', 'currency', 'ex_rate'] as $k) {
                        if (isset($master->{$k})) $row[$k] = $master->{$k};
                    }
                    // DA / auditor always from the invoice (the ticket lines may carry 0)
                    foreach (['assigned_daid', 'assigned_auid'] as $k) {
                        if (property_exists($master, $k)) $row[$k] = $master->{$k};
                    }
                    DB::table($dt)->insert($this->fkClean($dt, $this->only($dt, $row)));
                    $saved++;
                }
                abort_if($saved === 0, 422, 'No details inserted');
            }

            // 3) ticket approved
            $t->forceFill(['is_status' => self::APPROVED] + $this->stamp($request))->save();

            return response()->json(['message' => 'Ticket Successfully Approved']);
        });
    }

    public function ticketDecline(Request $request, string $ticketNo)
    {
        $this->staffOnly($request);
        return DB::transaction(function () use ($request, $ticketNo) {
            $t = RequestTicket::where('ticket_no', $ticketNo)->lockForUpdate()->latest('id')->first();
            abort_unless($t, 404, 'Ticket not found.');
            abort_unless((int) $t->is_status === self::PENDING, 422, 'This ticket was already ' . ((int) $t->is_status === self::APPROVED ? 'approved.' : 'declined.'));
            // the invoice is not touched; the requested data is kept so it can still be viewed
            $t->forceFill(['is_status' => self::DECLINED] + $this->stamp($request))->save();
            return response()->json(['message' => 'Ticket Successfully Declined']);
        });
    }


   /* =====================================================================
       DECLARED MONTH SETTINGS  (old pages/Administration/Declared_Month_Settings.php)
       Paste INSIDE AdministrationController (it uses its cols / pick / only / staffOnly helpers).
       Add at the top of the file:  use App\Models\DeclaredMonthSettings;

       Grid: one row per client account, Jan … Dec of the year, checked = locked.
       A locked month = a row in DeclaredMonthSettings (ca_id + declared_month 'YYYY-MM');
       Sales / Purchases / Expenses already refuse saving into it (lockedMonth / apLocked).

       GET  /admin/declared-months?year=2026     accounts + their locked months of that year
       POST /admin/declared-months               { caids: [1,2], months: ["2026-01"], locked: true|false }
                                                 lock / unlock one cell, a whole row or a whole column
       ===================================================================== */

    private function dmTable(): string
    {
        return (new DeclaredMonthSettings)->getTable();
    }

    public function declaredMonthAccounts(Request $request)
    {
        $this->staffOnly($request);
        $d = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) ($d['year'] ?? date('Y'));

        $tin = $this->pick('m_client_account', ['tin', 'tin_no']);
        $status = $this->cols('m_client_account_status') ? $this->pick('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']) : null;

        $q = DB::table('m_client_account as B')->leftJoin('m_client as A', 'A.client_num', '=', 'B.client_num');
        if ($status) $q->leftJoin('m_client_account_status as S', 'S.id', '=', 'B.status_id');
        $accounts = $q->orderBy('B.client_num')->orderBy('B.branch_code')->get([
            'B.id as caid', 'B.client_num', 'B.trade_name', 'B.branch_code', 'B.branch_type',
            DB::raw("TRIM(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name)) as client_name"),
            DB::raw($tin ? "B.{$tin} as tin" : "'' as tin"),
            DB::raw($status ? "S.{$status} as status" : "'' as status"),
        ]);

        // locked months of the year, one query: caid => ['2026-01', …]
        $locked = [];
        foreach (DB::table($this->dmTable())->where('declared_month', 'like', "{$year}-%")->get(['ca_id', 'declared_month']) as $r) {
            $locked[(int) $r->ca_id][] = $r->declared_month;
        }

        return response()->json([
            'year' => $year,
            'rows' => $accounts->map(fn ($r) => [
                'caid'        => (int) $r->caid,
                'client_num'  => $r->client_num,
                'client_name' => (string) $r->client_name,
                'trade_name'  => (string) $r->trade_name,
                'tin'         => (string) $r->tin,
                'status'      => (string) $r->status,
                'branch_code' => (string) $r->branch_code,
                'branch_type' => (string) $r->branch_type,
                'months'      => array_values(array_unique($locked[(int) $r->caid] ?? [])),
            ])->values(),
        ]);
    }

    public function declaredMonthSet(Request $request)
    {
        $this->staffOnly($request);
        $d = $request->validate([
            'caids'    => ['required', 'array', 'min:1'],
            'caids.*'  => ['integer'],
            'months'   => ['required', 'array', 'min:1', 'max:12'],
            'months.*' => ['date_format:Y-m'],
            'locked'   => ['required', 'boolean'],
        ]);
        $t = $this->dmTable();
        $caids = DB::table('m_client_account')->whereIn('id', array_unique($d['caids']))->pluck('id')->map(fn ($v) => (int) $v)->all();
        abort_if(!$caids, 404, 'Client account not found.');
        $months = array_values(array_unique($d['months']));

        return DB::transaction(function () use ($t, $caids, $months, $d) {
            if (!$d['locked']) {
                $n = DB::table($t)->whereIn('ca_id', $caids)->whereIn('declared_month', $months)->delete();
                return response()->json(['message' => 'Declared Month successfully removed', 'changed' => $n]);
            }
            // lock: only add what isn't there yet (old doGetExistsDeclaredMonth)
            $have = DB::table($t)->whereIn('ca_id', $caids)->whereIn('declared_month', $months)->get(['ca_id', 'declared_month'])
                ->map(fn ($r) => $r->ca_id . '|' . $r->declared_month)->flip();
            $now = now();
            $rows = [];
            foreach ($caids as $ca) foreach ($months as $m) {
                if (!isset($have["{$ca}|{$m}"])) {
                    $rows[] = $this->only($t, ['ca_id' => $ca, 'declared_month' => $m, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) DB::table($t)->insert($chunk);
            return response()->json(['message' => 'Declared Month successfully lock', 'changed' => count($rows)]);
        });
    }
    /* =====================================================================
       USER LOGS  (staff activity written by App\Http\Middleware\LogStaffActivity → t_user_logs)
       Paste INSIDE AdministrationController (it uses its staffOnly helper).

       GET /admin/user-logs/lookups                     staff list + tables found in the logs
       GET /admin/user-logs?from=&to=&user_id=&action=&table=&q=&page=1&per=50
       GET /admin/user-logs/{id}                        one entry + the other statements of the same click
       ===================================================================== */

    public function userLogLookups(Request $request)
    {
        $this->staffOnly($request);

        // every staff login (m_user_staff) + the employee name when it has employee_id
        $uname = $this->pick('m_user_staff', ['username', 'user_name', 'email', 'name']);
        $emp = $this->pick('m_user_staff', ['employee_id', 'emp_id']);
        $q = DB::table('m_user_staff as U');
        $sel = ['U.id', DB::raw($uname ? "U.{$uname} AS username" : "'' AS username")];
        if ($emp && $this->cols('m_employee')) {
            $q->leftJoin('m_employee as E', 'E.id', '=', "U.{$emp}");
            $sel[] = DB::raw("TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name)) AS name");
        } else {
            $sel[] = DB::raw("'' AS name");
        }
        $staff = $q->get($sel)
            ->map(fn ($r) => ['id' => (int) $r->id, 'username' => (string) $r->username, 'name' => trim((string) $r->name)])
            ->sortBy(fn ($r) => strtoupper($r['name'] ?: $r['username']))->values();

        // tables: the ones already in the logs + every table of THIS database (so the list is never empty)
        $logged = $this->cols('t_user_logs') ? DB::table('t_user_logs')->distinct()->pluck('table_name')->all() : [];
        // only this app's database (Schema::getTables() also lists other databases on the same MySQL server)
        $all = array_column(DB::select(
            "SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        ), 'n');
        $tables = array_values(array_unique(array_filter([...$logged, ...$all], fn ($t) => $t !== '' && $t !== 't_user_logs'
            && !in_array($t, ['personal_access_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens'], true))));
        sort($tables);

        return response()->json(['staff' => $staff, 'tables' => $tables]);
    }

    public function userLogs(Request $request)
    {
        $this->staffOnly($request);
        $d = $request->validate([
            'from'    => ['required', 'date_format:Y-m-d'],
            'to'      => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer'],
            'action'  => ['nullable', 'in:INSERT,UPDATE,DELETE'],
            'table'   => ['nullable', 'string', 'max:64'],
            'q'       => ['nullable', 'string', 'max:100'],
            'page'    => ['nullable', 'integer', 'min:1'],
            'per'     => ['nullable', 'integer', 'in:15,25,50,100'],
        ]);
        $per = (int) ($d['per'] ?? 50);
        $page = (int) ($d['page'] ?? 1);

        $q = DB::table('t_user_logs')
            ->where('created_at', '>=', $d['from'] . ' 00:00:00')
            ->where('created_at', '<=', $d['to'] . ' 23:59:59')
            ->when($d['user_id'] ?? null, fn ($w, $v) => $w->where('user_staff_id', $v))
            ->when($d['action'] ?? null, fn ($w, $v) => $w->where('action', $v))
            ->when($d['table'] ?? null, fn ($w, $v) => $w->where('table_name', $v))
            ->when(trim($d['q'] ?? '') !== '' ? trim($d['q']) : null, function ($w, $v) {
                $like = '%' . addcslashes($v, '%_\\') . '%';
                $w->where(fn ($x) => $x->where('record_id', $v)->orWhere('path', 'like', $like)->orWhere('route_action', 'like', $like)
                    ->orWhere('username', 'like', $like)->orWhere('employee_name', 'like', $like)
                    ->orWhere('where_clause', 'like', $like)->orWhere('changes', 'like', $like));
            });

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, $per)->get([
            'id', 'request_id', 'user_staff_id', 'username', 'employee_name', 'action', 'table_name', 'record_id',
            'method', 'path', 'route_action', 'ip', 'created_at',
        ]);
        return response()->json(['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
    }

    public function userLogShow(Request $request, int $id)
    {
        $this->staffOnly($request);
        $log = DB::table('t_user_logs')->where('id', $id)->first();
        abort_unless($log, 404, 'Log not found.');
        $log->changes = $log->changes ? json_decode($log->changes, true) : null;
        $same = DB::table('t_user_logs')->where('request_id', $log->request_id)->where('id', '<>', $log->id)
            ->orderBy('id')->limit(200)->get(['id', 'action', 'table_name', 'record_id']);
        return response()->json(['log' => $log, 'same_request' => $same]);
    }

/* =========================================================================
       ADMINISTRATION → USER ACCESS  (m_user_staff only · Staff section of the sidebar only)

       m_user_staff_access  one row per page a staff login can open (path = sidebar link)
         no rows at all     → never set up: the login still sees every Staff page
         '#none'            → saved with nothing ticked

       GET /admin/user-access              staff logins + how many pages each has
       GET /admin/user-access/mine         { all, paths } for the logged-in staff (sidebar)
       GET /admin/user-access/{id}         { user, paths, saved }
       PUT /admin/user-access/{id}         { paths: [...] }  replaces the list
       ========================================================================= */
    private const ACCESS_TABLE = 'm_user_staff_access', ACCESS_NONE = '#none';

    private function accessReady(): void
    {
        abort_unless(Schema::hasTable(self::ACCESS_TABLE), 422, 'Run the m_user_staff_access migration first (php artisan migrate).');
    }

    /** m_user_staff row → { id, username, name, designation, user_type, is_active } */
    private function staffRows(?int $id = null)
    {
        $nameCol = fn (string $t) => $this->pick($t, ['name', 'description', 'user_type', 'user_access', 'UserType']);
        $typeName = $nameCol('m_user_type');
        $accName = $nameCol('m_user_access');
        $hasEmp = Schema::hasTable('m_employee');

        $q = DB::table('m_user_staff as U')
            ->when($hasEmp, fn ($q) => $q->leftJoin('m_employee as E', 'E.com_id', '=', 'U.comid'))
            ->when($typeName && $this->pick('m_user_staff', ['user_type_id']), fn ($q) => $q->leftJoin('m_user_type as T', 'T.id', '=', 'U.user_type_id'))
            ->when($typeName && $hasEmp && $this->pick('m_employee', ['designation_id']), fn ($q) => $q->leftJoin('m_user_type as D', 'D.id', '=', 'E.designation_id'))
            ->when($accName && $this->pick('m_user_staff', ['user_access_id']), fn ($q) => $q->leftJoin('m_user_access as UA', 'UA.id', '=', 'U.user_access_id'))
            ->when($id, fn ($q) => $q->where('U.id', $id))
            ->orderBy('U.username');

        $sel = ['U.id', 'U.username'];
        $sel[] = DB::raw($this->pick('m_user_staff', ['is_active']) ? 'U.is_active' : '1 AS is_active');
        $sel[] = DB::raw($hasEmp
            ? "TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name)) AS name"
            : "'' AS name");
        $sel[] = DB::raw(($typeName && $this->pick('m_user_staff', ['user_type_id']) ? "T.{$typeName}" : "''") . ' AS user_type');
        $sel[] = DB::raw(($typeName && $hasEmp && $this->pick('m_employee', ['designation_id']) ? "D.{$typeName}" : "''") . ' AS designation');
        $sel[] = DB::raw(($accName && $this->pick('m_user_staff', ['user_access_id']) ? "UA.{$accName}" : "''") . ' AS user_access');
        $sel[] = DB::raw($hasEmp && $this->pick('m_employee', ['photo_path']) ? 'E.photo_path AS photo' : 'NULL AS photo');

        return $q->get($sel)->map(fn ($r) => [
            'id'          => (int) $r->id,
            'username'    => (string) $r->username,
            'name'        => trim(preg_replace('/\s+/', ' ', (string) $r->name)) ?: (string) $r->username,
            'designation' => (string) ($r->designation ?? ''),
            'user_type'   => (string) ($r->user_type ?? ''),
            'user_access' => (string) ($r->user_access ?? ''),
            'is_active'   => (bool) $r->is_active,
            'photo'       => $this->photoUrl($r->photo ?? null),
        ]);
    }

    /** employee photo (S3 key in m_employee.photo_path) → link valid for 2 hours */
    private function photoUrl(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') return null;
        if (preg_match('/^https?:\/\//i', $key)) return $key;
        try {
            return Storage::disk('s3')->temporaryUrl($key, now()->addHours(2));
        } catch (\Throwable) {
            return null;
        }
    }

    /** the saved paths: null = never set up */
    private function accessPaths(int $userStaffId): ?array
    {
        $paths = DB::table(self::ACCESS_TABLE)->where('user_staff_id', $userStaffId)->pluck('path')->all();
        if (!$paths) return null;
        return array_values(array_filter($paths, fn ($p) => $p !== self::ACCESS_NONE));
    }

    public function userAccessList(Request $request)
    {
        $this->staffOnly($request);
        $this->accessReady();

        $counts = DB::table(self::ACCESS_TABLE)
            ->where('path', '<>', self::ACCESS_NONE)
            ->groupBy('user_staff_id')
            ->selectRaw('user_staff_id, COUNT(*) AS n')
            ->pluck('n', 'user_staff_id');
        $saved = DB::table(self::ACCESS_TABLE)->distinct()->pluck('user_staff_id')->flip();

        return response()->json($this->staffRows()->map(fn ($u) => $u + [
            'pages' => (int) ($counts[$u['id']] ?? 0),
            'saved' => isset($saved[$u['id']]),
        ])->values());
    }

    public function userAccessShow(Request $request, int $id)
    {
        $this->staffOnly($request);
        $this->accessReady();
        $user = $this->staffRows($id)->first();
        abort_if(!$user, 404, 'Staff login not found.');
        $paths = $this->accessPaths($id);

        return response()->json(['user' => $user, 'paths' => $paths ?? [], 'saved' => $paths !== null]);
    }

    public function userAccessSave(Request $request, int $id)
    {
        $this->staffOnly($request);
        $this->accessReady();
        $data = $request->validate([
            'paths'   => ['present', 'array', 'max:300'],
            'paths.*' => ['string', 'max:150', 'regex:/^\/[A-Za-z0-9\/_-]*$/'],
        ]);
        abort_unless(DB::table('m_user_staff')->where('id', $id)->exists(), 404, 'Staff login not found.');

        $paths = array_values(array_unique($data['paths']));
        $now = now();
        $by = (int) ($request->user()->id ?? 0) ?: null;

        DB::transaction(function () use ($id, $paths, $now, $by) {
            DB::table(self::ACCESS_TABLE)->where('user_staff_id', $id)->delete();
            $rows = array_map(fn ($p) => [
                'user_staff_id' => $id, 'path' => $p, 'added_by' => $by, 'created_at' => $now, 'updated_at' => $now,
            ], $paths ?: [self::ACCESS_NONE]);
            DB::table(self::ACCESS_TABLE)->insert($rows);
        });

        return response()->json([
            'message' => count($paths) ? 'User access saved: ' . count($paths) . ' page' . (count($paths) === 1 ? '' : 's') . '.' : 'User access saved: no Staff pages.',
            'paths'   => $paths,
        ]);
    }

    /**
     * GET /admin/user-access/{id}/clients
     * every client account + whether this staff's employee has a login there (m_user_client_staff.employee_id),
     * and whether they are the assigned DA / Auditor of it
     */
    public function userAccessClients(Request $request, int $id)
    {
        $this->staffOnly($request);
        $staff = DB::table('m_user_staff')->where('id', $id)->first();
        abort_unless($staff, 404, 'Staff login not found.');
        $empId = Schema::hasTable('m_employee') && isset($staff->comid)
            ? (int) DB::table('m_employee')->where('com_id', $staff->comid)->value('id') : 0;

        $da = $this->pick('m_client_account', ['assigned_daid', 'assigned_da_id']);
        $au = $this->pick('m_client_account', ['assigned_auid', 'assigned_auditor_id']);
        $hasClient = Schema::hasTable('m_client');
        $logins = $empId && Schema::hasTable('m_user_client_staff')
            ? DB::table('m_user_client_staff')->where('employee_id', $empId)->get()->groupBy('caid') : collect();

        $rows = DB::table('m_client_account as A')
            ->when($hasClient, fn ($q) => $q->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num'))
            ->orderBy('A.trade_name')->orderBy('A.branch_code')
            ->get(array_filter([
                'A.id', 'A.client_num', 'A.trade_name', 'A.branch_code', 'A.branch_type',
                $this->pick('m_client_account', ['is_active']) ? 'A.is_active' : null,
                $da ? DB::raw("A.{$da} AS da_id") : null,
                $au ? DB::raw("A.{$au} AS au_id") : null,
                DB::raw($hasClient ? "TRIM(CONCAT_WS(' ', C.last_name, C.first_name, C.middle_name)) AS client_name" : "'' AS client_name"),
            ]))
            ->map(function ($r) use ($logins, $empId) {
                $l = ($logins[$r->id] ?? collect())->first();
                return [
                    'caid'        => (int) $r->id,
                    'client_num'  => (string) $r->client_num,
                    'trade_name'  => (string) $r->trade_name,
                    'branch_code' => (string) $r->branch_code,
                    'branch_type' => (string) $r->branch_type,
                    'client_name' => trim(preg_replace('/\s+/', ' ', (string) $r->client_name)),
                    'active'      => !isset($r->is_active) || (int) $r->is_active === 1,
                    'role'        => $empId && (int) ($r->da_id ?? 0) === $empId ? 'DA' : ($empId && (int) ($r->au_id ?? 0) === $empId ? 'Auditor' : null),
                    'login'       => $l ? [
                        'id'       => (int) $l->id,
                        'username' => (string) $l->username,
                        'active'   => (int) ($l->is_active ?? 0) === 1,
                        'web'      => (int) ($l->is_web ?? 0) === 1,
                        'scan'     => (int) ($l->is_scan_suite ?? 0) === 1,
                        'pos'      => (int) ($l->is_pos ?? 0) === 1,
                    ] : null,
                ];
            })->values();

        return response()->json([
            'employee_id' => $empId ?: null,
            'rows'        => $rows,
            'counts'      => [
                'all'      => $rows->count(),
                'login'    => $rows->whereNotNull('login')->count(),
                'assigned' => $rows->whereNotNull('role')->count(),
            ],
        ]);
    }

    /** the sidebar: which Staff pages the logged-in staff can open */
    public function userAccessMine(Request $request)
    {
        $u = $request->user();
        abort_if(!$u, 401);
        if (!($u instanceof \App\Models\UserStaff) || !Schema::hasTable(self::ACCESS_TABLE)) {
            return response()->json(['all' => true, 'paths' => []]);
        }
        $paths = $this->accessPaths((int) $u->id);
        return response()->json(['all' => $paths === null, 'paths' => $paths ?? []]);
    }
    /* =========================================================================
       ADMINISTRATION → DATABASE  (old pages/Administration/Database + ajax/backup_db_s3.php, qry_*_files.php)

       S3 = your existing 's3' disk (config/filesystems.php / .env AWS_*), no keys in code.

       GET  /admin/database/files?tab=db|sales|purchases|expenses&q=&page=&per=   files in the S3 folder, newest first
       GET  /admin/database/files/download?tab=&name=                             the file
       POST /admin/database/backup  { username, password }                       supervisor (user type 1) → mysqldump → S3 Db_Fems_v2/
       ========================================================================= */
    private const DB_FOLDERS = [
        'db'        => 'Db_Fems_v2',
        'sales'     => 'Sales_Invoices',
        'purchases' => 'Vendor_Invoices',
        'expenses'  => 'Expenses_Transaction',
    ];

    private function dbFolder(Request $request): string
    {
        $tab = (string) $request->query('tab', 'db');
        abort_unless(isset(self::DB_FOLDERS[$tab]), 422, 'Unknown tab.');
        return self::DB_FOLDERS[$tab];
    }

    private function dbSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' bytes';
    }

    /** every file in the folder (one S3 listing, cached 60s) */
    private function dbList(string $folder, bool $fresh = false): array
    {
        $key = "admin-db-files:{$folder}";
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);
        return \Illuminate\Support\Facades\Cache::remember($key, 60, function () use ($folder) {
            $rows = [];
            foreach (Storage::disk('s3')->getDriver()->listContents($folder, false) as $item) {
                if (!$item->isFile()) continue;
                $name = basename($item->path());
                if ($name === '') continue;
                $rows[] = [
                    'name'          => $name,
                    'size'          => (int) ($item->fileSize() ?? 0),
                    'last_modified' => (int) ($item->lastModified() ?? 0),
                ];
            }
            usort($rows, fn ($a, $b) => $b['last_modified'] <=> $a['last_modified']);
            return $rows;
        });
    }

    public function databaseFiles(Request $request)
    {
        $this->staffOnly($request);
        $folder = $this->dbFolder($request);
        try {
            $rows = $this->dbList($folder, $request->boolean('fresh'));
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'S3 error: ' . $e->getMessage()], 422);
        }

        $q = mb_strtolower(trim((string) $request->query('q', '')));
        if ($q !== '') $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name']), $q)));

        $per = min(max((int) $request->query('per', 15), 5), 100);
        $total = count($rows);
        $last = max(1, (int) ceil($total / $per));
        $page = min(max((int) $request->query('page', 1), 1), $last);
        $tz = new \DateTimeZone('Asia/Manila');

        return response()->json([
            'data' => array_map(fn ($r) => [
                'name'     => $r['name'],
                'size'     => $this->dbSize($r['size']),
                'bytes'    => $r['size'],
                'modified' => $r['last_modified'] ? (new \DateTime('@' . $r['last_modified']))->setTimezone($tz)->format('M d Y h:i:s A') : '',
            ], array_slice($rows, ($page - 1) * $per, $per)),
            'total' => $total, 'page' => $page, 'per' => $per, 'last_page' => $last,
        ]);
    }

    public function databaseDownload(Request $request)
    {
        $this->staffOnly($request);
        $folder = $this->dbFolder($request);
        $name = basename((string) $request->query('name', ''));
        abort_if($name === '' || $name === '.' || $name === '..', 422, 'Pick a file.');

        $disk = Storage::disk('s3');
        $key = "{$folder}/{$name}";
        abort_unless($disk->exists($key), 404, "{$name} is not on S3.");

        return response()->stream(function () use ($disk, $key) {
            $s = $disk->readStream($key);
            fpassthru($s);
            fclose($s);
        }, 200, [
            'Content-Type'        => $disk->mimeType($key) ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . addslashes($name) . '"',
            'Content-Length'      => (string) $disk->size($key),
        ]);
    }

    /** BACKUP DATABASE: supervisor override (old doGetSupervisorList: active, user type 1) → mysqldump → S3 */
    public function databaseBackup(Request $request)
    {
        $this->staffOnly($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ], ['username.required' => 'Username is required', 'password.required' => 'Password is required']);

        $sup = DB::table('m_user_staff')->where('username', $data['username'])
            ->when($this->pick('m_user_staff', ['is_active']), fn ($q) => $q->where('is_active', 1))
            ->when($this->pick('m_user_staff', ['user_type_id']), fn ($q) => $q->whereIn('user_type_id', [1]))
            ->first();
        $stored = (string) ($sup->password ?? '');
        $ok = $sup && $stored !== '' && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon'))
            && \Illuminate\Support\Facades\Hash::check($data['password'], $stored);
        abort_unless($ok, 422, 'User not found');

        @set_time_limit(0);
        $conn = config('database.connections.' . config('database.default'));
        $dbName = (string) ($conn['database'] ?? '');
        abort_if($dbName === '', 422, 'No database configured.');

        $dump = env('MYSQLDUMP_PATH') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
        abort_if(PHP_OS_FAMILY === 'Windows' && !is_file($dump), 422, "mysqldump not found at: {$dump} (set MYSQLDUMP_PATH in .env)");

        $file = storage_path('app/backup/' . $dbName . '_' . now('Asia/Manila')->format('Y-m-d_H-i-s') . '.sql');
        @mkdir(dirname($file), 0777, true);

        $cmd = [$dump, '--user=' . ($conn['username'] ?? 'root'), '--host=' . ($conn['host'] ?? '127.0.0.1'),
            '--port=' . ($conn['port'] ?? 3306), '--routines', '--triggers', '--single-transaction',
            '--result-file=' . $file, $dbName];
        $env = array_merge(getenv() ?: [], ['MYSQL_PWD' => (string) ($conn['password'] ?? '')]);   // password not on the command line
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        abort_unless(is_resource($proc), 422, 'Could not start mysqldump.');
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        try {
            if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
                abort(422, "Backup failed (code {$code}): " . (trim((string) $err) ?: 'No error output captured'));
            }
            $size = filesize($file);
            $key = self::DB_FOLDERS['db'] . '/' . basename($file);
            $in = fopen($file, 'r');
            Storage::disk('s3')->writeStream($key, $in, ['ServerSideEncryption' => 'AES256', 'StorageClass' => 'STANDARD_IA']);
            if (is_resource($in)) fclose($in);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            abort(422, 'S3 error: ' . $e->getMessage());
        } finally {
            @unlink($file);
        }
        \Illuminate\Support\Facades\Cache::forget('admin-db-files:' . self::DB_FOLDERS['db']);

        return response()->json([
            'message' => 'Database successfully backup',
            'file'    => basename($file),
            'size'    => $this->dbSize((int) $size),
        ]);
    }
}