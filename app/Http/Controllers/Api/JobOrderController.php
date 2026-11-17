<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Staff → Task Management
 *   Prepare JO     t_job_order + t_job_order_activity
 *   Activity List  m_jo_activity
 *
 * A JO: date · whole day (no time) or a time range · assigned by / assigned to (m_employee)
 *       location = a client account (m_client_account) or something else (Phone call, Office, BIR …)
 *       activities from the Activity List, each with remarks and a done tick · remarks · status
 * Status: PENDING → IN_PROGRESS → COMPLETED  (or CANCELLED)
 *
 * Who can change a JO: the one who made it, the assigned by, the assigned to, or a supervisor (m_user_staff.user_type_id 4).
 * Everybody can see every JO (the list opens on "Assigned to me").
 *
 * routes/api.php (inside the auth:sanctum group):
 *   use App\Http\Controllers\Api\JobOrderController;
 *   Route::get('/job-orders/lookups', [JobOrderController::class, 'lookups']);
 *   Route::get('/job-orders', [JobOrderController::class, 'index']);
 *   Route::get('/job-orders/{id}', [JobOrderController::class, 'show'])->whereNumber('id');
 *   Route::post('/job-orders', [JobOrderController::class, 'store']);
 *   Route::put('/job-orders/{id}', [JobOrderController::class, 'update'])->whereNumber('id');
 *   Route::post('/job-orders/{id}/status', [JobOrderController::class, 'status'])->whereNumber('id');
 *   Route::post('/job-orders/{id}/activities/{aid}/toggle', [JobOrderController::class, 'toggle'])->whereNumber('id')->whereNumber('aid');
 *   Route::delete('/job-orders/{id}', [JobOrderController::class, 'destroy'])->whereNumber('id');
 *   Route::get('/jo-activities', [JobOrderController::class, 'activities']);
 *   Route::post('/jo-activities', [JobOrderController::class, 'saveActivity']);
 *   Route::put('/jo-activities/{id}', [JobOrderController::class, 'saveActivity'])->whereNumber('id');
 *   Route::delete('/jo-activities/{id}', [JobOrderController::class, 'deleteActivity'])->whereNumber('id');
 */
class JobOrderController extends MonitoringController
{
    const T = 't_job_order';
    const TA = 't_job_order_activity';
    const TM = 'm_jo_activity';
    const STATUSES = ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
    const SUPERVISOR_TYPES = [4];   // m_user_staff.user_type_id (same as Send Requests)

    /* ---------------- helpers ---------------- */

    private function staffId(Request $request): ?int
    {
        return $request->user() ? (int) $request->user()->id : null;
    }

    private function isSupervisor(Request $request): bool
    {
        $u = $request->user();
        return $u && in_array((int) ($u->user_type_id ?? 0), self::SUPERVISOR_TYPES, true);
    }

    private function canEdit(Request $request, object $jo): bool
    {
        if ($this->isSupervisor($request)) return true;
        $me = $this->me($request);
        return (int) $jo->created_by === (int) $this->staffId($request)
            || ($me && in_array($me, [(int) $jo->assigned_by, (int) $jo->assigned_to], true));
    }

    private function jo(int $id): object
    {
        $r = DB::table(self::T)->where('id', $id)->first();
        abort_if(!$r, 404, 'Job order not found.');
        return $r;
    }

    /** JO-202611-0001 (per month, from the JO date) */
    private function nextNo(string $date): string
    {
        $prefix = 'JO-' . date('Ym', strtotime($date)) . '-';
        $last = DB::table(self::T)->where('jo_no', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('jo_no')->value('jo_no');
        $n = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** employee id → name */
    private function employeeNames(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
        if (!$ids) return [];
        return DB::table('m_employee as E')->whereIn('E.id', $ids)
            ->get(['E.id', DB::raw($this->employeeName() . ' AS n')])
            ->mapWithKeys(fn ($e) => [(int) $e->id => trim((string) $e->n)])->all();
    }

    private function clientLabels(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
        if (!$ids) return [];
        return DB::table('m_client_account')->whereIn('id', $ids)->get(['id', 'branch_code', 'trade_name'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => trim(implode(' - ', array_filter([trim((string) $c->branch_code), trim((string) $c->trade_name)])))])->all();
    }

    private function out(object $r, array $emp, array $cli, array $acts = []): array
    {
        $done = count(array_filter($acts, fn ($a) => $a['is_done']));
        return [
            'id' => (int) $r->id,
            'jo_no' => $r->jo_no,
            'jo_date' => substr((string) $r->jo_date, 0, 10),
            'no_time' => (bool) $r->no_time,
            'time_from' => $r->time_from ? substr((string) $r->time_from, 0, 5) : null,
            'time_to' => $r->time_to ? substr((string) $r->time_to, 0, 5) : null,
            'assigned_by' => $r->assigned_by ? (int) $r->assigned_by : null,
            'assigned_by_name' => $emp[(int) $r->assigned_by] ?? '',
            'assigned_to' => $r->assigned_to ? (int) $r->assigned_to : null,
            'assigned_to_name' => $emp[(int) $r->assigned_to] ?? '',
            'location_type' => $r->location_type,
            'caid' => $r->caid ? (int) $r->caid : null,
            'location_other' => $r->location_other,
            'location' => $r->location_type === 'client' ? ($cli[(int) $r->caid] ?? '') : (string) $r->location_other,
            'status' => $r->status,
            'remarks' => $r->remarks,
            'completed_at' => $r->completed_at,
            'completed_by_name' => $emp[(int) $r->completed_by] ?? '',
            'created_by' => $r->created_by ? (int) $r->created_by : null,
            'created_at' => (string) $r->created_at,
            'activities' => $acts,
            'activities_total' => count($acts),
            'activities_done' => $done,
        ];
    }

    private function activitiesOf(array $joIds): array
    {
        if (!$joIds) return [];
        $out = [];
        foreach (DB::table(self::TA)->whereIn('job_order_id', $joIds)->orderBy('sort')->orderBy('id')->get() as $a) {
            $out[(int) $a->job_order_id][] = [
                'id' => (int) $a->id,
                'activity_id' => $a->activity_id ? (int) $a->activity_id : null,
                'activity' => $a->activity,
                'remarks' => $a->remarks,
                'is_done' => (bool) $a->is_done,
                'done_at' => $a->done_at,
            ];
        }
        return $out;
    }

    /* ---------------- lookups ---------------- */

    /** GET /job-orders/lookups → employees (grouped by designation), client accounts, active activities, me */
    public function lookups(Request $request)
    {
        $active = $this->pick('m_employee', ['is_active', 'active']);
        $dn = Schema::hasTable('m_user_type') ? $this->pick('m_user_type', ['user_type', 'name', 'type_name', 'description']) : null;

        $employees = DB::table('m_employee as E')
            ->when($dn, fn ($q) => $q->leftJoin('m_user_type as T', 'T.id', '=', 'E.designation_id'))
            ->when($active, fn ($q) => $q->where("E.{$active}", 1))
            ->orderBy('E.last_name')->orderBy('E.first_name')
            ->get(['E.id', DB::raw($this->employeeName() . ' AS name'), DB::raw($dn ? "T.{$dn} AS designation" : "'' AS designation")])
            ->map(fn ($e) => ['id' => (int) $e->id, 'name' => trim((string) $e->name), 'designation' => trim((string) $e->designation)]);

        $clients = DB::table('m_client_account')
            ->whereNotNull('trade_name')->where('trade_name', '<>', '')
            ->orderBy('branch_code')->orderBy('trade_name')
            ->get(['id', 'branch_code', 'trade_name', 'client_num'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'branch_code' => (string) $c->branch_code, 'trade_name' => (string) $c->trade_name, 'client_num' => (string) $c->client_num]);

        $activities = DB::table(self::TM)->where('is_active', 1)->orderBy('sort')->orderBy('name')->get(['id', 'name', 'description']);

        return response()->json([
            'me' => $this->me($request),
            'supervisor' => $this->isSupervisor($request),
            'employees' => $employees,
            'clients' => $clients,
            'activities' => $activities,
        ]);
    }

    /* ---------------- Prepare JO ---------------- */

    /** GET /job-orders?from=&to=&scope=mine|by_me|all */
    public function index(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'scope' => ['nullable', 'in:mine,by_me,all'],
        ]);
        $me = $this->me($request);
        $scope = $d['scope'] ?? 'mine';

        $q = DB::table(self::T)->whereBetween('jo_date', [$d['from'], $d['to']]);
        if ($scope === 'mine') $q->where('assigned_to', $me ?: -1);
        if ($scope === 'by_me') $q->where(fn ($w) => $w->where('assigned_by', $me ?: -1)->orWhere('created_by', $this->staffId($request)));

        $rows = $q->orderByDesc('jo_date')->orderByRaw('no_time DESC, time_from')->orderByDesc('id')->get();
        $acts = $this->activitiesOf($rows->pluck('id')->all());
        $emp = $this->employeeNames($rows->flatMap(fn ($r) => [$r->assigned_by, $r->assigned_to, $r->completed_by])->all());
        $cli = $this->clientLabels($rows->pluck('caid')->all());

        $list = $rows->map(fn ($r) => $this->out($r, $emp, $cli, $acts[(int) $r->id] ?? []) + ['can_edit' => $this->canEdit($request, $r)])->values();
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($list as $r) $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;

        return response()->json(['rows' => $list, 'counts' => $counts, 'me' => $me]);
    }

    /** GET /job-orders/{id} */
    public function show(Request $request, int $id)
    {
        $r = $this->jo($id);
        $acts = $this->activitiesOf([$id]);
        $emp = $this->employeeNames([$r->assigned_by, $r->assigned_to, $r->completed_by]);
        $cli = $this->clientLabels([$r->caid]);
        return response()->json($this->out($r, $emp, $cli, $acts[$id] ?? []) + ['can_edit' => $this->canEdit($request, $r)]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'jo_date' => ['required', 'date_format:Y-m-d'],
            'no_time' => ['required', 'boolean'],
            'time_from' => ['nullable', 'required_if:no_time,false', 'date_format:H:i'],
            'time_to' => ['nullable', 'required_if:no_time,false', 'date_format:H:i'],
            'assigned_by' => ['required', 'integer', 'exists:m_employee,id'],
            'assigned_to' => ['required', 'integer', 'exists:m_employee,id'],
            'location_type' => ['required', 'in:client,other'],
            'caid' => ['nullable', 'required_if:location_type,client', 'integer', 'exists:m_client_account,id'],
            'location_other' => ['nullable', 'required_if:location_type,other', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'activities' => ['required', 'array', 'min:1'],
            'activities.*.id' => ['nullable', 'integer'],
            'activities.*.activity_id' => ['nullable', 'integer'],
            'activities.*.activity' => ['required', 'string', 'max:150'],
            'activities.*.remarks' => ['nullable', 'string', 'max:2000'],
            'activities.*.is_done' => ['nullable', 'boolean'],
        ], [
            'time_from.required_if' => 'Enter the start time, or tick "No time".',
            'time_to.required_if' => 'Enter the end time, or tick "No time".',
            'caid.required_if' => 'Choose the client account.',
            'location_other.required_if' => 'Enter where it is, e.g. Phone call.',
            'activities.required' => 'Add at least one activity.',
            'activities.min' => 'Add at least one activity.',
            'activities.*.activity.required' => 'Every activity needs a name.',
        ]);
        if (!$d['no_time'] && $d['time_to'] <= $d['time_from']) {
            abort(response()->json(['message' => 'The end time must be after the start time.', 'errors' => ['time_to' => ['The end time must be after the start time.']]], 422));
        }
        return $d;
    }

    private function fields(array $d): array
    {
        $client = $d['location_type'] === 'client';
        return [
            'jo_date' => $d['jo_date'],
            'no_time' => (bool) $d['no_time'],
            'time_from' => $d['no_time'] ? null : $d['time_from'],
            'time_to' => $d['no_time'] ? null : $d['time_to'],
            'assigned_by' => (int) $d['assigned_by'],
            'assigned_to' => (int) $d['assigned_to'],
            'location_type' => $d['location_type'],
            'caid' => $client ? (int) $d['caid'] : null,
            'location_other' => $client ? null : trim((string) $d['location_other']),
            'remarks' => $d['remarks'] ?? null,
        ];
    }

    /** replace the activities of a JO (keeps done_at of rows that stay done) */
    private function saveActivities(int $joId, array $list): void
    {
        $old = DB::table(self::TA)->where('job_order_id', $joId)->get()->keyBy('id');
        $keep = [];
        foreach (array_values($list) as $i => $a) {
            $done = (bool) ($a['is_done'] ?? false);
            $prev = !empty($a['id']) ? ($old[(int) $a['id']] ?? null) : null;
            $row = [
                'activity_id' => $a['activity_id'] ?? null,
                'activity' => trim($a['activity']),
                'remarks' => $a['remarks'] ?? null,
                'is_done' => $done,
                'done_at' => $done ? ($prev && $prev->is_done ? $prev->done_at : now()) : null,
                'sort' => $i,
                'updated_at' => now(),
            ];
            if ($prev) {
                DB::table(self::TA)->where('id', $prev->id)->update($row);
                $keep[] = (int) $prev->id;
            } else {
                $keep[] = (int) DB::table(self::TA)->insertGetId($row + ['job_order_id' => $joId, 'created_at' => now()]);
            }
        }
        DB::table(self::TA)->where('job_order_id', $joId)->whereNotIn('id', $keep ?: [0])->delete();
    }

    /** POST /job-orders */
    public function store(Request $request)
    {
        $d = $this->validated($request);
        $id = DB::transaction(function () use ($d, $request) {
            $id = DB::table(self::T)->insertGetId($this->fields($d) + [
                'jo_no' => $this->nextNo($d['jo_date']),
                'status' => 'PENDING',
                'created_by' => $this->staffId($request),
                'updated_by' => $this->staffId($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->saveActivities($id, $d['activities']);
            return $id;
        });
        return $this->show($request, $id)->setStatusCode(201);
    }

    /** PUT /job-orders/{id} */
    public function update(Request $request, int $id)
    {
        $jo = $this->jo($id);
        abort_unless($this->canEdit($request, $jo), 403, 'Only the one who made it, the assigned employees or a supervisor can change this JO.');
        abort_if(in_array($jo->status, ['COMPLETED', 'CANCELLED'], true) && !$this->isSupervisor($request), 422, 'This JO is already ' . strtolower($jo->status) . '.');

        $d = $this->validated($request);
        DB::transaction(function () use ($d, $id, $request) {
            DB::table(self::T)->where('id', $id)->update($this->fields($d) + ['updated_by' => $this->staffId($request), 'updated_at' => now()]);
            $this->saveActivities($id, $d['activities']);
        });
        return $this->show($request, $id);
    }

    /** POST /job-orders/{id}/status  { status } */
    public function status(Request $request, int $id)
    {
        $jo = $this->jo($id);
        abort_unless($this->canEdit($request, $jo), 403, 'You cannot change the status of this JO.');
        $s = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]])['status'];

        $done = $s === 'COMPLETED';
        DB::table(self::T)->where('id', $id)->update([
            'status' => $s,
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $this->me($request) : null,
            'updated_by' => $this->staffId($request),
            'updated_at' => now(),
        ]);
        // completed → every activity done
        if ($done) DB::table(self::TA)->where('job_order_id', $id)->where('is_done', 0)->update(['is_done' => 1, 'done_at' => now(), 'updated_at' => now()]);

        return $this->show($request, $id);
    }

    /** POST /job-orders/{id}/activities/{aid}/toggle → done / not done (PENDING → IN_PROGRESS on the first tick) */
    public function toggle(Request $request, int $id, int $aid)
    {
        $jo = $this->jo($id);
        abort_unless($this->canEdit($request, $jo), 403, 'You cannot change this JO.');
        abort_if(in_array($jo->status, ['COMPLETED', 'CANCELLED'], true), 422, 'This JO is already ' . strtolower($jo->status) . '.');
        $a = DB::table(self::TA)->where('id', $aid)->where('job_order_id', $id)->first();
        abort_if(!$a, 404, 'Activity not found.');

        $done = !$a->is_done;
        DB::table(self::TA)->where('id', $aid)->update(['is_done' => $done, 'done_at' => $done ? now() : null, 'updated_at' => now()]);
        if ($done && $jo->status === 'PENDING') {
            DB::table(self::T)->where('id', $id)->update(['status' => 'IN_PROGRESS', 'updated_by' => $this->staffId($request), 'updated_at' => now()]);
        }
        return $this->show($request, $id);
    }

    /** DELETE /job-orders/{id} (not when completed) */
    public function destroy(Request $request, int $id)
    {
        $jo = $this->jo($id);
        abort_unless((int) $jo->created_by === (int) $this->staffId($request) || $this->isSupervisor($request), 403, 'Only the one who made it or a supervisor can delete this JO.');
        abort_if($jo->status === 'COMPLETED', 422, 'A completed JO cannot be deleted.');
        DB::transaction(function () use ($id) {
            DB::table(self::TA)->where('job_order_id', $id)->delete();
            DB::table(self::T)->where('id', $id)->delete();
        });
        return response()->json(['message' => "{$jo->jo_no} deleted."]);
    }

    /* ---------------- Activity List ---------------- */

    /** GET /jo-activities → every activity + how many times it is used */
    public function activities()
    {
        $used = DB::table(self::TA)->whereNotNull('activity_id')->groupBy('activity_id')->selectRaw('activity_id, COUNT(*) AS n')->pluck('n', 'activity_id');
        return response()->json([
            'rows' => DB::table(self::TM)->orderBy('sort')->orderBy('name')->get()->map(fn ($a) => [
                'id' => (int) $a->id, 'name' => $a->name, 'description' => $a->description, 'sort' => (int) $a->sort,
                'is_active' => (bool) $a->is_active, 'used' => (int) ($used[$a->id] ?? 0),
                'updated_at' => (string) $a->updated_at,
            ]),
        ]);
    }

    /** POST /jo-activities · PUT /jo-activities/{id} */
    public function saveActivity(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique(self::TM, 'name')->ignore($id)],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ], ['name.unique' => 'This activity is already in the list.']);

        // names in capitals, like the old list · a new one goes to the end of the dropdown
        $row = ['name' => mb_strtoupper(trim($d['name'])), 'description' => $d['description'] ?? null, 'is_active' => $d['is_active'] ?? true, 'updated_at' => now()];
        if (isset($d['sort'])) $row['sort'] = (int) $d['sort'];
        elseif (!$id) $row['sort'] = (int) DB::table(self::TM)->max('sort') + 1;
        if ($id) {
            abort_if(!DB::table(self::TM)->where('id', $id)->exists(), 404, 'Activity not found.');
            DB::table(self::TM)->where('id', $id)->update($row);
        } else {
            $id = DB::table(self::TM)->insertGetId($row + ['created_by' => $this->staffId($request), 'created_at' => now()]);
        }
        return response()->json(['message' => 'Saved.', 'id' => $id]);
    }

    /** DELETE /jo-activities/{id} → removed, or set inactive when JOs already use it */
    public function deleteActivity(int $id)
    {
        abort_if(!DB::table(self::TM)->where('id', $id)->exists(), 404, 'Activity not found.');
        if (DB::table(self::TA)->where('activity_id', $id)->exists()) {
            DB::table(self::TM)->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            return response()->json(['message' => 'Used in job orders, so it was set to inactive instead.']);
        }
        DB::table(self::TM)->where('id', $id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}