<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IdentifiesStaff;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeBank;
use App\Models\EmployeeLeaveCredit;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Services\AttendanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * My profile — the logged-in STAFF login (m_user_staff), Philippine time.
 *   GET  /hr/my-profile                         profile, shift, banks, leave credits (salary / gov't numbers are not shown)
 *   PUT  /hr/my-profile                         personal + contact only
 *   GET  /hr/birthdays-today                    birthdays today (popup after login)
 *   GET  /hr/my-photo                           my photo link for the header
 *   POST /hr/my-profile/photo                   my photo (data URL, null = remove) → S3
 *   GET  /hr/my-requests?year=                  my overtime + leave with status, approved date and who
 *   GET  /hr/my-calendar?month=YYYY-MM          my attendance + holidays
 *   PUT  /hr/my-requests/{kind}/{id}/cancel     cancel my own pending overtime / leave
 * Filing a leave / overtime uses POST /hr/leaves and POST /hr/overtime (HRManagmentController, no employee_id = me).
 */
class ProfileController extends Controller
{
    use IdentifiesStaff;

    const TZ = 'Asia/Manila';

    public function __construct(private AttendanceCalculator $attendanceCalc)
    {
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ);
    }

    /** personal + contact fields the employee may change himself */
    const SELF_EDIT = ['last_name', 'first_name', 'middle_name', 'suffix', 'birthdate', 'gender', 'civ_stat_id', 'citizenship_id', 'religion_id', 'birth_place_id',
        'phone_num', 'tell_num', 'email_address', 'street', 'province_id', 'city_id', 'brgy_id'];

    /** a staff login (m_user_staff)? other logins use /settings */
    private function isStaffLogin(Request $request): bool
    {
        $u = $request->user();
        if (!$u) return false;
        $table = method_exists($u, 'getTable') ? $u->getTable() : '';
        return $table === 'm_user_staff' || class_basename($u) === 'UserStaff';
    }

    /* GET /hr/my-profile */
    public function myProfile(Request $request)
    {
        if (!$this->isStaffLogin($request)) return response()->json(['is_staff' => false, 'linked' => false]);
        $id = $this->me($request);
        $base = ['is_staff' => true, 'login_name' => $this->loginName($request)];
        if (!$id) return response()->json($base + ['linked' => false, 'login_columns' => $this->loginColumns($request->user())]);

        $e = Employee::findOrFail($id);
        $year = (int) ($request->query('year') ?: $this->now()->year);
        $profile = array_intersect_key($e->toArray(), array_flip(array_merge(['id'], array_keys($this->employeeRules()))));
        foreach (['sss_no', 'phic_no', 'hdmf_no', 'tin', 'mccb_acc', 'allowance', 'salary_month', 'salary_day'] as $k) unset($profile[$k]);
        $shift = $this->attendanceCalc->shiftOf($e);
        return response()->json($base + [
            'linked' => true,
            'employee' => ['id' => $e->id, 'full_name' => $e->full_name],
            'profile' => $profile,
            'photo_url' => $this->photoUrl($e->photo_path ?? null),
            'shift' => $shift, 'shift_is_default' => !$e->shift_id,
            'banks' => EmployeeBank::query()->where('employee_id', $id)->where('is_active', true)->orderByDesc('is_default')
                ->get(['id', 'bank_name', 'branch', 'account_name', 'account_no', 'account_type', 'is_default']),
            'leave_credits' => EmployeeLeaveCredit::query()->with('leaveType:id,code,name')->where('employee_id', $id)->where('year', $year)->get(),
        ]);
    }

    /* PUT /hr/my-profile   personal + contact only */
    public function updateMyProfile(Request $request)
    {
        abort_unless($this->isStaffLogin($request), 403, 'Only staff logins have a profile here.');
        $id = $this->me($request) ?? abort(422, 'Your login is not linked to an employee yet. Ask HR to link it.');
        $rules = array_intersect_key($this->employeeRules(), array_flip(self::SELF_EDIT));
        $d = $request->validate($rules);
        Employee::findOrFail($id)->update(array_intersect_key($d, array_flip(self::SELF_EDIT)));
        return response()->json(['message' => 'Your profile is saved.']);
    }

    /* GET /hr/birthdays-today   for the popup after login: who has a birthday today (staff logins only) */
    public function birthdaysToday(Request $request)
    {
        if (!$this->isStaffLogin($request)) return response()->json(['date' => null, 'people' => [], 'reason' => 'not a staff login (' . class_basename($request->user()) . ')']);
        if (!Schema::hasColumn('m_employee', 'birthdate')) return response()->json(['date' => null, 'people' => [], 'reason' => 'm_employee has no birthdate column']);
        $today = $this->now()->startOfDay();
        $me = $this->me($request);
        $md = [$today->format('m-d')];
        if ($today->format('m-d') === '02-28' && !$today->isLeapYear()) $md[] = '02-29'; // Feb 29 birthdays on Feb 28
        $cols = ['id', 'last_name', 'first_name', 'birthdate'];
        if (Schema::hasColumn('m_employee', 'photo_path')) $cols[] = 'photo_path';
        if (Schema::hasColumn('m_employee', 'gender')) $cols[] = 'gender';
        $people = Employee::query()->where('is_active', 1)->whereNotNull('birthdate')->get($cols)
            ->filter(fn ($e) => in_array(CarbonImmutable::parse($e->birthdate)->format('m-d'), $md, true))
            ->map(fn ($e) => [
                'id' => $e->id, 'name' => trim("{$e->last_name}, {$e->first_name}"), 'first_name' => trim((string) $e->first_name),
                'age' => $today->year - CarbonImmutable::parse($e->birthdate)->year, 'photo_url' => $this->photoUrl($e->photo_path ?? null),
                'is_me' => $me && (int) $e->id === (int) $me, 'gender' => strtoupper(trim((string) ($e->gender ?? ''))),
            ])
            ->sortByDesc('is_me')->values();
        return response()->json(['date' => $today->toDateString(), 'people' => $people]);
    }

    /* GET /hr/my-photo   the photo for the header (null when none / not a staff login) */
    public function myPhoto(Request $request)
    {
        if (!$this->isStaffLogin($request)) return response()->json(['photo_url' => null]);
        $id = $this->me($request);
        $key = $id ? Employee::query()->whereKey($id)->value('photo_path') : null;
        return response()->json(['photo_url' => $this->photoUrl($key)]);
    }

    /* POST /hr/my-profile/photo   { photo: data URL | null }  → S3 Employee_Profile/ */
    public function saveMyPhoto(Request $request)
    {
        abort_unless($this->isStaffLogin($request), 403, 'Only staff logins have a profile here.');
        $id = $this->me($request) ?? abort(422, 'Your login is not linked to an employee.');
        $e = Employee::findOrFail($id);
        $data = $request->input('photo');
        if (!$data) {
            if ($e->photo_path ?? null) Storage::disk('s3')->delete($e->photo_path);
            Employee::query()->whereKey($id)->update(['photo_path' => null]);
            return response()->json(['message' => 'Photo removed.', 'photo_url' => null]);
        }
        $key = $this->storePhoto($e, (string) $data);
        return response()->json(['message' => 'Photo saved.', 'photo_url' => $this->photoUrl($key)]);
    }

    /* GET /hr/my-requests?year=   my overtime + leave with status, approved date and who */
    public function myRequests(Request $request)
    {
        $id = $this->me($request);
        if (!$id) return response()->json(['overtime' => [], 'leaves' => []]);
        $year = (int) ($request->query('year') ?: $this->now()->year);

        $who = fn (\Illuminate\Database\Eloquent\Model $r) => $r->approved_by_name
            ?? ($r->approver ? trim("{$r->approver->last_name}, {$r->approver->first_name}") : null);

        $ot = Overtime::query()->with('approver:id,last_name,first_name')->where('employee_id', $id)->whereYear('work_date', $year)
            ->orderByDesc('work_date')->orderByDesc('id')->get()
            ->map(fn (Overtime $r) => $r->toArray() + ['decided_by' => in_array($r->status, ['approved', 'rejected', 'cancelled'], true) ? $who($r) : null]);

        $lv = LeaveRequest::query()->with('approver:id,last_name,first_name', 'leaveType:id,code,name')->where('employee_id', $id)
            ->where(fn ($q) => $q->whereYear('date_from', $year)->orWhereYear('date_to', $year))
            ->orderByDesc('date_from')->orderByDesc('id')->get()
            ->map(fn (LeaveRequest $r) => $r->toArray() + ['decided_by' => in_array($r->status, ['approved', 'rejected', 'cancelled'], true) ? $who($r) : null]);

        return response()->json(['overtime' => $ot->values(), 'leaves' => $lv->values()]);
    }

    /* GET /hr/my-calendar?month=YYYY-MM   my attendance + holidays of the month */
    public function myCalendar(Request $request)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : $this->now()->format('Y-m');
        $from = CarbonImmutable::parse("{$month}-01", self::TZ)->subDays(7)->toDateString();
        $to = CarbonImmutable::parse("{$month}-01", self::TZ)->endOfMonth()->addDays(14)->toDateString();
        $id = $this->me($request);
        $att = $id ? Attendance::query()->where('employee_id', $id)->whereBetween('work_date', [$from, $to])
            ->get(['work_date', 'time_in', 'time_out', 'status', 'late_minutes', 'worked_minutes'])
            ->map(fn ($a) => ['date' => $a->work_date->format('Y-m-d'), 'status' => $a->status, 'time_in' => $a->time_in, 'time_out' => $a->time_out,
                'late_minutes' => (int) $a->late_minutes, 'worked_minutes' => (int) $a->worked_minutes]) : collect();
        $holidays = Holiday::query()->whereBetween('holiday_date', [$from, $to])->get()
            ->map(fn ($h) => ['date' => CarbonImmutable::parse($h->holiday_date)->format('Y-m-d'), 'name' => $h->name, 'type' => $h->holiday_type]);
        return response()->json(['attendance' => $att->values(), 'holidays' => $holidays->values()]);
    }

    /* PUT /hr/my-requests/{kind}/{id}/cancel   cancel my own PENDING overtime / leave */
    public function cancelMyRequest(Request $request, string $kind, int $id)
    {
        $me = $this->me($request) ?? abort(422, 'Your login is not linked to an employee.');
        $row = ($kind === 'overtime' ? Overtime::query() : LeaveRequest::query())->where('employee_id', $me)->findOrFail($id);
        if ($row->status !== 'pending') abort(422, "Only a pending request can be cancelled. This one is {$row->status}.");
        $row->update(['status' => 'cancelled', 'approved_by' => $me, 'approved_by_name' => $this->loginName($request), 'approved_at' => $this->now(),
            'approver_remarks' => 'Cancelled by the employee']);
        return response()->json(['message' => ($kind === 'overtime' ? 'Overtime' : 'Leave') . ' cancelled.']);
    }
}