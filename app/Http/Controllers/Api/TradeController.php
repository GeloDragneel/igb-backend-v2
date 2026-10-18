<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\DeclaredMonthSettings;
use App\Models\DocumentType;
use App\Models\RequestTicket;
use App\Models\TicketData;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Shared logic for Importation and Exportation (old Importation page).
 * ImportationController / ExportationController only describe their tables, the line formula and the journal.
 *
 * The React pages send and receive the same neutral keys for both:
 *   party_id, date, invoice_no, entry_no, country, pay_date, currency, ex_rate, remarks,
 *   document_type_id, declared_month, account_code, lines[]
 */
abstract class TradeController extends SalesController
{
    /** @var array{master:string,detail:string,fk:string,no:string,party:string,partyTable:string,date:string,entry:string,country:string,pay:?string,prefix:string,folder:string,module:string,inputs:array,amounts:array,totals:array} */
    protected array $cfg;

    /** line inputs -> all detail amount columns */
    abstract protected function calcLine(array $l, float $exRate): array;

    /** journal rows: [account_code, account_name, debit, credit] */
    abstract protected function journalRows(object $m, $lines, $names, ?object $vatAccount): array;

    /** amount shown in the list "Total" column */
    abstract protected function listTotal(object $m): float;

    const VOID = 5;

    /* ------------------------------------------------------------------ helpers */
    private static array $cols = [];

    protected function only(string $table, array $row): array
    {
        $cols = self::$cols[$table] ??= Schema::getColumnListing($table);
        return array_intersect_key($row, array_flip($cols));
    }

    protected function base(int $addedBy)
    {
        return DB::table($this->cfg['master'])->where('added_by', $addedBy);
    }

    protected function find(int $addedBy, string $col, $value): ?object
    {
        return $this->base($addedBy)->where($col, $value)->first();
    }

    protected function locked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    protected function ymLabel(?string $ym): string
    {
        return $ym && preg_match('/^\d{4}-\d{2}$/', $ym) ? Carbon::createFromFormat('Y-m-d', "$ym-01")->format('F Y') : (string) $ym;
    }

    protected function rules(): array
    {
        $r = [
            'party_id'         => ['required', 'integer', 'min:1'],
            'date'             => ['required', 'date'],
            'invoice_no'       => ['required', 'string', 'max:100'],
            'entry_no'         => ['nullable', 'string', 'max:100'],
            'country'          => ['nullable', 'string', 'max:60'],
            'pay_date'         => ['nullable', 'date'],
            'currency'         => ['nullable', 'string', 'max:10'],
            'ex_rate'          => ['nullable', 'numeric', 'gt:0'],
            'remarks'          => ['nullable', 'string', 'max:255'],
            'document_type_id' => ['nullable', 'integer'],
            'declared_month'   => ['required', 'date_format:Y-m'],
            'account_code'     => ['required', 'string', 'max:20'],
            'lines'                => ['required', 'array', 'min:1'],
            'lines.*.item_id'      => ['nullable', 'integer'],
            'lines.*.account_code' => ['required', 'string', 'max:20'],
        ];
        foreach ($this->cfg['inputs'] as $k) $r["lines.*.$k"] = ['nullable', 'numeric', 'min:0'];
        return $r;
    }

    protected function messages(): array
    {
        return [
            'party_id.required' => 'Please select ' . ($this->cfg['party'] === 'seller_id' ? 'seller' : 'buyer'),
            'party_id.min'      => 'Please select ' . ($this->cfg['party'] === 'seller_id' ? 'seller' : 'buyer'),
            'invoice_no.required' => 'Please input invoice #',
            'account_code.required' => 'Accounts is required.',
            'lines.required' => 'Please add item', 'lines.min' => 'Please add item',
            'lines.*.account_code.required' => 'Each line needs an account.',
        ];
    }

    /** master + detail rows from the request */
    protected function build(array $d, int $addedBy, string $no, int $status): array
    {
        $c = $this->cfg;
        $ex = (float) ($d['ex_rate'] ?? 1) ?: 1;

        $header = [
            $c['party'] => (int) $d['party_id'], $c['date'] => $d['date'], 'invoice_no' => $d['invoice_no'],
            $c['entry'] => $d['entry_no'] ?? null, $c['country'] => $d['country'] ?? null,
            'currency' => $d['currency'] ?? 'PHP', 'ex_rate' => $ex,
            'document_type_id' => (int) ($d['document_type_id'] ?? 0), 'declared_month' => $d['declared_month'],
            'invoice_status_id' => $status, 'added_by' => $addedBy,
        ];
        if ($c['pay']) $header[$c['pay']] = $d['pay_date'] ?? null;

        $lines = [];
        foreach ($d['lines'] as $l) {
            $lines[] = [
                'item_id' => (int) ($l['item_id'] ?? 0), 'account_code' => $l['account_code'],
            ] + $this->calcLine($l, $ex);
        }

        $master = $header + [
            $c['no'] => $no, 'remarks' => $d['remarks'] ?? null, 'account_code' => $d['account_code'],
        ];
        foreach ($c['totals'] as $mCol => $dCol) {
            $master[$mCol] = $this->r4(array_sum(array_column($lines, $dCol)));
        }
        $details = array_map(fn ($l) => $l + $header + [$c['no'] => $no], $lines);

        return [$master, $details];
    }

    /* ------------------------------------------------------------------ list */
    public function trIndex(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json([]);
        $c = $this->cfg;

        $list = $this->base($addedBy)->whereBetween('declared_month', [$d['from'], $d['to']])->orderByDesc('id')->get();
        $parties = DB::table($c['partyTable'])->whereIn('id', $list->pluck($c['party'])->filter()->unique())->pluck('name', 'id');
        $tickets = RequestTicket::whereIn('reference_no', $list->pluck($c['no']))->where('is_status', 0)->pluck('ticket_no', 'reference_no');

        return response()->json($list->map(fn ($m) => [
            'id'             => $m->id,
            'no'             => $m->{$c['no']},
            'party_name'     => $parties[$m->{$c['party']}] ?? '—',
            'date'           => $m->{$c['date']} ? substr((string) $m->{$c['date']}, 0, 10) : null,
            'declared_month' => $this->ymLabel($m->declared_month),
            'invoice_no'     => $m->invoice_no,
            'entry_no'       => $m->{$c['entry']},
            'country'        => $m->{$c['country']},
            'pay_date'       => $c['pay'] && $m->{$c['pay']} ? substr((string) $m->{$c['pay']}, 0, 10) : null,
            'total'          => $this->listTotal($m),
            'status'         => (int) $m->invoice_status_id === self::VOID ? 'VOID' : 'ACTIVE',
            'ticket_no'      => $tickets[$m->{$c['no']}] ?? null,
        ])->values());
    }

    /* ------------------------------------------------------------------ lookups */
    public function trLookups(Request $request)
    {
        $addedBy = $this->addedByFor($request);
        $vatCol = in_array('input_vat_account', Schema::getColumnListing('m_items'), true) ? 'input_vat_account' : null;

        return response()->json([
            'parties' => DB::table($this->cfg['partyTable'])->where('added_by', $addedBy)->orderBy('name')->get(['id', 'name', 'address', 'tin']),
            'items'   => DB::table('m_items')->where('added_by', $addedBy)->orderBy('item_code')
                ->get(array_filter(['id', 'item_code', 'description', $vatCol])),
            'accounts' => ChartsOfAccount::where('added_by', $addedBy)->where('root_name', '!=', '0')->orderBy('account_code')
                ->get(['account_code', 'account_name']),
            'document_types' => DocumentType::whereRaw('CHAR_LENGTH(name) > 3')->orderBy('name')->get(['id', 'name']),
            'locked_months'  => DeclaredMonthSettings::where('ca_id', $this->caidFor($request))->pluck('declared_month'),
        ]);
    }

    /* POST /{module}/parties  quick add seller / buyer */
    public function trStoreParty(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:255'], 'tin' => ['nullable', 'string', 'max:20'],
        ]);
        $id = DB::table($this->cfg['partyTable'])->insertGetId($d + [
            'added_by' => $this->addedByFor($request), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id] + $d);
    }

    /* ------------------------------------------------------------------ one record */
    public function trShow(Request $request, string $no)
    {
        $c = $this->cfg;
        $m = $this->find($this->addedByFor($request), $c['no'], $no);
        if (!$m) return response()->json(['message' => 'Record not found.'], 404);

        $lines = DB::table($c['detail'])->where($c['no'], $m->{$c['no']})->orderBy('id')->get();

        return response()->json([
            'record' => [
                'id' => $m->id, 'no' => $m->{$c['no']}, 'party_id' => $m->{$c['party']},
                'date' => $m->{$c['date']} ? substr((string) $m->{$c['date']}, 0, 10) : '',
                'invoice_no' => $m->invoice_no, 'entry_no' => $m->{$c['entry']}, 'country' => $m->{$c['country']},
                'pay_date' => $c['pay'] && $m->{$c['pay']} ? substr((string) $m->{$c['pay']}, 0, 10) : '',
                'currency' => $m->currency, 'ex_rate' => (float) $m->ex_rate, 'remarks' => $m->remarks,
                'document_type_id' => $m->document_type_id, 'declared_month' => $m->declared_month,
                'account_code' => $m->account_code, 'attachment' => $m->attachment,
                'status' => (int) $m->invoice_status_id === self::VOID ? 'VOID' : 'ACTIVE',
            ],
            'lines' => $lines->map(fn ($l) => ['id' => $l->id, 'item_id' => $l->item_id, 'account_code' => $l->account_code]
                + array_map('floatval', array_intersect_key((array) $l, array_flip($c['amounts'])))),
            'party'  => DB::table($c['partyTable'])->where('id', $m->{$c['party']})->first(['id', 'name', 'address', 'tin']),
            'ticket' => RequestTicket::where('reference_no', $m->{$c['no']})->where('is_status', 0)->latest('id')
                ->first(['ticket_no', 'remarks', 'action', 'created_at']),
        ]);
    }

    public function trJournal(Request $request, string $no)
    {
        $addedBy = $this->addedByFor($request);
        $c = $this->cfg;
        $m = $this->find($addedBy, $c['no'], $no);
        if (!$m || (int) $m->invoice_status_id === self::VOID) return response()->json(['journal' => [], 'collection' => []]);

        $lines = DB::table($c['detail'] . ' as L')->leftJoin('m_items as I', 'I.id', '=', 'L.item_id')
            ->where('L.' . $c['no'], $no)->get(['L.*']);
        $names = ChartsOfAccount::where('added_by', $addedBy)->pluck('account_name', 'account_code');
        $vatAcc = ChartsOfAccount::where('added_by', $addedBy)->whereRaw("account_name LIKE '%input%vat%'")
            ->orderBy('account_code')->first(['account_code', 'account_name']);

        $rows = array_map(fn ($r) => [
            'account_code' => (string) $r[0], 'account_name' => (string) $r[1],
            'debit' => round((float) $r[2], 2), 'credit' => round((float) $r[3], 2),
        ], $this->journalRows($m, $lines, $names, $vatAcc));

        return response()->json(['journal' => array_values(array_filter($rows, fn ($r) => $r['debit'] || $r['credit'])), 'collection' => []]);
    }

    /* ------------------------------------------------------------------ save new */
    public function trStore(Request $request)
    {
        $d = $request->validate($this->rules(), $this->messages());
        $addedBy = $this->addedByFor($request);
        if (!$addedBy) return response()->json(['message' => 'No client account selected.'], 422);
        $c = $this->cfg;

        if ($this->locked($this->caidFor($request), [$d['declared_month']])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }
        if ($this->base($addedBy)->where('invoice_no', $d['invoice_no'])->where('invoice_status_id', '<>', self::VOID)->exists()) {
            return response()->json(['message' => 'Document number already in use'], 422);
        }

        return DB::transaction(function () use ($d, $addedBy, $c) {
            do {
                $no = $c['prefix'] . date('ymd-his') . random_int(10000, 99999);
            } while (DB::table($c['master'])->where($c['no'], $no)->exists());

            [$master, $details] = $this->build($d, $addedBy, $no, 1);
            $id = DB::table($c['master'])->insertGetId($this->only($c['master'], $master + ['created_at' => now(), 'updated_at' => now()]));
            foreach ($details as $row) {
                DB::table($c['detail'])->insert($this->only($c['detail'], $row + [$c['fk'] => $id, 'created_at' => now(), 'updated_at' => now()]));
            }
            return response()->json(['id' => $id, 'no' => $no]);
        });
    }

    /* ------------------------------------------------------------------ EDIT / VOID ticket */
    public function trTicket(Request $request, int $id)
    {
        $payload = $request->filled('data') ? (json_decode($request->input('data'), true) ?? []) : $request->except('attachment');
        $d = Validator::make($payload,
            ['ticket_remarks' => ['required', 'string', 'max:1000'], 'action' => ['nullable', 'in:EDIT,VOID']] + $this->rules(),
            ['ticket_remarks.required' => 'Remarks / Reason is required.'] + $this->messages()
        )->validate();

        $addedBy = $this->addedByFor($request);
        $c = $this->cfg;
        $m = $this->find($addedBy, 'id', $id);
        if (!$m) return response()->json(['message' => 'Record not found.'], 404);
        if ($this->locked($this->caidFor($request), [$m->declared_month, $d['declared_month']])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }
        if (RequestTicket::where('reference_no', $m->{$c['no']})->where('is_status', 0)->exists()) {
            return response()->json(['message' => 'Ticket is pending for this record. Please wait for approval.'], 422);
        }

        $attachment = $m->attachment;
        if ($request->hasFile('attachment')) {
            $file  = $request->file('attachment');
            $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
            Storage::disk('s3')->putFileAs($c['folder'], $file, $m->id . '-' . $clean);
            $attachment = $clean;
        }

        $action = $d['action'] ?? 'EDIT';
        [$master, $details] = $this->build($d, $addedBy, $m->{$c['no']}, $action === 'VOID' ? self::VOID : (int) $m->invoice_status_id);
        $master += ['table_id' => $m->id, 'attachment' => $attachment];

        $ticketNo = 'TCKT' . date('ymd-his') . random_int(10000, 99999);
        DB::transaction(function () use ($m, $c, $d, $addedBy, $ticketNo, $action, $master, $details) {
            RequestTicket::create([
                'ticket_no' => $ticketNo, 'reference_no' => $m->{$c['no']}, 'remarks' => $d['ticket_remarks'],
                'module' => $c['module'], 'action' => $action, 'request_by' => $addedBy, 'is_status' => 0,
            ]);
            TicketData::create(['ticket_no' => $ticketNo, 'data_master' => $master, 'data_detail' => $details]);
        });

        return response()->json(['message' => 'Ticket successfully saved', 'ticket_no' => $ticketNo]);
    }

    public function trTicketShow(Request $request, string $ticketNo)
    {
        $ticket = RequestTicket::where('ticket_no', $ticketNo)->where('request_by', $this->addedByFor($request))
            ->first(['ticket_no', 'reference_no', 'remarks', 'action', 'created_at']);
        if (!$ticket) return response()->json(['message' => 'Ticket not found.'], 404);
        $data = TicketData::where('ticket_no', $ticketNo)->first();
        return response()->json(['ticket' => $ticket, 'master' => $data->data_master ?? [], 'details' => $data->data_detail ?? []]);
    }

    /* ------------------------------------------------------------------ attachment */
    public function trUploadAttachment(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif']]);
        $m = $this->find($this->addedByFor($request), 'id', $id);
        if (!$m) return response()->json(['message' => 'Record not found.'], 404);

        $file  = $request->file('file');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
        Storage::disk('s3')->putFileAs($this->cfg['folder'], $file, $m->id . '-' . $clean);
        DB::table($this->cfg['master'])->where('id', $m->id)->update(['attachment' => $clean, 'updated_at' => now()]);
        return response()->json(['attachment' => $clean]);
    }

    public function trAttachment(Request $request, int $id)
    {
        $m = $this->find($this->addedByFor($request), 'id', $id);
        if (!$m) return response()->json(['message' => 'No attachment.'], 404);

        $file = trim((string) ($m->attachment ?? ''));
        if ($file === '') return response()->json(['message' => 'No attachment.'], 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        foreach (["{$this->cfg['folder']}/{$m->id}-{$file}", "{$this->cfg['folder']}/{$file}"] as $key) {
            if ($disk->exists($key)) return $disk->response($key, $file);
        }

        return response()->json(['message' => 'File not found on storage.'], 404);
    }
}
