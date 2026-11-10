<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Billing → Send Requests
 *   "Send via" on a printed SOA is saved here first (t_send_request, PDF in S3 Send_Request/)
 *   and only goes out after a supervisor confirms it.
 *   PENDING   → waiting for approval
 *   REVISED   → returned to the requester with remarks; edit + resubmit → PENDING
 *   DECLINED  → rejected (remarks)
 *   CONFIRMED → approved: email sent with the PDF; chat apps open with a 7-day link
 *
 * routes/api.php (inside the auth:sanctum group):
 *   use App\Http\Controllers\Api\SendRequestController;
 *   Route::get('/send-requests', [SendRequestController::class, 'index']);
 *   Route::get('/send-requests/contacts', [SendRequestController::class, 'contacts']);
 *   Route::post('/send-requests', [SendRequestController::class, 'store']);
 *   Route::put('/send-requests/{id}', [SendRequestController::class, 'update'])->whereNumber('id');
 *   Route::get('/send-requests/{id}/pdf', [SendRequestController::class, 'pdf'])->whereNumber('id');
 *   Route::post('/send-requests/{id}/confirm', [SendRequestController::class, 'confirm'])->whereNumber('id');
 *   Route::post('/send-requests/{id}/decline', [SendRequestController::class, 'decline'])->whereNumber('id');
 *   Route::post('/send-requests/{id}/revise', [SendRequestController::class, 'revise'])->whereNumber('id');
 *   Route::post('/send-requests/{id}/link', [SendRequestController::class, 'link'])->whereNumber('id');
 */
class SendRequestController extends Controller
{
    const T = 't_send_request';
    const APPROVER_TYPES = [4];   // same as BillingController::SUPERVISOR_TYPES (m_user_staff.user_type_id)
    const STATUSES = ['PENDING', 'REVISED', 'DECLINED', 'CONFIRMED'];
    const CHANNELS = ['email', 'viber', 'messenger', 'wechat', 'whatsapp', 'telegram'];

    /* ---------------- helpers ---------------- */

    private function canApprove(Request $request): bool
    {
        $u = $request->user();
        return $u && in_array((int) ($u->user_type_id ?? 0), self::APPROVER_TYPES, true);
    }

    private function row(int $id): object
    {
        $r = DB::table(self::T)->where('id', $id)->first();
        abort_if(!$r, 404, 'Request not found.');
        return $r;
    }

    private function json($v): array
    {
        $a = json_decode((string) $v, true);
        return is_array($a) ? $a : [];
    }

    /** staff id → username */
    private function names(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids)));
        if (!$ids || !Schema::hasTable('m_user_staff')) return [];
        return DB::table('m_user_staff')->whereIn('id', $ids)->pluck('username', 'id')->all();
    }

    private function out(object $r, array $names): array
    {
        return [
            'id' => (int) $r->id,
            'doc_type' => $r->doc_type,
            'reference_no' => $r->reference_no,
            'print_type' => $r->print_type,
            'service_id' => $r->service_id ? (int) $r->service_id : null,
            'label' => $r->label,
            'client_num' => $r->client_num,
            'party_name' => $r->party_name,
            'channels' => $this->json($r->channels),
            'emails' => $this->json($r->emails),
            'contacts' => $this->json($r->contacts),
            'subject' => $r->subject,
            'message' => $r->message,
            'status' => $r->status,
            'remarks' => $r->remarks,
            'requested_by' => $r->requested_by ? (int) $r->requested_by : null,
            'requested_by_name' => $names[$r->requested_by] ?? '',
            'approved_by_name' => $names[$r->approved_by] ?? '',
            'approved_at' => $r->approved_at,
            'sent_at' => $r->sent_at,
            'created_at' => (string) $r->created_at,
            'updated_at' => (string) $r->updated_at,
        ];
    }

    /** channels / emails / contacts / subject / message (JSON "payload" field or plain JSON body) */
    private function payload(Request $request, bool $withRef): array
    {
        $data = $request->has('payload') ? json_decode((string) $request->input('payload'), true) : $request->all();
        $rules = [
            'channels'           => ['required', 'array', 'min:1'],
            'channels.*'         => ['required', 'in:' . implode(',', self::CHANNELS)],
            'emails'             => ['nullable', 'array', 'max:20'],
            'emails.*'           => ['required', 'email'],
            'contacts'           => ['nullable', 'array', 'max:50'],
            'contacts.*.channel' => ['required', 'in:' . implode(',', self::CHANNELS)],
            'contacts.*.value'   => ['required', 'string', 'max:255'],
            'subject'            => ['nullable', 'string', 'max:200'],
            'message'            => ['nullable', 'string', 'max:5000'],
        ];
        if ($withRef) {
            $rules += [
                'doc_type'     => ['nullable', 'string', 'max:30'],
                'reference_no' => ['required', 'string', 'max:60'],
                'print_type'   => ['nullable', 'string', 'max:30'],
                'service_id'   => ['nullable', 'integer'],
                'label'        => ['nullable', 'string', 'max:150'],
            ];
        }
        $d = Validator::make(is_array($data) ? $data : [], $rules, [
            'channels.required' => 'Pick at least one: Email, Viber, Messenger, WeChat, WhatsApp or Telegram.',
            'emails.*.email' => 'One of the email addresses is not valid.',
        ])->validate();

        $d['channels'] = array_values(array_unique($d['channels']));
        $d['emails'] = array_values(array_unique(array_map('trim', $d['emails'] ?? [])));
        abort_if(in_array('email', $d['channels'], true) && !$d['emails'], 422, 'Type at least one email address.');
        if (!in_array('email', $d['channels'], true)) $d['emails'] = [];
        $d['contacts'] = collect($d['contacts'] ?? [])
            ->filter(fn ($c) => in_array($c['channel'], $d['channels'], true) && trim($c['value']) !== '')
            ->map(fn ($c) => ['channel' => $c['channel'], 'value' => trim($c['value'])])
            ->unique(fn ($c) => $c['channel'] . '|' . $c['value'])->values()->all();
        return $d;
    }

    /** SOA → its business account (trade name, client #, emails) */
    private function accountOf(string $transNumber): ?object
    {
        $caid = DB::table('t_transaction')->where('trans_number', $transNumber)->value('ca_id');
        return $caid ? DB::table('m_client_account')->where('id', $caid)->first() : null;
    }

    /* ---------------- list ---------------- */

    /* GET /api/send-requests?status=&q= */
    public function index(Request $request)
    {
        $q = DB::table(self::T);
        $status = strtoupper((string) $request->query('status', ''));
        if (in_array($status, self::STATUSES, true)) $q->where('status', $status);
        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('reference_no', 'like', $like)->orWhere('party_name', 'like', $like)
                ->orWhere('label', 'like', $like)->orWhere('client_num', 'like', $like));
        }
        $rows = $q->orderByRaw("FIELD(status, 'PENDING', 'REVISED', 'CONFIRMED', 'DECLINED')")->orderByDesc('id')->limit(1000)->get();
        $names = $this->names($rows->pluck('requested_by')->merge($rows->pluck('approved_by'))->all());

        return response()->json([
            'rows' => $rows->map(fn ($r) => $this->out($r, $names))->values(),
            'counts' => DB::table(self::T)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
            'can_approve' => $this->canApprove($request),
            'me' => $request->user()?->id,
        ]);
    }

    /* GET /api/send-requests/contacts?trans=PF…   the client's emails + saved Viber / WhatsApp … (Client → Socials) */
    public function contacts(Request $request)
    {
        $a = $this->accountOf((string) $request->query('trans', ''));
        if (!$a) return response()->json(['party' => '', 'client_num' => null, 'emails' => [], 'socials' => []]);

        $emails = [$a->email_address ?? null, DB::table('m_client')->where('client_num', $a->client_num)->value('email_address')];
        $socials = [];
        if (Schema::hasTable('m_social_list') && Schema::hasColumn('m_social_party', 'client_num')) {
            $socials = DB::table('m_social_party as P')->join('m_social_list as L', 'L.id', '=', 'P.social_list_id')
                ->where('P.client_num', $a->client_num)->orderBy('L.id')->orderBy('P.id')
                ->get(['L.name', 'P.value'])->map(fn ($r) => ['name' => $r->name, 'value' => $r->value])->all();
            foreach ($socials as $s) if (strtolower($s['name']) === 'email') $emails[] = $s['value'];
        }
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => trim((string) $e), $emails),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));

        return response()->json(['party' => $a->trade_name ?? '', 'client_num' => $a->client_num, 'emails' => $emails, 'socials' => $socials]);
    }

    /* ---------------- requester ---------------- */

    /* POST /api/send-requests   multipart: file (PDF) + payload (JSON) */
    public function store(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:20480']], ['file.required' => 'The PDF is missing.']);
        $d = $this->payload($request, true);
        $a = $this->accountOf($d['reference_no']);

        $file = $request->file('file');
        $key = 'Send_Request/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $d['reference_no']) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.pdf';
        Storage::disk('s3')->put($key, file_get_contents($file->getPathname()), ['ContentType' => 'application/pdf']);

        $id = DB::table(self::T)->insertGetId([
            'doc_type' => $d['doc_type'] ?? 'SOA',
            'reference_no' => $d['reference_no'],
            'print_type' => $d['print_type'] ?? null,
            'service_id' => $d['service_id'] ?? null,
            'label' => $d['label'] ?? null,
            'client_num' => $a->client_num ?? null,
            'party_name' => $a->trade_name ?? null,
            'channels' => json_encode($d['channels']),
            'emails' => json_encode($d['emails']),
            'contacts' => json_encode($d['contacts']),
            'subject' => $d['subject'] ?? null,
            'message' => $d['message'] ?? null,
            'file_path' => $key,
            'status' => 'PENDING',
            'requested_by' => $request->user()?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Sent for approval.'], 201);
    }

    /* PUT /api/send-requests/{id}   edit + resubmit (PENDING / REVISED) → PENDING */
    public function update(Request $request, int $id)
    {
        $r = $this->row($id);
        abort_unless(in_array($r->status, ['PENDING', 'REVISED'], true), 422, "This request is already {$r->status}.");
        abort_unless((int) $r->requested_by === (int) $request->user()?->id || $this->canApprove($request), 403, 'Only the requester can edit this.');
        $d = $this->payload($request, false);
        DB::table(self::T)->where('id', $id)->update([
            'channels' => json_encode($d['channels']),
            'emails' => json_encode($d['emails']),
            'contacts' => json_encode($d['contacts']),
            'subject' => $d['subject'] ?? null,
            'message' => $d['message'] ?? null,
            'status' => 'PENDING',
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Resubmitted for approval.']);
    }

    /* GET /api/send-requests/{id}/pdf */
    public function pdf(int $id)
    {
        $r = $this->row($id);
        $disk = Storage::disk('s3');
        abort_unless($disk->exists($r->file_path), 404, 'The PDF is no longer on storage.');
        return response($disk->get($r->file_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . ($r->print_type ?: 'SOA') . '-' . $r->reference_no . '.pdf"',
        ]);
    }

    /* POST /api/send-requests/{id}/link   7-day link of the PDF for the chat apps (CONFIRMED only) */
    public function link(int $id)
    {
        $r = $this->row($id);
        abort_unless($r->status === 'CONFIRMED', 422, 'Only confirmed requests can be sent.');
        return response()->json([
            'url' => Storage::disk('s3')->temporaryUrl($r->file_path, now()->addDays(7)),
            'expires' => now()->addDays(7)->toDateString(),
        ]);
    }

    /* ---------------- approver ---------------- */

    /* POST /api/send-requests/{id}/confirm   approve → email sent now; chat apps get the link */
    public function confirm(Request $request, int $id)
    {
        abort_unless($this->canApprove($request), 403, 'Only a supervisor can confirm.');
        $r = $this->row($id);
        abort_unless($r->status === 'PENDING', 422, "This request is {$r->status}.");

        $sentAt = null;
        $emails = $this->json($r->emails);
        if (in_array('email', $this->json($r->channels), true) && $emails) {
            try {
                $pdf = Storage::disk('s3')->get($r->file_path);
                $name = ($r->print_type ?: 'SOA') . '-' . $r->reference_no . '.pdf';
                $subject = trim((string) $r->subject) ?: trim(($r->label ?: 'Statement of Account') . ' ' . $r->reference_no);
                Mail::raw((string) $r->message ?: 'Please see the attached statement of account.', function ($m) use ($emails, $subject, $pdf, $name) {
                    $m->to($emails)->subject($subject)->attachData($pdf, $name, ['mime' => 'application/pdf']);
                });
                $sentAt = now();
            } catch (\Throwable $e) {
                report($e);
                return response()->json(['message' => 'Email not sent, still pending: ' . $e->getMessage()], 422);
            }
        }

        DB::table(self::T)->where('id', $id)->update([
            'status' => 'CONFIRMED', 'approved_by' => $request->user()?->id, 'approved_at' => now(),
            'sent_at' => $sentAt, 'updated_at' => now(),
        ]);
        return response()->json(['message' => $sentAt ? 'Confirmed · email sent to ' . count($emails) . ' address(es).' : 'Confirmed.']);
    }

    /* POST /api/send-requests/{id}/decline   { remarks } */
    public function decline(Request $request, int $id)
    {
        return $this->decide($request, $id, 'DECLINED', ['PENDING', 'REVISED'], 'Declined.');
    }

    /* POST /api/send-requests/{id}/revise   { remarks }  back to the requester */
    public function revise(Request $request, int $id)
    {
        return $this->decide($request, $id, 'REVISED', ['PENDING'], 'Returned for revision.');
    }

    private function decide(Request $request, int $id, string $to, array $from, string $msg)
    {
        abort_unless($this->canApprove($request), 403, 'Only a supervisor can do this.');
        $d = $request->validate(['remarks' => ['required', 'string', 'max:2000']], ['remarks.required' => 'Type the reason.']);
        $r = $this->row($id);
        abort_unless(in_array($r->status, $from, true), 422, "This request is {$r->status}.");
        DB::table(self::T)->where('id', $id)->update([
            'status' => $to, 'remarks' => trim($d['remarks']),
            'approved_by' => $request->user()?->id, 'approved_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => $msg]);
    }
}