<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\NightDiffSetting;
use App\Models\Overtime;
use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * Attendance computations — Philippine time.
 *   schedule  : work date + the shift's time in / break in / break out / time out (overnight shift ends the next day)
 *   late      : time in after (schedule in + grace) → minutes from schedule in
 *   undertime : time out before schedule out
 *   overbreak : actual break longer than the scheduled break
 *   worked    : time inside the schedule minus the break
 *   night diff: worked minutes between the night-diff window (default 10:00 PM – 6:00 AM)
 *   day type  : regular | rest_day | special | regular_holiday (from m_holiday and the shift's work days)
 */
class AttendanceCalculator
{
    const TZ = 'Asia/Manila';

    /** the employee's shift: his own, else the default shift */
    public function shiftOf(Employee $employee): ?Shift
    {
        return ($employee->shift_id ? Shift::find($employee->shift_id) : null) ?? Shift::default();
    }

    /** schedule datetimes of a work date */
    public function schedule(Shift $shift, string $workDate): array
    {
        $day = CarbonImmutable::parse($workDate, self::TZ)->startOfDay();
        $at = fn (?string $time) => $time ? $day->setTimeFromTimeString($time) : null;
        $in = $at($shift->time_in);
        $out = $at($shift->time_out);
        if ($out && $in && ($shift->is_overnight || $out->lessThanOrEqualTo($in))) $out = $out->addDay();
        $bIn = $at($shift->break_in);
        $bOut = $at($shift->break_out);
        if ($bIn && $in && $bIn->lessThan($in)) $bIn = $bIn->addDay();
        if ($bOut && $bIn && $bOut->lessThanOrEqualTo($bIn)) $bOut = $bOut->addDay();
        return ['in' => $in, 'break_in' => $bIn, 'break_out' => $bOut, 'out' => $out];
    }

    /** day type of a date for a shift */
    public function dayType(Shift $shift, string $workDate): string
    {
        $holiday = Holiday::query()->whereDate('holiday_date', $workDate)->value('holiday_type');
        if ($holiday === 'regular') return 'regular_holiday';
        if ($holiday === 'special') return 'special';
        $iso = CarbonImmutable::parse($workDate, self::TZ)->dayOfWeekIso;
        return in_array($iso, $shift->workDays(), true) ? 'regular' : 'rest_day';
    }

    /** fill the schedule + computed minutes of an attendance row (not saved) */
    public function compute(Attendance $a, ?Shift $shift = null): Attendance
    {
        $shift ??= ($a->shift_id ? Shift::find($a->shift_id) : null) ?? Shift::default();
        if (!$shift) return $a;
        $date = $a->work_date instanceof \DateTimeInterface ? $a->work_date->format('Y-m-d') : substr((string) $a->work_date, 0, 10);
        $s = $this->schedule($shift, $date);
        $a->shift_id = $shift->id;
        $a->sched_time_in = $s['in'];
        $a->sched_break_in = $s['break_in'];
        $a->sched_break_out = $s['break_out'];
        $a->sched_time_out = $s['out'];
        $a->day_type = $this->dayType($shift, $date);

        // punches are stored as Philippine time; read them as Philippine time whatever config('app.timezone') is
        $t = fn ($v) => $v ? CarbonImmutable::parse($v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, self::TZ) : null;
        $in = $t($a->time_in);
        $out = $t($a->time_out);
        $bIn = $t($a->break_in);
        $bOut = $t($a->break_out);

        $a->late_minutes = $a->undertime_minutes = $a->overbreak_minutes = $a->worked_minutes = $a->night_diff_minutes = 0;
        if (!$in || !$out || !$s['in'] || !$s['out']) {
            if (!$in && !$out) $a->status = $a->status === 'on_leave' ? 'on_leave' : ($a->day_type === 'regular' ? 'absent' : $a->day_type);
            else $a->status = 'incomplete'; // time in or time out is missing (fix it by manual entry)
            return $a;
        }

        $grace = (int) $shift->grace_minutes;
        if ($in->greaterThan($s['in']->addMinutes($grace))) $a->late_minutes = (int) $s['in']->diffInMinutes($in);
        if ($out->lessThan($s['out'])) $a->undertime_minutes = (int) $out->diffInMinutes($s['out']);

        $schedBreak = $s['break_in'] && $s['break_out'] ? (int) $s['break_in']->diffInMinutes($s['break_out']) : 0;
        $actualBreak = $bIn && $bOut && $bOut->greaterThan($bIn) ? (int) $bIn->diffInMinutes($bOut) : $schedBreak;
        $a->overbreak_minutes = max(0, $actualBreak - $schedBreak);

        // time inside the schedule, minus the break
        $start = $in->max($s['in']);
        $end = $out->min($s['out']);
        $inside = $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : 0;
        $break = max($schedBreak, $actualBreak);
        $a->worked_minutes = max(0, $inside - $break);

        // night diff inside the worked time (break removed)
        if ($nd = NightDiffSetting::inForce($date)) {
            $ndMinutes = $this->overlapNight($start, $end, $nd);
            $breakStart = $bIn ?? $s['break_in'];
            $breakEnd = $bOut ?? $s['break_out'];
            if ($breakStart && $breakEnd && $breakEnd->greaterThan($breakStart)) {
                $ndMinutes -= $this->overlapNight($breakStart->max($start), $breakEnd->min($end), $nd);
            }
            $a->night_diff_minutes = max(0, $ndMinutes);
        }

        // approved OT of the day
        $a->approved_ot_minutes = (int) Overtime::query()->where('employee_id', $a->employee_id)->whereDate('work_date', $date)
            ->where('status', 'approved')->sum('minutes');
        $a->status = $a->worked_minutes > 0 ? ($a->worked_minutes < ((float) $shift->paid_hours * 60) / 2 ? 'half_day' : 'present') : 'absent';
        return $a;
    }

    /** minutes of [start, end] inside the night-diff window(s) */
    public function overlapNight(CarbonImmutable $start, CarbonImmutable $end, NightDiffSetting $nd): int
    {
        if (!$end->greaterThan($start)) return 0;
        $total = 0;
        // check the window that starts the day before, the same day, and the next day
        for ($d = $start->startOfDay()->subDay(); $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $wStart = $d->setTimeFromTimeString($nd->start_time);
            $wEnd = $d->setTimeFromTimeString($nd->end_time);
            if ($wEnd->lessThanOrEqualTo($wStart)) $wEnd = $wEnd->addDay();
            $a = $start->max($wStart);
            $b = $end->min($wEnd);
            if ($b->greaterThan($a)) $total += (int) $a->diffInMinutes($b);
        }
        return $total;
    }
}