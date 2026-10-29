<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* =========================================================================
   Staff → Calendar (employees only)
     GET    /calendar?from=Y-m-d&to=Y-m-d[&employee_id=]   events (repeats expanded) + holidays + leaves
     GET    /calendar/today                                what is due today for me (the popup)
     POST   /calendar/events                               new event / task / meeting / reminder
     PUT    /calendar/events/{id}                          change (the whole series for a repeating one)
     PATCH  /calendar/events/{id}/done                     tick / untick a task
     DELETE /calendar/events/{id}
     GET    /calendar/employees                            people to share with
     GET    /calendar/access                               IT Officer: who may read / write / delete
     PUT    /calendar/access/{employeeId}                  IT Officer: set it

   Rights (m_calendar_access, set by the IT Officer):
     read   = open the Calendar and see what is shared with you      (no row yet → yes)
     write  = add events / tasks and change your own                  (no row yet → no)
     delete = delete your own                                         (no row yet → no)
   IT Officer (and IT Development Head) = everything, everyone's calendar, and the access list.

   Who sees an event: the one who made it · the employees it is shared with · everyone when "Everyone" is ticked.
   ========================================================================= */
class CalendarController extends Controller
{
    private const TZ = 'Asia/Manila';
    private const ADMINS = ['IT OFFICER', 'IT DEVELOPMENT HEAD'];
    private const KINDS = ['event', 'task', 'meeting', 'reminder'];
    private const REPEATS = ['none', 'daily', 'weekly', 'monthly', 'yearly'];
    private const MAX_OCCURRENCES = 800;

    /* ---------------- who am I ---------------- */

    /** m_employee.id of the login (staff: comid → m_employee.com_id · client staff: employee_id) */
    private function me(Request $request): ?int
    {
        $u = $request->user();
        if (!$u) return null;
        $ok = fn ($id) => is_numeric($id) && (int) $id > 0 && DB::table('m_employee')->where('id', (int) $id)->exists() ? (int) $id : null;

        foreach (['employee_id', 'emp_id'] as $k) {
            if ($id = $ok($u->getAttribute($k))) return $id;
        }
        $com = (int) ($u->getAttribute('comid') ?? 0);
        if ($com > 0) {
            $q = DB::table('m_employee')->where('com_id', $com);
            if (Schema::hasColumn('m_employee', 'is_active')) $q->orderByDesc('is_active');
            if ($id = $ok($q->orderBy('id')->value('id'))) return $id;
        }
        return null;
    }

    /** designation name of an employee (m_employee.designation_id → m_user_type) */
    private function designationName(?int $emp): string
    {
        if (!$emp || !Schema::hasTable('m_user_type')) return '';
        $des = (int) DB::table('m_employee')->where('id', $emp)->value('designation_id');
        if (!$des) return '';
        $col = collect(['name', 'description', 'user_type', 'UserType'])->first(fn ($c) => Schema::hasColumn('m_user_type', $c));
        return $col ? strtoupper(trim((string) DB::table('m_user_type')->where('id', $des)->value($col))) : '';
    }

    private function empName($e): string
    {
        if (!$e) return '—';
        $mi = trim((string) ($e->middle_name ?? ''));
        return trim("{$e->last_name}, {$e->first_name}" . ($mi !== '' ? ' ' . mb_substr($mi, 0, 1) . '.' : ''));
    }

    /** ['emp', 'name', 'admin', 'can' => [read, write, delete]] */
    private function ctx(Request $request): array
    {
        $emp = $this->me($request);
        $admin = in_array($this->designationName($emp), self::ADMINS, true);
        abort_if(!$emp && !$admin, 403, 'The Calendar is for employees – your login has no employee record.');

        $row = $emp && Schema::hasTable('m_calendar_access') ? DB::table('m_calendar_access')->where('employee_id', $emp)->first() : null;
        $can = $admin
            ? ['read' => true, 'write' => true, 'delete' => true]
            : ['read' => $row ? (bool) $row->can_read : true, 'write' => $row ? (bool) $row->can_write : false, 'delete' => $row ? (bool) $row->can_delete : false];

        $e = $emp ? DB::table('m_employee')->where('id', $emp)->first(['last_name', 'first_name', 'middle_name']) : null;
        return ['emp' => $emp, 'name' => $this->empName($e), 'admin' => $admin, 'can' => $can];
    }

    /* ---------------- events ---------------- */

    /** events this person can see (admin: all, or one employee's when $only is given) */
    private function visible($q, array $c, ?int $only = null)
    {
        $who = $c['admin'] ? $only : $c['emp'];
        if ($who === null) return $q;
        return $q->where(function ($w) use ($who) {
            $w->where('E.everyone', 1)
              ->orWhere('E.created_by_emp', $who)
              ->orWhereExists(fn ($g) => $g->from('t_calendar_event_guest as G')->whereColumn('G.event_id', 'E.id')->where('G.employee_id', $who));
        });
    }

    private function guestsOf(array $ids): array
    {
        if (!$ids) return [];
        $out = [];
        $rows = DB::table('t_calendar_event_guest as G')->join('m_employee as M', 'M.id', '=', 'G.employee_id')
            ->whereIn('G.event_id', $ids)->orderBy('M.last_name')->get(['G.event_id', 'M.id', 'M.last_name', 'M.first_name', 'M.middle_name']);
        foreach ($rows as $r) $out[$r->event_id][] = ['id' => (int) $r->id, 'name' => $this->empName($r)];
        return $out;
    }

    /** the dates a (repeating) event happens on inside from → to: [[start, end], …] */
    private function occurrences(object $e, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($e->start_at, self::TZ);
        $end = CarbonImmutable::parse($e->end_at, self::TZ);
        $len = max(0, $end->getTimestamp() - $start->getTimestamp());
        $winFrom = CarbonImmutable::parse("{$from} 00:00:00", self::TZ);
        $winTo = CarbonImmutable::parse("{$to} 23:59:59", self::TZ);
        $until = $e->repeat_until ? CarbonImmutable::parse("{$e->repeat_until} 23:59:59", self::TZ) : null;

        $step = match ($e->repeat) {
            'daily' => fn ($d, $n) => $start->addDays($n),
            'weekly' => fn ($d, $n) => $start->addWeeks($n),
            'monthly' => fn ($d, $n) => $start->addMonthsNoOverflow($n),
            'yearly' => fn ($d, $n) => $start->addYearsNoOverflow($n),
            default => null,
        };

        $out = [];
        if (!$step) {
            if ($start <= $winTo && $end >= $winFrom) $out[] = [$start, $end];
            return $out;
        }
        // jump close to the window first so long series stay fast
        $n = 0;
        if ($start < $winFrom && $e->repeat === 'daily') $n = max(0, (int) floor(($winFrom->getTimestamp() - $start->getTimestamp() - $len) / 86400));
        if ($start < $winFrom && $e->repeat === 'weekly') $n = max(0, (int) floor(($winFrom->getTimestamp() - $start->getTimestamp() - $len) / 604800));
        for ($i = 0; $i < self::MAX_OCCURRENCES; $i++, $n++) {
            $s = $step(null, $n);
            if ($s > $winTo || ($until && $s > $until)) break;
            $en = $s->addSeconds($len);
            if ($en >= $winFrom) $out[] = [$s, $en];
        }
        return $out;
    }

    private function eventRow(object $e, array $c, array $guests, CarbonImmutable $s, CarbonImmutable $en): array
    {
        $mine = $c['emp'] && (int) $e->created_by_emp === $c['emp'];
        return [
            'id'           => (int) $e->id,
            'key'          => $e->id . '@' . $s->format('Ymd\THi'),
            'kind'         => $e->kind ?? 'event',
            'title'        => $e->title,
            'description'  => $e->description,
            'location'     => $e->location,
            'start'        => $s->format('Y-m-d\TH:i'),
            'end'          => $en->format('Y-m-d\TH:i'),
            'all_day'      => (bool) $e->all_day,
            'color'        => $e->color ?: '#2563eb',
            'repeat'       => $e->repeat ?: 'none',
            'repeat_until' => $e->repeat_until,
            'series_start' => CarbonImmutable::parse($e->start_at, self::TZ)->format('Y-m-d\TH:i'),
            'series_end'   => CarbonImmutable::parse($e->end_at, self::TZ)->format('Y-m-d\TH:i'),
            'everyone'     => (bool) $e->everyone,
            'is_done'      => (bool) ($e->is_done ?? false),
            'guests'       => $guests[$e->id] ?? [],
            'created_by'   => $e->created_by_name,
            'mine'         => $mine,
            'can_edit'     => $c['admin'] || ($c['can']['write'] && $mine),
            'can_delete'   => $c['admin'] || ($c['can']['delete'] && $mine),
            // a guest may tick a shared task as done
            'can_done'     => $c['admin'] || $mine || collect($guests[$e->id] ?? [])->contains('id', $c['emp']) || (bool) $e->everyone,
        ];
    }

    /** events in from → to (repeats expanded) */
    private function eventsBetween(array $c, string $from, string $to, ?int $only = null): array
    {
        if (!Schema::hasTable('t_calendar_event')) return [];
        $q = DB::table('t_calendar_event as E')
            ->where('E.start_at', '<=', "{$to} 23:59:59")
            ->where(fn ($w) => $w->where('E.end_at', '>=', "{$from} 00:00:00")
                ->orWhere(fn ($r) => $r->where('E.repeat', '<>', 'none')->where(fn ($u) => $u->whereNull('E.repeat_until')->orWhere('E.repeat_until', '>=', $from))));
        $rows = $this->visible($q, $c, $only)->select('E.*')->orderBy('E.start_at')->get();
        $guests = $this->guestsOf($rows->pluck('id')->all());

        $out = [];
        foreach ($rows as $e) {
            foreach ($this->occurrences($e, $from, $to) as [$s, $en]) $out[] = $this->eventRow($e, $c, $guests, $s, $en);
        }
        usort($out, fn ($a, $b) => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);
        return $out;
    }

    /** holidays in from → to */
    private function holidays(string $from, string $to): array
    {
        if (!Schema::hasTable('m_holiday')) return [];
        return DB::table('m_holiday')->whereBetween('holiday_date', [$from, $to])->orderBy('holiday_date')
            ->get(['holiday_date', 'name', 'holiday_type'])
            ->map(fn ($h) => ['date' => substr((string) $h->holiday_date, 0, 10), 'name' => $h->name, 'type' => $h->holiday_type])->all();
    }

    /** leaves in from → to: approved for everybody; pending only your own (IT Officer: all) */
    private function leaves(array $c, string $from, string $to, ?int $only = null): array
    {
        if (!Schema::hasTable('t_leave_request')) return [];
        $q = DB::table('t_leave_request as L')->join('m_employee as M', 'M.id', '=', 'L.employee_id')
            ->whereDate('L.date_from', '<=', $to)->whereDate('L.date_to', '>=', $from)
            ->whereIn('L.status', ['pending', 'approved']);
        if (!$c['admin']) $q->where(fn ($w) => $w->where('L.status', 'approved')->orWhere('L.employee_id', $c['emp']));
        if ($c['admin'] && $only) $q->where('L.employee_id', $only);
        $typeName = Schema::hasTable('m_leave_type') ? 'T.name' : 'NULL';
        $typeCode = Schema::hasTable('m_leave_type') ? 'T.code' : 'NULL';
        if (Schema::hasTable('m_leave_type')) $q->leftJoin('m_leave_type as T', 'T.id', '=', 'L.leave_type_id');

        return $q->orderBy('L.date_from')->get([
            'L.id', 'L.employee_id', 'L.date_from', 'L.date_to', 'L.status', 'M.last_name', 'M.first_name', 'M.middle_name',
            DB::raw("{$typeCode} AS type_code"), DB::raw("{$typeName} AS type_name"),
        ])->map(fn ($l) => [
            'id' => (int) $l->id, 'employee_id' => (int) $l->employee_id, 'employee' => $this->empName($l),
            'from' => substr((string) $l->date_from, 0, 10), 'to' => substr((string) $l->date_to, 0, 10),
            'status' => $l->status, 'type' => $l->type_code, 'type_name' => $l->type_name,
        ])->all();
    }

    /* GET /calendar */
    public function index(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c['can']['read'], 403, "You don't have access to the Calendar. Ask the IT Officer.");
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        abort_if(CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 62, 422, 'Pick at most 2 months at a time.');
        $only = $c['admin'] && !empty($d['employee_id']) ? (int) $d['employee_id'] : null;

        return response()->json([
            'me'       => ['employee_id' => $c['emp'], 'name' => $c['name'], 'is_admin' => $c['admin'], 'can' => $c['can']],
            'events'   => $this->eventsBetween($c, $d['from'], $d['to'], $only),
            'holidays' => $this->holidays($d['from'], $d['to']),
            'leaves'   => $this->leaves($c, $d['from'], $d['to'], $only),
        ]);
    }

    /* GET /calendar/today – what is due today for me (popup once a day) */
    public function today(Request $request)
    {
        $c = $this->ctx($request);
        if (!$c['can']['read']) return response()->json(['date' => null, 'events' => [], 'holidays' => [], 'leaves' => []]);
        $today = CarbonImmutable::now(self::TZ)->toDateString();
        // the IT Officer sees only what is shared with him / made by him here, not the whole office
        $me = $c['admin'] ? array_merge($c, ['admin' => false]) : $c;
        $events = array_values(array_filter($this->eventsBetween($me, $today, $today), fn ($e) => !($e['kind'] === 'task' && $e['is_done'])));
        return response()->json([
            'date'     => $today,
            'events'   => $events,
            'holidays' => $this->holidays($today, $today),
            'leaves'   => array_values(array_filter($this->leaves($me, $today, $today), fn ($l) => $l['employee_id'] === $c['emp'])),
        ]);
    }

    private function rules(): array
    {
        return [
            'kind'         => ['nullable', 'in:' . implode(',', self::KINDS)],
            'title'        => ['required', 'string', 'max:150'],
            'description'  => ['nullable', 'string', 'max:5000'],
            'location'     => ['nullable', 'string', 'max:150'],
            'all_day'      => ['boolean'],
            'start'        => ['required', 'date'],
            'end'          => ['required', 'date', 'after_or_equal:start'],
            'color'        => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'repeat'       => ['nullable', 'in:' . implode(',', self::REPEATS)],
            'repeat_until' => ['nullable', 'date', 'after_or_equal:start'],
            'everyone'     => ['boolean'],
            'guests'       => ['array'],
            'guests.*'     => ['integer', 'exists:m_employee,id'],
        ];
    }

    private function fill(array $d): array
    {
        $allDay = !empty($d['all_day']);
        $s = CarbonImmutable::parse($d['start'], self::TZ);
        $e = CarbonImmutable::parse($d['end'], self::TZ);
        if ($allDay) { $s = $s->startOfDay(); $e = $e->endOfDay(); }
        $repeat = $d['repeat'] ?? 'none';
        return [
            'kind'         => $d['kind'] ?? 'event',
            'title'        => trim($d['title']),
            'description'  => $d['description'] ?? null,
            'location'     => $d['location'] ?? null,
            'start_at'     => $s->format('Y-m-d H:i:s'),
            'end_at'       => $e->format('Y-m-d H:i:s'),
            'all_day'      => $allDay ? 1 : 0,
            'color'        => $d['color'] ?? '#2563eb',
            'repeat'       => $repeat,
            'repeat_until' => $repeat === 'none' ? null : ($d['repeat_until'] ?? null),
            'everyone'     => !empty($d['everyone']) ? 1 : 0,
        ];
    }

    private function saveGuests(int $id, array $guests, ?int $me): void
    {
        DB::table('t_calendar_event_guest')->where('event_id', $id)->delete();
        $ids = collect($guests)->map(fn ($g) => (int) $g)->filter()->reject(fn ($g) => $g === $me)->unique()->values();
        if ($ids->isNotEmpty()) {
            DB::table('t_calendar_event_guest')->insert($ids->map(fn ($g) => ['event_id' => $id, 'employee_id' => $g])->all());
        }
    }

    /** the event, if I may see it */
    private function eventFor(array $c, int $id): object
    {
        $e = $this->visible(DB::table('t_calendar_event as E')->where('E.id', $id), $c)->select('E.*')->first();
        abort_unless($e, 404, 'Event not found.');
        return $e;
    }

    private function one(array $c, int $id): array
    {
        $e = DB::table('t_calendar_event')->where('id', $id)->first();
        $s = CarbonImmutable::parse($e->start_at, self::TZ);
        return $this->eventRow($e, $c, $this->guestsOf([$id]), $s, CarbonImmutable::parse($e->end_at, self::TZ));
    }

    /* POST /calendar/events */
    public function store(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c['can']['write'], 403, "You can't add to the Calendar. Ask the IT Officer for Write access.");
        $d = $request->validate($this->rules());
        $id = DB::transaction(function () use ($d, $c) {
            $id = DB::table('t_calendar_event')->insertGetId($this->fill($d) + [
                'created_by_emp' => $c['emp'], 'created_by_name' => $c['name'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->saveGuests($id, $d['guests'] ?? [], $c['emp']);
            return $id;
        });
        return response()->json(['event' => $this->one($c, $id), 'message' => 'Saved.'], 201);
    }

    /* PUT /calendar/events/{id} */
    public function update(Request $request, int $id)
    {
        $c = $this->ctx($request);
        $e = $this->eventFor($c, $id);
        abort_unless($c['admin'] || ($c['can']['write'] && (int) $e->created_by_emp === $c['emp']), 403, 'Only the one who made it (with Write access) can change it.');
        $d = $request->validate($this->rules());
        DB::transaction(function () use ($id, $d, $c) {
            DB::table('t_calendar_event')->where('id', $id)->update($this->fill($d) + ['updated_at' => now()]);
            $this->saveGuests($id, $d['guests'] ?? [], $c['emp']);
        });
        return response()->json(['event' => $this->one($c, $id), 'message' => 'Saved.']);
    }

    /* PATCH /calendar/events/{id}/done { is_done } */
    public function done(Request $request, int $id)
    {
        $c = $this->ctx($request);
        $e = $this->eventFor($c, $id);
        $d = $request->validate(['is_done' => ['required', 'boolean']]);
        DB::table('t_calendar_event')->where('id', $e->id)->update(['is_done' => $d['is_done'] ? 1 : 0, 'updated_at' => now()]);
        return response()->json(['is_done' => (bool) $d['is_done']]);
    }

    /* DELETE /calendar/events/{id} */
    public function destroy(Request $request, int $id)
    {
        $c = $this->ctx($request);
        $e = $this->eventFor($c, $id);
        abort_unless($c['admin'] || ($c['can']['delete'] && (int) $e->created_by_emp === $c['emp']), 403, 'Only the one who made it (with Delete access) can delete it.');
        DB::transaction(function () use ($id) {
            DB::table('t_calendar_event_guest')->where('event_id', $id)->delete();
            DB::table('t_calendar_event')->where('id', $id)->delete();
        });
        return response()->json(['message' => 'Deleted.']);
    }

    /* ---------------- people ---------------- */

    private function activeEmployees()
    {
        $cols = array_values(array_filter(['id', 'last_name', 'first_name', 'middle_name', 'designation_id'], fn ($c) => Schema::hasColumn('m_employee', $c)));
        $base = fn () => DB::table('m_employee')->whereNotNull('last_name')->where('last_name', '<>', '')->orderBy('last_name')->orderBy('first_name');
        $active = collect(['is_active', 'active'])->first(fn ($c) => Schema::hasColumn('m_employee', $c));
        $rows = $active ? $base()->where($active, 1)->get($cols) : collect();
        if ($rows->isEmpty()) $rows = $base()->get($cols);
        return $rows;
    }

    /* GET /calendar/employees */
    public function employees(Request $request)
    {
        $this->ctx($request);
        $roles = $this->roleNames();
        return response()->json($this->activeEmployees()->map(fn ($e) => [
            'id'   => (int) $e->id,
            'name' => $this->empName($e),
            'role' => (string) ($roles[$e->designation_id ?? 0] ?? ''),
        ])->values());
    }
    private function roleNames()
    {
        if (!Schema::hasTable('m_user_type')) return collect();
        $col = collect(['name', 'description', 'user_type', 'UserType'])->first(fn ($x) => Schema::hasColumn('m_user_type', $x));
        return $col ? DB::table('m_user_type')->pluck($col, 'id') : collect();
    }
    /* GET /calendar/access (IT Officer) */
    public function access(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c['admin'], 403, 'Only the IT Officer can set Calendar access.');
        $rows = DB::table('m_calendar_access')->get()->keyBy('employee_id');
        $col = Schema::hasTable('m_user_type')
            ? collect(['name', 'description', 'user_type', 'UserType'])->first(fn ($x) => Schema::hasColumn('m_user_type', $x)) : null;
        $roles = $col ? DB::table('m_user_type')->pluck($col, 'id') : collect();

        return response()->json($this->activeEmployees()->map(function ($e) use ($rows, $roles) {
            $r = $rows[$e->id] ?? null;
            $role = strtoupper(trim((string) ($roles[$e->designation_id] ?? '')));
            $admin = in_array($role, self::ADMINS, true);
            return [
                'id' => (int) $e->id, 'name' => $this->empName($e), 'role' => $roles[$e->designation_id] ?? '', 'is_admin' => $admin,
                'can_read' => $admin || ($r ? (bool) $r->can_read : true),
                'can_write' => $admin || ($r ? (bool) $r->can_write : false),
                'can_delete' => $admin || ($r ? (bool) $r->can_delete : false),
                'set' => (bool) $r,
            ];
        })->values());
    }

    /* PUT /calendar/access/{employeeId} (IT Officer) { can_read, can_write, can_delete } */
    public function saveAccess(Request $request, int $employeeId)
    {
        $c = $this->ctx($request);
        abort_unless($c['admin'], 403, 'Only the IT Officer can set Calendar access.');
        abort_unless(DB::table('m_employee')->where('id', $employeeId)->exists(), 404, 'Employee not found.');
        $d = $request->validate(['can_read' => ['required', 'boolean'], 'can_write' => ['required', 'boolean'], 'can_delete' => ['required', 'boolean']]);
        // write / delete without read makes no sense → read comes with them
        $read = $d['can_read'] || $d['can_write'] || $d['can_delete'];
        DB::table('m_calendar_access')->updateOrInsert(['employee_id' => $employeeId], [
            'can_read' => $read ? 1 : 0, 'can_write' => $d['can_write'] ? 1 : 0, 'can_delete' => $d['can_delete'] ? 1 : 0,
            'updated_by_name' => $c['name'], 'updated_at' => now(), 'created_at' => now(),
        ]);
        return response()->json(['can_read' => $read, 'can_write' => (bool) $d['can_write'], 'can_delete' => (bool) $d['can_delete']]);
    }
}