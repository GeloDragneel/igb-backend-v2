<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IdentifiesStaff;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * HR → Dashboard (Philippine time)
 *   GET /hr/dashboard?month=YYYY-MM
 *     today    : employees, present, late, on leave, not in yet, pending leave / OT
 *     calendar : per day of the month (with the days before / after shown on the grid):
 *                holidays · leaves · overtime · birthdays · payroll pay dates / cut-off ends · attendance (in / late)
 *     lists    : waiting for approval (leave + OT) · on leave today · birthdays this month · next payroll
 */
class HRDashboardController extends Controller
{
    use IdentifiesStaff; // photoUrl()

    const TZ = 'Asia/Manila';
    public function dashboard(Request $request){
        return response()->json($this->data((string) $request->query('month')));
    }

    public function data(string $month = '', ?string $todayOverride = null): array{
        $now = $todayOverride ? CarbonImmutable::parse($todayOverride, self::TZ) : CarbonImmutable::now(self::TZ);
        $today = $now->toDateString();
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : $now->format('Y-m');
        $first = CarbonImmutable::parse("{$month}-01", self::TZ);
        // the 6-week grid: from the Sunday on / before the 1st
        $from = $first->subDays($first->dayOfWeek)->toDateString();
        $to = CarbonImmutable::parse($from, self::TZ)->addDays(41)->toDateString();

        $name = fn ($e) => $e ? trim("{$e->last_name}, {$e->first_name}") : '—';
        // safe Y-m-d: works whether the attribute is a string or a Carbon object
        $ymd = fn ($v) => $v ? CarbonImmutable::parse($v, self::TZ)->toDateString() : null;
        $activeIds = Employee::query()->where('is_active', 1)->pluck('id');

        /* ---------------- today ---------------- */
        $attToday = Attendance::query()->whereDate('work_date', $today)->whereIn('employee_id', $activeIds)->get(['employee_id', 'time_in', 'late_minutes', 'status']);
        $present = $attToday->whereNotNull('time_in');
        $leaveToday = LeaveRequest::query()->with('employee:id,last_name,first_name', 'leaveType:id,code,name')
            ->where('status', 'approved')->whereDate('date_from', '<=', $today)->whereDate('date_to', '>=', $today)->get();
        $pendingLeave = LeaveRequest::query()->with('employee:id,last_name,first_name', 'leaveType:id,code,name')->where('status', 'pending')
            ->orderBy('date_from')->limit(50)->get();
        $pendingOt = Overtime::query()->with('employee:id,last_name,first_name')->where('status', 'pending')->orderBy('work_date')->limit(50)->get();

        /* ---------------- calendar ---------------- */
        $days = [];
        $add = function (string $date, string $key, array $item) use (&$days) {
            $days[$date] ??= ['holidays' => [], 'leaves' => [], 'overtime' => [], 'birthdays' => [], 'payroll' => [], 'present' => 0, 'late' => 0];
            $days[$date][$key][] = $item;
        };

        foreach (Holiday::query()->whereBetween('holiday_date', [$from, $to])->get() as $h) {
            $add($ymd($h->holiday_date), 'holidays', ['name' => $h->name, 'type' => $h->holiday_type]);
        }

        $leaves = LeaveRequest::query()->with('employee:id,last_name,first_name', 'leaveType:id,code,name')
            ->whereIn('status', ['pending', 'approved'])->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->get();
        foreach ($leaves as $l) {
            $lTo = CarbonImmutable::parse($l->date_to, self::TZ);
            for ($d = CarbonImmutable::parse($l->date_from, self::TZ); $d->lessThanOrEqualTo($lTo); $d = $d->addDay()) {
                $key = $d->toDateString();
                if ($key < $from || $key > $to) continue;
                $add($key, 'leaves', ['id' => $l->id, 'employee' => $name($l->employee), 'type' => $l->leaveType?->code, 'type_name' => $l->leaveType?->name,
                    'status' => $l->status, 'half' => $l->is_half_day ? ($l->half_day_part ?: 'half') : null]);
            }
        }

        foreach (Overtime::query()->with('employee:id,last_name,first_name')->whereIn('status', ['pending', 'approved'])->whereBetween('work_date', [$from, $to])->get() as $o) {
            $add($ymd($o->work_date), 'overtime', ['id' => $o->id, 'employee' => $name($o->employee), 'minutes' => (int) $o->minutes, 'status' => $o->status]);
        }

        if (Schema::hasColumn('m_employee', 'birthdate')) {
            $grid = [];
            for ($d = CarbonImmutable::parse($from, self::TZ); $d->lessThanOrEqualTo(CarbonImmutable::parse($to, self::TZ)); $d = $d->addDay()) $grid[$d->format('m-d')] = $d->toDateString();
            foreach (Employee::query()->where('is_active', 1)->whereNotNull('birthdate')->get(['id', 'last_name', 'first_name', 'birthdate']) as $e) {
                $b = CarbonImmutable::parse($e->birthdate, self::TZ);
                $key = $grid[$b->format('m-d')] ?? null;
                if ($key) $add($key, 'birthdays', ['employee' => $name($e), 'age' => CarbonImmutable::parse($key, self::TZ)->year - $b->year]);
            }
        }

        foreach (PayrollRun::query()->where('status', '!=', 'cancelled')
                     ->where(fn ($q) => $q->whereBetween('pay_date', [$from, $to])->orWhereBetween('period_to', [$from, $to]))->get() as $r) {
            $pay = $ymd($r->pay_date);
            if ($pay && $pay >= $from && $pay <= $to) $add($pay, 'payroll', ['id' => $r->id, 'code' => $r->code, 'kind' => 'pay', 'status' => $r->status]);
        }

        foreach (Attendance::query()->whereBetween('work_date', [$from, $to])->whereIn('employee_id', $activeIds)->whereNotNull('time_in')
                     ->get(['work_date', 'late_minutes']) as $a) {
            $key = $ymd($a->work_date);
            $days[$key] ??= ['holidays' => [], 'leaves' => [], 'overtime' => [], 'birthdays' => [], 'payroll' => [], 'present' => 0, 'late' => 0];
            $days[$key]['present']++;
            if ((int) $a->late_minutes > 0) $days[$key]['late']++;
        }

        /* ---------------- birthdays: today (celebration) + the next 7 days ---------------- */
        $celebrate = [];
        $soon = [];
        if (Schema::hasColumn('m_employee', 'birthdate')) {
            $cols = ['id', 'last_name', 'first_name', 'birthdate'];
            if (Schema::hasColumn('m_employee', 'photo_path')) $cols[] = 'photo_path';
            if (Schema::hasColumn('m_employee', 'gender')) $cols[] = 'gender';
            foreach (Employee::query()->where('is_active', 1)->whereNotNull('birthdate')->get($cols) as $e) {
                $b = CarbonImmutable::parse($e->birthdate, self::TZ);
                $next = $b->setYear($now->year);
                if ($next->format('m-d') === '02-29' && !$next->isLeapYear()) $next = $next->setDate($now->year, 2, 28);
                if ($next->lessThan($now->startOfDay())) $next = $next->addYear();
                $in = (int) $now->startOfDay()->diffInDays($next->startOfDay());
                if ($in > 7) continue;
                $row = ['id' => $e->id, 'employee' => $name($e), 'first_name' => trim((string) $e->first_name), 'date' => $next->toDateString(),
                    'age' => $next->year - $b->year, 'in_days' => $in, 'photo_url' => $this->photoUrl($e->photo_path ?? null),
                    'gender' => strtoupper(trim((string) ($e->gender ?? '')))];
                $in === 0 ? $celebrate[] = $row : $soon[] = $row;
            }
            usort($soon, fn ($x, $y) => $x['in_days'] <=> $y['in_days']);
        }

        /* ---------------- lists ---------------- */
        $monthEnd = $first->endOfMonth()->toDateString();
        $birthdays = collect($days)->filter(fn ($d, $k) => $k >= $first->toDateString() && $k <= $monthEnd && $d['birthdays'])
            ->flatMap(fn ($d, $k) => array_map(fn ($b) => $b + ['date' => $k], $d['birthdays']))->values();
        $nextRun = PayrollRun::query()->whereIn('status', ['draft', 'computed', 'approved'])->orderBy('pay_date')->first();

        return [
            'month' => $month, 'today' => $today, 'from' => $from, 'to' => $to,
            'stats' => [
                'employees' => $activeIds->count(),
                'present' => $present->count(),
                'late' => $present->filter(fn ($a) => (int) $a->late_minutes > 0)->count(),
                'on_leave' => $leaveToday->count(),
                'pending_leave' => LeaveRequest::query()->where('status', 'pending')->count(),
                'pending_ot' => Overtime::query()->where('status', 'pending')->count(),
            ],
            'days' => $days,
            'on_leave_today' => $leaveToday->map(fn ($l) => ['employee' => $name($l->employee), 'type' => $l->leaveType?->code, 'type_name' => $l->leaveType?->name,
                'until' => $ymd($l->date_to), 'half' => $l->is_half_day ? ($l->half_day_part ?: 'half') : null])->values(),
            'pending' => $pendingLeave->map(fn ($l) => ['kind' => 'leave', 'id' => $l->id, 'employee' => $name($l->employee), 'what' => $l->leaveType?->code ?? 'Leave',
                'from' => $ymd($l->date_from), 'to' => $ymd($l->date_to), 'days' => (float) $l->days, 'reason' => $l->reason])
                ->concat($pendingOt->map(fn ($o) => ['kind' => 'overtime', 'id' => $o->id, 'employee' => $name($o->employee), 'what' => 'OT',
                    'from' => $ymd($o->work_date), 'to' => $ymd($o->work_date), 'minutes' => (int) $o->minutes, 'reason' => $o->reason]))
                ->sortBy('from')->values()->take(30),
            'birthdays' => $birthdays,
            'birthdays_today' => $celebrate,
            'birthdays_soon' => $soon,
            'next_payroll' => $nextRun ? ['id' => $nextRun->id, 'code' => $nextRun->code, 'status' => $nextRun->status,
                'period_from' => $ymd($nextRun->period_from), 'period_to' => $ymd($nextRun->period_to),
                'pay_date' => $ymd($nextRun->pay_date), 'total_net' => (float) $nextRun->total_net] : null,
        ];
    }
}