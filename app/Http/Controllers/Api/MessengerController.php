<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserClientStaff;
use App\Models\UserStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Messenger – like FB Messenger, for employees only.
 * Who I am = my m_employee.id, so the same person logged in as UserStaff or as UserClientStaff sees the same chats.
 *   UserStaff        → m_user_staff.employee_id, else m_employee.com_id = m_user_staff.comid
 *   UserClientStaff  → m_user_client_staff.employee_id
 *   Client logins    → 403
 *
 * GET    /messenger/summary                       unread chats + who is online (also my "I'm online" heartbeat)
 * GET    /messenger/people?q=                     employees to message (with online status)
 * GET    /messenger/conversations                 my chats, newest first
 * POST   /messenger/direct            {employee_id}             open (or create) a direct chat
 * POST   /messenger/groups            {name, member_ids[]}      new group
 * GET    /messenger/conversations/{id}?before_id=|after_id=     messages (30 at a time) + members + seen
 * POST   /messenger/conversations/{id}/messages  (multipart) body?, images[]?, files[]?, reply_to_id?   send
 * GET    /messenger/conversations/{id}/shared?kind=media|files|links&before_id=   chat info: shared photos / files / links
 * POST   /messenger/conversations/{id}/read       {message_id}  seen up to here
 * PUT    /messenger/conversations/{id}            {name}        rename group
 * POST   /messenger/conversations/{id}/members    {employee_ids[]}  add people
 * DELETE /messenger/conversations/{id}/members/{employee}       remove (admin) / leave (myself)
 * DELETE /messenger/messages/{id}                               unsend my message
 * POST   /messenger/conversations/{id}/typing                     "is typing…" to the others
 * POST   /messenger/conversations/{id}/pin  {pinned}              pin / unpin a chat (my list only)
 * POST   /messenger/messages/{id}/react     {emoji|null}          react (same emoji again = remove)
 * POST   /messenger/pusher-auth       {socket_id, channel_name}  real time: my private channel private-emp.<my employee id>
 *
 * Real time = Pusher (same account as the old mobile_php). Events on private-emp.<employee id>:
 *   message {conversation_id, messages[]} · read {conversation_id, employee_id, last_read_id}
 *   unsend {conversation_id, message_id} · typing {conversation_id, employee_id, name} · conversation {conversation_id}
 *   react {conversation_id, message_id, reactions[]}
 * Without Pusher keys the app falls back to polling.
 */
class MessengerController extends Controller
{
    private const PAGE = 30;
    private const ONLINE_SECONDS = 70;          // the app pings every ~20 s while open
    private const MAX_IMAGES = 10;
    private const FILE_TYPES = 'pdf,doc,docx,xls,xlsx,csv,txt,ppt,pptx,zip,rar,7z,odt,ods,rtf,xml,json';

    private array $names = [];
    private array $photos = [];

    /* ================= who ================= */

    private function me(Request $request): int
    {
        $u = $request->user();
        $emp = 0;
        if ($u instanceof UserStaff) {
            $emp = (int) ($u->employee_id ?? 0);
            if ($emp <= 0 && !empty($u->comid)) $emp = (int) DB::table('m_employee')->where('com_id', $u->comid)->value('id');
        } elseif ($u instanceof UserClientStaff) {
            $emp = (int) ($u->employee_id ?? 0);
        }
        abort_unless($emp > 0, 403, 'Messenger is for employees only.');

        // I'm online (at most one write per 15 s)
        $seen = DB::table('t_msg_presence')->where('employee_id', $emp)->value('last_seen_at');
        if (!$seen || now()->diffInSeconds($seen, true) > 15) {
            DB::table('t_msg_presence')->updateOrInsert(['employee_id' => $emp], ['last_seen_at' => now()]);
        }
        return $emp;
    }

    /** load names / photos of these employees once */
    private function loadPeople(array $ids): void
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($this->names)));
        if (!$ids) return;
        $cols = ['id', 'first_name', 'last_name'];
        if (Schema::hasColumn('m_employee', 'photo_path')) $cols[] = 'photo_path';
        foreach (DB::table('m_employee')->whereIn('id', $ids)->get($cols) as $e) {
            $this->names[(int) $e->id] = trim(ucwords(strtolower(trim("{$e->first_name} {$e->last_name}"))));
            $this->photos[(int) $e->id] = $e->photo_path ?? null;
        }
    }

    private function name(?int $id): string
    {
        return $id ? ($this->names[$id] ?? 'Unknown') : '';
    }

    private function url(?string $key, ?string $downloadAs = null): ?string
    {
        if (!$key) return null;
        try {
            $opt = [];
            if ($downloadAs) {   // the browser saves it with the original name
                $ascii = preg_replace('/[^\x20-\x7E]|"/', '_', $downloadAs);
                $opt['ResponseContentDisposition'] = "attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($downloadAs);
            }
            return Storage::disk('s3')->temporaryUrl($key, now()->addHours(2), $opt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function person(int $id, array $online): array
    {
        return ['id' => $id, 'name' => $this->name($id), 'photo' => $this->url($this->photos[$id] ?? null),
            'online' => isset($online[$id]), 'last_seen' => $online[$id] ?? $this->lastSeen[$id] ?? null];
    }

    private array $lastSeen = [];

    /** employee id → last_seen_at, for everyone online now (and remembers last seen of the others asked) */
    private function presence(array $ids = []): array
    {
        $rows = DB::table('t_msg_presence')
            ->when($ids, fn ($q) => $q->whereIn('employee_id', $ids), fn ($q) => $q->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_SECONDS)))
            ->get(['employee_id', 'last_seen_at']);
        $online = [];
        foreach ($rows as $r) {
            $this->lastSeen[(int) $r->employee_id] = (string) $r->last_seen_at;
            if (now()->diffInSeconds($r->last_seen_at, true) <= self::ONLINE_SECONDS) $online[(int) $r->employee_id] = (string) $r->last_seen_at;
        }
        return $online;
    }

    /** my active membership or 404 */
    private function member(int $conversationId, int $me): object
    {
        $m = DB::table('t_msg_member')->where('conversation_id', $conversationId)->where('employee_id', $me)->whereNull('left_at')->first();
        abort_unless($m, 404, 'Chat not found.');
        return $m;
    }

    private function activeIds(int $conversationId): array
    {
        return DB::table('t_msg_member')->where('conversation_id', $conversationId)->whereNull('left_at')
            ->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
    }

    private function system(int $conversationId, string $text): void
    {
        DB::table('t_msg_message')->insert(['conversation_id' => $conversationId, 'employee_id' => null, 'type' => 'system',
            'body' => $text, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('t_msg_conversation')->where('id', $conversationId)->update(['last_message_at' => now(), 'updated_at' => now()]);
    }

    /** after a group change: everyone in it (and anyone removed) reloads that chat */
    private function pushChanged(int $conversationId, array $alsoTo = []): void
    {
        $this->push(array_merge($this->activeIds($conversationId), $alsoTo), 'conversation', ['conversation_id' => $conversationId]);
    }

    private function employeeOk(array $ids): array
    {
        $active = Schema::hasColumn('m_employee', 'is_active') ? 'is_active' : null;
        return DB::table('m_employee')->whereIn('id', $ids)->when($active, fn ($q) => $q->where($active, 1))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /* ================= summary / people ================= */

    /* GET /messenger/summary   badge + online list (called every ~20 s = heartbeat) */
    public function summary(Request $request)
    {
        $me = $this->me($request);
        $unread = DB::table('t_msg_message as G')
            ->join('t_msg_member as M', fn ($j) => $j->on('M.conversation_id', '=', 'G.conversation_id')->where('M.employee_id', $me)->whereNull('M.left_at'))
            ->whereColumn('G.id', '>', 'M.last_read_id')
            ->where(fn ($w) => $w->whereNull('G.employee_id')->orWhere('G.employee_id', '<>', $me))
            ->where('G.type', '<>', 'system')
            ->distinct()->count('G.conversation_id');
        $online = array_keys($this->presence());
        $cfg = $this->pusherCfg();
        return response()->json(['me' => $me, 'unread' => $unread, 'online' => array_values(array_diff($online, [$me])),
            'pusher' => $cfg ? ['key' => $cfg['key'], 'cluster' => $cfg['cluster']] : null]);
    }

    /* GET /messenger/people?q=   employees I can message (online first) */
    public function people(Request $request)
    {
        $me = $this->me($request);
        $q = trim((string) $request->query('q', ''));
        $active = Schema::hasColumn('m_employee', 'is_active') ? 'is_active' : null;
        $typeCol = collect(['name', 'description', 'user_type'])->first(fn ($c) => Schema::hasColumn('m_user_type', $c));

        $rows = DB::table('m_employee as E')
            ->when($typeCol, fn ($x) => $x->leftJoin('m_user_type as T', 'T.id', '=', 'E.designation_id'))
            ->where('E.id', '<>', $me)
            ->when($active, fn ($x) => $x->where("E.{$active}", 1))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where(DB::raw("CONCAT_WS(' ', E.first_name, E.last_name)"), 'like', "%{$q}%")
                ->orWhere(DB::raw("CONCAT_WS(' ', E.last_name, E.first_name)"), 'like', "%{$q}%")))
            ->orderBy('E.first_name')->orderBy('E.last_name')->limit(200)
            ->get(['E.id', $typeCol ? DB::raw("T.{$typeCol} AS role") : DB::raw("'' AS role")]);

        $ids = $rows->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->loadPeople($ids);
        $online = $this->presence($ids);
        return response()->json($rows->map(fn ($r) => $this->person((int) $r->id, $online) + ['role' => (string) $r->role])
            ->sortByDesc('online')->values());
    }

    /* ================= conversations ================= */

    /* GET /messenger/conversations */
    public function conversations(Request $request)
    {
        $me = $this->me($request);
        $mine = DB::table('t_msg_member as M')->join('t_msg_conversation as C', 'C.id', '=', 'M.conversation_id')
            ->where('M.employee_id', $me)->whereNull('M.left_at')
            ->where(fn ($w) => $w->where('C.is_group', 1)->orWhereNotNull('C.last_message_at'))   // empty direct chats stay hidden
            ->orderByRaw('M.pinned_at IS NULL')->orderByDesc('M.pinned_at')
            ->orderByDesc(DB::raw('COALESCE(C.last_message_at, C.created_at)'))->limit(100)
            ->get(['C.id', 'C.is_group', 'C.name', 'C.last_message_at', 'C.created_at', 'M.last_read_id', 'M.pinned_at']);
        if ($mine->isEmpty()) return response()->json([]);
        $ids = $mine->pluck('id')->all();

        $members = DB::table('t_msg_member')->whereIn('conversation_id', $ids)->whereNull('left_at')->get(['conversation_id', 'employee_id'])
            ->groupBy('conversation_id')->map(fn ($g) => $g->pluck('employee_id')->map(fn ($v) => (int) $v)->all());
        $last = DB::table('t_msg_message')->whereIn('id', DB::table('t_msg_message')->whereIn('conversation_id', $ids)
            ->groupBy('conversation_id')->select(DB::raw('MAX(id)')))->get()->keyBy('conversation_id');
        $unread = DB::table('t_msg_message as G')
            ->join('t_msg_member as M', fn ($j) => $j->on('M.conversation_id', '=', 'G.conversation_id')->where('M.employee_id', $me))
            ->whereIn('G.conversation_id', $ids)->whereColumn('G.id', '>', 'M.last_read_id')
            ->where(fn ($w) => $w->whereNull('G.employee_id')->orWhere('G.employee_id', '<>', $me))->where('G.type', '<>', 'system')
            ->groupBy('G.conversation_id')->selectRaw('G.conversation_id, COUNT(*) AS n')->pluck('n', 'conversation_id');

        $everyone = $members->flatten()->merge($last->pluck('employee_id')->filter())->unique()->all();
        $this->loadPeople($everyone);
        $online = $this->presence($everyone);

        return response()->json($mine->map(fn ($c) => $this->convRow($c, $me, $members[$c->id] ?? [], $last[$c->id] ?? null, (int) ($unread[$c->id] ?? 0), $online))->values());
    }

    private function convRow(object $c, int $me, array $memberIds, ?object $last, int $unread, array $online): array
    {
        $others = array_values(array_diff($memberIds, [$me]));
        $isGroup = (bool) $c->is_group;
        $other = !$isGroup && $others ? $others[0] : null;
        $preview = '';
        if ($last) {
            $who = $last->employee_id === null ? '' : ((int) $last->employee_id === $me ? 'You: ' : ($isGroup ? explode(' ', $this->name((int) $last->employee_id))[0] . ': ' : ''));
            $preview = $last->deleted_at ? $who . 'Unsent a message'
                : ($last->type === 'image' ? $who . 'Sent a photo' : ($last->type === 'file' ? $who . 'Sent a file' : $who . Str::limit((string) $last->body, 60)));
        }
        return [
            'id' => (int) $c->id,
            'is_group' => $isGroup,
            'name' => $isGroup ? ($c->name ?: implode(', ', array_map(fn ($i) => explode(' ', $this->name($i))[0], array_slice($others, 0, 3)))) : $this->name($other),
            'other' => $other ? $this->person($other, $online) : null,
            'avatars' => array_map(fn ($i) => ['name' => $this->name($i), 'photo' => $this->url($this->photos[$i] ?? null)], array_slice($others, 0, 2)),
            'members' => count($memberIds),
            'online' => $isGroup ? count(array_intersect($others, array_keys($online))) > 0 : isset($online[$other]),
            'preview' => $preview,
            'time' => (string) ($c->last_message_at ?? $c->created_at),
            'unread' => $unread,
            'pinned' => !empty($c->pinned_at),
        ];
    }

    /* POST /messenger/direct {employee_id} */
    public function direct(Request $request)
    {
        $me = $this->me($request);
        $other = (int) $request->validate(['employee_id' => ['required', 'integer']])['employee_id'];
        abort_if($other === $me, 422, "You can't message yourself.");
        abort_unless($this->employeeOk([$other]), 422, 'Employee not found.');

        $key = min($me, $other) . '-' . max($me, $other);
        $id = DB::table('t_msg_conversation')->where('direct_key', $key)->value('id');
        if (!$id) {
            $id = DB::transaction(function () use ($me, $other, $key) {
                $id = DB::table('t_msg_conversation')->insertGetId(['is_group' => 0, 'direct_key' => $key, 'created_by' => $me,
                    'created_at' => now(), 'updated_at' => now()]);
                foreach ([$me, $other] as $e) {
                    DB::table('t_msg_member')->insert(['conversation_id' => $id, 'employee_id' => $e, 'created_at' => now(), 'updated_at' => now()]);
                }
                return $id;
            });
        }
        return $this->show($request, (int) $id);
    }

    /* POST /messenger/groups {name, member_ids[]} */
    public function createGroup(Request $request)
    {
        $me = $this->me($request);
        $d = $request->validate(['name' => ['nullable', 'string', 'max:80'], 'member_ids' => ['required', 'array', 'min:2', 'max:200'], 'member_ids.*' => ['integer']],
            ['member_ids.min' => 'Pick at least 2 people for a group.']);
        $ids = array_values(array_diff($this->employeeOk(array_map('intval', $d['member_ids'])), [$me]));
        abort_if(count($ids) < 2, 422, 'Pick at least 2 people for a group.');
        $this->loadPeople([$me]);

        $id = DB::transaction(function () use ($me, $ids, $d) {
            $id = DB::table('t_msg_conversation')->insertGetId(['is_group' => 1, 'name' => trim((string) ($d['name'] ?? '')) ?: null,
                'created_by' => $me, 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_merge([$me], $ids) as $e) {
                DB::table('t_msg_member')->insert(['conversation_id' => $id, 'employee_id' => $e, 'is_admin' => $e === $me ? 1 : 0,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $this->system($id, $this->name($me) . ' created the group.');
            return $id;
        });
        $this->pushChanged((int) $id);
        return $this->show($request, (int) $id);
    }

    /* GET /messenger/conversations/{id}?before_id=&after_id= */
    public function show(Request $request, int $id)
    {
        $me = $this->me($request);
        $this->member($id, $me);
        $c = DB::table('t_msg_conversation')->where('id', $id)->first();
        $before = (int) $request->query('before_id', 0);
        $after = (int) $request->query('after_id', 0);

        $q = DB::table('t_msg_message')->where('conversation_id', $id);
        if ($after) {
            $msgs = $q->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        } else {
            $msgs = $q->when($before, fn ($x) => $x->where('id', '<', $before))->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
        }
        // older messages changed lately (unsent / reactions) – for polling without Pusher
        $changed = $after ? DB::table('t_msg_message')->where('conversation_id', $id)->where('id', '<=', $after)
            ->where('updated_at', '>=', now()->subMinutes(2))->get() : collect();
        $unsent = $changed->whereNotNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->values()->all();

        $members = DB::table('t_msg_member')->where('conversation_id', $id)->get(['employee_id', 'is_admin', 'last_read_id', 'left_at']);
        $this->loadPeople(array_merge($members->pluck('employee_id')->all(), $msgs->pluck('employee_id')->filter()->all(), $changed->pluck('employee_id')->filter()->all()));
        $online = $this->presence($members->pluck('employee_id')->map(fn ($v) => (int) $v)->all());
        $active = $members->whereNull('left_at');
        $others = $active->pluck('employee_id')->map(fn ($v) => (int) $v)->reject(fn ($v) => $v === $me)->values()->all();
        $other = !$c->is_group && $others ? $others[0] : null;

        return response()->json([
            'conversation' => [
                'id' => (int) $c->id, 'is_group' => (bool) $c->is_group,
                'name' => $c->is_group ? ($c->name ?: implode(', ', array_map(fn ($i) => explode(' ', $this->name($i))[0], array_slice($others, 0, 3)))) : $this->name($other),
                'has_name' => (bool) $c->name,
                'other' => $other ? $this->person($other, $online) : null,
                'avatars' => array_map(fn ($i) => ['name' => $this->name($i), 'photo' => $this->url($this->photos[$i] ?? null)], array_slice($others, 0, 2)),
                'i_am_admin' => (bool) ($active->firstWhere('employee_id', $me)->is_admin ?? false),
            ],
            'members' => $active->map(fn ($m) => $this->person((int) $m->employee_id, $online) + [
                'is_admin' => (bool) $m->is_admin, 'last_read_id' => (int) $m->last_read_id, 'me' => (int) $m->employee_id === $me,
            ])->values(),
            'messages' => $this->rows($msgs, $me),
            'has_more' => !$after && $msgs->count() === self::PAGE,
            'unsent' => $unsent,
            'changed' => $this->rows($changed, $me),
        ]);
    }

    /** messages → rows, with the message each one replies to and the reactions */
    private function rows($msgs, int $me): array
    {
        $msgs = collect($msgs);
        if ($msgs->isEmpty()) return [];
        $ids = $msgs->pluck('id')->map(fn ($v) => (int) $v)->all();
        $replyIds = $msgs->pluck('reply_to_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $replies = $replyIds ? DB::table('t_msg_message')->whereIn('id', $replyIds)->get()->keyBy('id') : collect();
        $reactions = $this->reactionsFor($ids);
        $this->loadPeople($replies->pluck('employee_id')->filter()->all());

        return $msgs->map(function ($m) use ($me, $replies, $reactions) {
            $row = $this->msgRow($m, $me);
            $row['reactions'] = $reactions[(int) $m->id] ?? [];
            $rt = !empty($m->reply_to_id) ? $replies->get((int) $m->reply_to_id) : null;
            $row['reply'] = $rt ? [
                'id' => (int) $rt->id,
                'sender_id' => $rt->employee_id ? (int) $rt->employee_id : null,
                'sender' => $this->name($rt->employee_id ? (int) $rt->employee_id : null),
                'type' => $rt->type,
                'body' => $rt->deleted_at ? null : Str::limit((string) $rt->body, 120),
                'image' => !$rt->deleted_at && $rt->type === 'image' ? $this->url($rt->file_key) : null,
                'file_name' => !$rt->deleted_at && $rt->type === 'file' ? $rt->file_name : null,
                'unsent' => (bool) $rt->deleted_at,
            ] : null;
            return $row;
        })->values()->all();
    }

    /** message id → [{emoji, count, ids[], names[]}] (most used first) */
    private function reactionsFor(array $messageIds): array
    {
        if (!$messageIds || !Schema::hasTable('t_msg_reaction')) return [];
        $rows = DB::table('t_msg_reaction')->whereIn('message_id', $messageIds)->orderBy('id')->get(['message_id', 'employee_id', 'emoji']);
        $this->loadPeople($rows->pluck('employee_id')->all());
        $out = [];
        foreach ($rows->groupBy('message_id') as $mid => $list) {
            $out[(int) $mid] = $list->groupBy('emoji')->map(fn ($g, $emoji) => [
                'emoji' => $emoji, 'count' => $g->count(),
                'ids' => $g->pluck('employee_id')->map(fn ($v) => (int) $v)->values()->all(),
                'names' => $g->map(fn ($r) => $this->name((int) $r->employee_id))->values()->all(),
            ])->sortByDesc('count')->values()->all();
        }
        return $out;
    }

    private function msgRow(object $m, int $me): array
    {
        $deleted = (bool) $m->deleted_at;
        return [
            'id' => (int) $m->id,
            'type' => $m->type,
            'body' => $deleted ? null : $m->body,
            'image' => !$deleted && $m->type === 'image' ? $this->url($m->file_key) : null,
            'file_name' => $deleted ? null : $m->file_name,
            'file' => !$deleted && $m->type === 'file' ? $this->url($m->file_key, $m->file_name) : null,
            'file_size' => $deleted ? null : ($m->file_size ? (int) $m->file_size : null),
            'sender_id' => $m->employee_id ? (int) $m->employee_id : null,
            'sender' => $this->name($m->employee_id ? (int) $m->employee_id : null),
            'photo' => $m->employee_id ? $this->url($this->photos[(int) $m->employee_id] ?? null) : null,
            'mine' => (int) $m->employee_id === $me,
            'unsent' => $deleted,
            'time' => (string) $m->created_at,
        ];
    }

    /* POST /messenger/conversations/{id}/messages   multipart: body?, images[]? */
    public function send(Request $request, int $id)
    {
        $me = $this->me($request);
        $this->member($id, $me);
        $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:' . self::MAX_IMAGES],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
            'files' => ['nullable', 'array', 'max:' . self::MAX_IMAGES],
            'files.*' => ['file', 'mimes:' . self::FILE_TYPES, 'max:20480'],
            'reply_to_id' => ['nullable', 'integer'],
        ], [
            'images.*.max' => 'Each photo must be 10 MB or less.', 'images.*.image' => 'Only photos can be sent as photos.',
            'files.*.max' => 'Each file must be 20 MB or less.',
            'files.*.mimes' => 'This file type is not allowed (PDF, Word, Excel, CSV, PowerPoint, TXT, ZIP only).',
        ]);
        $body = trim((string) $request->input('body', ''));
        $files = $request->file('images', []);
        $docs = $request->file('files', []);
        abort_if($body === '' && !$files && !$docs, 422, 'Type a message or add a photo or file.');
        $replyTo = (int) $request->input('reply_to_id', 0);
        if ($replyTo && !DB::table('t_msg_message')->where('id', $replyTo)->where('conversation_id', $id)->exists()) $replyTo = 0;

        // photos → S3 first (outside the transaction)
        $stored = [];
        foreach ($files as $f) {
            $ext = strtolower($f->getClientOriginalExtension() ?: $f->extension() ?: 'jpg');
            $key = Storage::disk('s3')->putFileAs("Messenger/{$id}", $f, Str::uuid() . ".{$ext}");
            abort_unless($key, 500, 'Could not upload the photo.');
            $stored[] = [$key, mb_substr($f->getClientOriginalName(), 0, 255), (int) $f->getSize(), 'image'];
        }
        foreach ($docs as $f) {
            $ext = strtolower($f->getClientOriginalExtension() ?: $f->extension() ?: 'bin');
            $key = Storage::disk('s3')->putFileAs("Messenger/{$id}/files", $f, Str::uuid() . ".{$ext}");
            abort_unless($key, 500, 'Could not upload the file.');
            $stored[] = [$key, mb_substr($f->getClientOriginalName(), 0, 255), (int) $f->getSize(), 'file'];
        }

        $newIds = DB::transaction(function () use ($id, $me, $body, $stored, $replyTo) {
            $ids = [];
            // the reply goes on the text, or on the first photo when there is no text
            $replyOnPhoto = $body === '' ? $replyTo : 0;
            foreach ($stored as $n => [$key, $name, $size, $type]) {
                $ids[] = DB::table('t_msg_message')->insertGetId(['conversation_id' => $id, 'employee_id' => $me, 'type' => $type,
                    'reply_to_id' => $n === 0 && $replyOnPhoto ? $replyOnPhoto : null,
                    'file_key' => $key, 'file_name' => $name, 'file_size' => $size, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($body !== '') {
                $ids[] = DB::table('t_msg_message')->insertGetId(['conversation_id' => $id, 'employee_id' => $me, 'type' => 'text',
                    'reply_to_id' => $replyTo ?: null, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('t_msg_conversation')->where('id', $id)->update(['last_message_at' => now(), 'updated_at' => now()]);
            DB::table('t_msg_member')->where('conversation_id', $id)->where('employee_id', $me)->update(['last_read_id' => max($ids)]);
            return $ids;
        });

        $this->loadPeople([$me]);
        $rows = $this->rows(DB::table('t_msg_message')->whereIn('id', $newIds)->orderBy('id')->get(), $me);
        $this->push(array_diff($this->activeIds($id), [$me]), 'message', ['conversation_id' => $id, 'messages' => $rows]);
        return response()->json(['messages' => $rows]);
    }

    /* POST /messenger/conversations/{id}/read {message_id} */
    public function read(Request $request, int $id)
    {
        $me = $this->me($request);
        $m = $this->member($id, $me);
        $msg = (int) $request->validate(['message_id' => ['required', 'integer']])['message_id'];
        $max = (int) DB::table('t_msg_message')->where('conversation_id', $id)->max('id');
        $msg = min($msg, $max);
        if ($msg > (int) $m->last_read_id) {
            DB::table('t_msg_member')->where('id', $m->id)->update(['last_read_id' => $msg, 'updated_at' => now()]);
            $this->push($this->activeIds($id), 'read', ['conversation_id' => $id, 'employee_id' => $me, 'last_read_id' => $msg]);
        }
        return response()->json(['ok' => true]);
    }

    /* ================= groups ================= */

    /* PUT /messenger/conversations/{id} {name} */
    public function rename(Request $request, int $id)
    {
        $me = $this->me($request);
        $this->member($id, $me);
        abort_unless(DB::table('t_msg_conversation')->where('id', $id)->value('is_group'), 422, 'Only groups have a name.');
        $name = trim((string) ($request->validate(['name' => ['nullable', 'string', 'max:80']])['name'] ?? ''));
        DB::table('t_msg_conversation')->where('id', $id)->update(['name' => $name ?: null, 'updated_at' => now()]);
        $this->loadPeople([$me]);
        $this->system($id, $name ? $this->name($me) . " named the group {$name}." : $this->name($me) . ' removed the group name.');
        $this->pushChanged($id);
        return $this->show($request, $id);
    }

    /* POST /messenger/conversations/{id}/members {employee_ids[]} */
    public function addMembers(Request $request, int $id)
    {
        $me = $this->me($request);
        $this->member($id, $me);
        abort_unless(DB::table('t_msg_conversation')->where('id', $id)->value('is_group'), 422, 'Create a group to add more people.');
        $ids = $this->employeeOk(array_map('intval', $request->validate(['employee_ids' => ['required', 'array', 'min:1'], 'employee_ids.*' => ['integer']])['employee_ids']));
        $ids = array_values(array_diff($ids, $this->activeIds($id)));
        abort_if(!$ids, 422, 'They are already in the group.');
        $this->loadPeople(array_merge([$me], $ids));
        $lastId = (int) DB::table('t_msg_message')->where('conversation_id', $id)->max('id');

        DB::transaction(function () use ($id, $ids, $lastId) {
            foreach ($ids as $e) {
                DB::table('t_msg_member')->updateOrInsert(['conversation_id' => $id, 'employee_id' => $e],
                    ['left_at' => null, 'is_admin' => 0, 'last_read_id' => $lastId, 'updated_at' => now(), 'created_at' => now()]);
            }
        });
        $this->system($id, $this->name($me) . ' added ' . $this->joinNames($ids) . '.');
        $this->pushChanged($id);
        return $this->show($request, $id);
    }

    /* DELETE /messenger/conversations/{id}/members/{employee}   remove (admin) or leave (myself) */
    public function removeMember(Request $request, int $id, int $employee)
    {
        $me = $this->me($request);
        $mine = $this->member($id, $me);
        abort_unless(DB::table('t_msg_conversation')->where('id', $id)->value('is_group'), 422, "You can't leave a direct chat.");
        $leaving = $employee === $me;
        abort_unless($leaving || $mine->is_admin, 403, 'Only a group admin can remove people.');
        $this->loadPeople([$me, $employee]);

        DB::transaction(function () use ($id, $employee) {
            DB::table('t_msg_member')->where('conversation_id', $id)->where('employee_id', $employee)->update(['left_at' => now(), 'is_admin' => 0, 'updated_at' => now()]);
            // keep at least one admin
            if (!DB::table('t_msg_member')->where('conversation_id', $id)->whereNull('left_at')->where('is_admin', 1)->exists()) {
                DB::table('t_msg_member')->where('conversation_id', $id)->whereNull('left_at')->orderBy('id')->limit(1)->update(['is_admin' => 1]);
            }
        });
        $this->system($id, $leaving ? $this->name($me) . ' left the group.' : $this->name($me) . ' removed ' . $this->name($employee) . '.');
        $this->pushChanged($id, [$employee]);
        return $leaving ? response()->json(['left' => true]) : $this->show($request, $id);
    }

    /* PUT /messenger/conversations/{id}/members/{employee}/admin {is_admin} */
    public function setAdmin(Request $request, int $id, int $employee)
    {
        $me = $this->me($request);
        abort_unless($this->member($id, $me)->is_admin, 403, 'Only a group admin can do this.');
        $on = (bool) $request->validate(['is_admin' => ['required', 'boolean']])['is_admin'];
        DB::table('t_msg_member')->where('conversation_id', $id)->where('employee_id', $employee)->whereNull('left_at')->update(['is_admin' => $on ? 1 : 0]);
        if (!$on && !DB::table('t_msg_member')->where('conversation_id', $id)->whereNull('left_at')->where('is_admin', 1)->exists()) {
            DB::table('t_msg_member')->where('conversation_id', $id)->where('employee_id', $me)->update(['is_admin' => 1]);
        }
        return $this->show($request, $id);
    }

    private function joinNames(array $ids): string
    {
        $n = array_map(fn ($i) => $this->name($i), $ids);
        return count($n) > 1 ? implode(', ', array_slice($n, 0, -1)) . ' and ' . end($n) : ($n[0] ?? '');
    }

    /* ================= real time (Pusher) ================= */

    private function pusherCfg(): ?array
    {
        $c = config('broadcasting.connections.pusher') ?: [];
        $s = config('services.pusher') ?: [];
        $key = $c['key'] ?? $s['key'] ?? null;
        $secret = $c['secret'] ?? $s['secret'] ?? null;
        $app = $c['app_id'] ?? $s['app_id'] ?? null;
        $cluster = $c['options']['cluster'] ?? $s['cluster'] ?? null;
        if (!$key || !$secret || !$app || !$cluster || !class_exists(\Pusher\Pusher::class)) return null;
        return compact('key', 'secret', 'app', 'cluster');
    }

    private function pusher(): ?\Pusher\Pusher
    {
        static $p = false;
        if ($p !== false) return $p;
        $cfg = $this->pusherCfg();
        return $p = $cfg ? new \Pusher\Pusher($cfg['key'], $cfg['secret'], $cfg['app'], ['cluster' => $cfg['cluster'], 'useTLS' => true]) : null;
    }

    /** send an event to these employees' private channels (never breaks the request) */
    private function push(array $employeeIds, string $event, array $data): void
    {
        $p = $this->pusher();
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (!$p || !$ids) return;
        try {
            foreach (array_chunk($ids, 100) as $chunk) {
                $p->trigger(array_map(fn ($e) => "private-emp.{$e}", $chunk), $event, $data);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* POST /messenger/pusher-auth {socket_id, channel_name}   only my own channel */
    public function pusherAuth(Request $request)
    {
        $me = $this->me($request);
        $d = $request->validate(['socket_id' => ['required', 'string'], 'channel_name' => ['required', 'string']]);
        abort_unless($d['channel_name'] === "private-emp.{$me}", 403, 'Not your channel.');
        $p = $this->pusher();
        abort_unless($p, 503, 'Real time is not set up.');
        $json = method_exists($p, 'authorizeChannel') ? $p->authorizeChannel($d['channel_name'], $d['socket_id']) : $p->socket_auth($d['channel_name'], $d['socket_id']);
        return response($json, 200, ['Content-Type' => 'application/json']);
    }

    /* POST /messenger/conversations/{id}/typing */
    public function typing(Request $request, int $id)
    {
        $me = $this->me($request);
        $this->member($id, $me);
        $this->loadPeople([$me]);
        $this->push(array_diff($this->activeIds($id), [$me]), 'typing', ['conversation_id' => $id, 'employee_id' => $me, 'name' => $this->name($me)]);
        return response()->json(['ok' => true]);
    }

    /* GET /messenger/conversations/{id}/shared?kind=media|files|links&before_id=   for the chat info panel */
    public function shared(Request $request, int $id)
    {
        $me = $this->me($request);
        $this->member($id, $me);
        $kind = $request->query('kind', 'media');
        abort_unless(in_array($kind, ['media', 'files', 'links'], true), 422, 'Unknown kind.');
        $before = (int) $request->query('before_id', 0);
        $limit = $kind === 'media' ? 30 : 25;

        $q = DB::table('t_msg_message')->where('conversation_id', $id)->whereNull('deleted_at')
            ->when($before, fn ($x) => $x->where('id', '<', $before))->orderByDesc('id');
        match ($kind) {
            'media' => $q->where('type', 'image'),
            'files' => $q->where('type', 'file'),
            'links' => $q->where('type', 'text')->where(fn ($w) => $w->where('body', 'like', '%http://%')->orWhere('body', 'like', '%https://%')),
        };
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $this->loadPeople($rows->pluck('employee_id')->filter()->all());

        $items = [];
        foreach ($rows as $m) {
            $base = ['message_id' => (int) $m->id, 'sender' => $this->name($m->employee_id ? (int) $m->employee_id : null), 'time' => (string) $m->created_at];
            if ($kind === 'media') $items[] = $base + ['url' => $this->url($m->file_key), 'name' => $m->file_name];
            if ($kind === 'files') $items[] = $base + ['url' => $this->url($m->file_key, $m->file_name), 'name' => $m->file_name, 'size' => (int) $m->file_size];
            if ($kind === 'links') {
                preg_match_all('~https?://[^\s<>"\']+~i', (string) $m->body, $hits);
                foreach (array_unique($hits[0]) as $u) {
                    $u = rtrim($u, '.,);:!?');
                    $items[] = $base + ['url' => $u, 'host' => parse_url($u, PHP_URL_HOST) ?: $u];
                }
            }
        }
        return response()->json(['items' => $items, 'has_more' => $more, 'next_before' => $more ? (int) $rows->last()->id : null]);
    }

    /* POST /messenger/conversations/{id}/pin {pinned}   pin / unpin in MY list */
    public function pin(Request $request, int $id)
    {
        $me = $this->me($request);
        $m = $this->member($id, $me);
        $on = (bool) $request->validate(['pinned' => ['required', 'boolean']])['pinned'];
        DB::table('t_msg_member')->where('id', $m->id)->update(['pinned_at' => $on ? now() : null]);
        return response()->json(['pinned' => $on]);
    }

    /* POST /messenger/messages/{id}/react {emoji|null}   one reaction per person; the same emoji again removes it */
    public function react(Request $request, int $id)
    {
        $me = $this->me($request);
        $emoji = trim((string) ($request->validate(['emoji' => ['nullable', 'string', 'max:16']])['emoji'] ?? ''));
        $m = DB::table('t_msg_message')->where('id', $id)->first();
        abort_unless($m && !$m->deleted_at && $m->type !== 'system', 404, 'Message not found.');
        $cid = (int) $m->conversation_id;
        $this->member($cid, $me);

        $mine = DB::table('t_msg_reaction')->where('message_id', $id)->where('employee_id', $me)->first();
        if ($emoji === '' || ($mine && $mine->emoji === $emoji)) {
            DB::table('t_msg_reaction')->where('message_id', $id)->where('employee_id', $me)->delete();
        } else {
            DB::table('t_msg_reaction')->updateOrInsert(['message_id' => $id, 'employee_id' => $me],
                ['emoji' => $emoji, 'updated_at' => now(), 'created_at' => $mine->created_at ?? now()]);
        }
        DB::table('t_msg_message')->where('id', $id)->update(['updated_at' => now()]);   // polling picks it up

        $reactions = $this->reactionsFor([$id])[$id] ?? [];
        $this->push(array_diff($this->activeIds($cid), [$me]), 'react', ['conversation_id' => $cid, 'message_id' => $id, 'reactions' => $reactions]);
        return response()->json(['message_id' => $id, 'reactions' => $reactions]);
    }

    /* DELETE /messenger/messages/{id}   unsend my own message */
    public function unsend(Request $request, int $id)
    {
        $me = $this->me($request);
        $m = DB::table('t_msg_message')->where('id', $id)->first();
        abort_unless($m && (int) $m->employee_id === $me && !$m->deleted_at, 404, 'Message not found.');
        $this->member((int) $m->conversation_id, $me);
        DB::table('t_msg_message')->where('id', $id)->update(['deleted_at' => now(), 'body' => null, 'updated_at' => now()]);
        if (Schema::hasTable('t_msg_reaction')) DB::table('t_msg_reaction')->where('message_id', $id)->delete();
        if ($m->file_key) {
            try { Storage::disk('s3')->delete($m->file_key); } catch (\Throwable) { /* keep going */ }
        }
        $this->push(array_diff($this->activeIds((int) $m->conversation_id), [$me]), 'unsend', ['conversation_id' => (int) $m->conversation_id, 'message_id' => $id]);
        return response()->json(['ok' => true]);
    }
}