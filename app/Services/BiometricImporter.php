<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BiometricLog;
use App\Models\BiometricMap;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Biometric TXT → attendance (Philippine time).
 *
 * The device "Mode" is not reliable, so the punches of a work day are placed by order:
 *   first punch = time in · last punch = time out · the punches between = break in (first) / break out (last)
 *   one punch only  → time in when it is before the middle of the shift, else time out
 *   double taps within 3 minutes count once
 *   overnight shift → a punch before (shift time in − 6 h) belongs to the day before
 */
class BiometricImporter
{
    const TZ = 'Asia/Manila';
    const DOUBLE_TAP_MINUTES = 3;

    public function __construct(private AttendanceCalculator $calc)
    {
    }

    /**
     * clean the uploaded rows: [{ en_no, name?, mode?, at: "Y-m-d H:i:s" }] → valid punches + bad lines
     */
    public function clean(array $rows): array
    {
        $ok = [];
        $bad = 0;
        foreach ($rows as $r) {
            $en = trim((string) ($r['en_no'] ?? ''));
            $at = trim((string) ($r['at'] ?? ''));
            if ($en === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $at)) { $bad++; continue; }
            try {
                $time = CarbonImmutable::parse($at, self::TZ);
            } catch (\Throwable) {
                $bad++;
                continue;
            }
            $ok[] = ['en_no' => $en, 'name' => trim((string) ($r['name'] ?? '')), 'mode' => is_numeric($r['mode'] ?? null) ? (int) $r['mode'] : null, 'at' => $time];
        }
        return [$ok, $bad];
    }

    /** EnNo → employee_id (only employees that exist) */
    public function mapping(): array
    {
        $map = BiometricMap::query()->pluck('employee_id', 'en_no')->all();
        $exists = Employee::query()->whereIn('id', array_values($map) ?: [0])->pluck('id')->flip();
        return array_filter($map, fn ($id) => isset($exists[$id]));
    }

    /**
     * the preview: every employee-day the file makes (merged with punches already saved), nothing is saved
     */
    public function preview(array $rows): array
    {
        [$punches, $bad] = $this->clean($rows);
        $map = $this->mapping();

        // EnNo with no employee yet
        $unmapped = [];
        foreach ($punches as $p) {
            if (isset($map[$p['en_no']])) continue;
            $u = &$unmapped[$p['en_no']];
            $u ??= ['en_no' => $p['en_no'], 'name' => $p['name'], 'punches' => 0, 'from' => $p['at']->toDateString(), 'to' => $p['at']->toDateString()];
            $u['punches']++;
            $u['from'] = min($u['from'], $p['at']->toDateString());
            $u['to'] = max($u['to'], $p['at']->toDateString());
            unset($u);
        }

        $byEmployee = [];
        foreach ($punches as $p) {
            if (isset($map[$p['en_no']])) $byEmployee[$map[$p['en_no']]][] = $p['at'];
        }
        $days = $this->buildDays($byEmployee, true);

        $count = fn ($s) => count(array_filter($days, fn ($d) => $d['action'] === $s));
        return [
            'summary' => [
                'lines' => count($rows), 'bad_lines' => $bad, 'punches' => count($punches),
                'employees' => count($byEmployee), 'days' => count($days),
                'new' => $count('new'), 'update' => $count('update'), 'locked' => $count('locked'),
                'from' => $punches ? min(array_map(fn ($p) => $p['at']->toDateString(), $punches)) : null,
                'to' => $punches ? max(array_map(fn ($p) => $p['at']->toDateString(), $punches)) : null,
            ],
            'unmapped' => array_values($unmapped),
            'days' => $days,
        ];
    }

    /**
     * save: raw punches into t_biometric_log, then each employee-day into t_attendance
     *   a day that already exists is updated · days in an approved payroll are never touched
     */
    public function import(array $rows, ?int $userId): array
    {
        [$punches, $bad] = $this->clean($rows);
        $map = $this->mapping();
        $batch = now(self::TZ)->format('YmdHis') . '-' . Str::lower(Str::random(4));

        return DB::transaction(function () use ($punches, $bad, $map, $batch, $userId) {
            $now = now();
            $saved = 0;
            foreach (array_chunk($punches, 500) as $chunk) {
                $saved += DB::table('t_biometric_log')->insertOrIgnore(array_map(fn ($p) => [
                    'en_no' => $p['en_no'], 'employee_id' => $map[$p['en_no']] ?? null, 'punched_at' => $p['at']->format('Y-m-d H:i:s'),
                    'mode' => $p['mode'], 'device_name' => $p['name'] ?: null, 'batch' => $batch, 'uploaded_by' => $userId,
                    'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
            // punches saved before an EnNo was mapped get their employee now
            foreach ($map as $en => $empId) {
                BiometricLog::query()->where('en_no', $en)->whereNull('employee_id')->update(['employee_id' => $empId]);
            }

            $byEmployee = [];
            foreach ($punches as $p) {
                if (isset($map[$p['en_no']])) $byEmployee[$map[$p['en_no']]][] = $p['at'];
            }
            $days = $this->buildDays($byEmployee, false);

            $result = ['created' => 0, 'updated' => 0, 'skipped_locked' => 0];
            foreach ($days as $d) {
                if ($d['action'] === 'locked') { $result['skipped_locked']++; continue; }
                $row = Attendance::firstOrNew(['employee_id' => $d['employee_id'], 'work_date' => $d['work_date']]);
                $isNew = !$row->exists;
                $row->fill([
                    'time_in' => $d['time_in'], 'break_in' => $d['break_in'], 'break_out' => $d['break_out'], 'time_out' => $d['time_out'],
                    'source' => 'biometric', 'updated_by' => $userId,
                ]);
                if ($isNew) $row->created_by = $userId;
                $shift = Shift::find($d['shift_id']) ?? null;
                $this->calc->compute($row, $shift)->save();
                $isNew ? $result['created']++ : $result['updated']++;
            }
            return $result + ['punches_saved' => $saved, 'punches_already_saved' => count($punches) - $saved, 'bad_lines' => $bad, 'batch' => $batch];
        });
    }

    /**
     * employee_id => [CarbonImmutable punches] → one entry per employee per work day
     * the punches already in t_biometric_log for the same days are merged in (a file can cut a day in half)
     */
    private function buildDays(array $byEmployee, bool $withResult): array
    {
        if (!$byEmployee) return [];
        $employees = Employee::query()->whereIn('id', array_keys($byEmployee))->get()->keyBy('id');
        $days = [];

        foreach ($byEmployee as $empId => $times) {
            $e = $employees[$empId] ?? null;
            if (!$e) continue;
            $shift = $this->calc->shiftOf($e);

            // + punches already saved for the same dates
            $min = min($times)->subDay()->startOfDay();
            $max = max($times)->addDay()->endOfDay();
            $saved = BiometricLog::query()->where('employee_id', $empId)->whereBetween('punched_at', [$min->format('Y-m-d H:i:s'), $max->format('Y-m-d H:i:s')])
                ->pluck('punched_at')->map(fn ($v) => CarbonImmutable::parse($v->format('Y-m-d H:i:s'), self::TZ))->all();
            $all = [];
            foreach (array_merge($times, $saved) as $t) $all[$t->format('Y-m-d H:i:s')] = $t;
            ksort($all);
            $uploadedDates = [];
            foreach ($times as $t) $uploadedDates[$this->workDate($t, $shift)] = true;

            // group by work date
            $groups = [];
            foreach ($all as $t) $groups[$this->workDate($t, $shift)][] = $t;

            $existing = Attendance::query()->where('employee_id', $empId)->whereIn('work_date', array_keys($uploadedDates))->get()
                ->keyBy(fn ($a) => $a->work_date->format('Y-m-d'));

            foreach ($groups as $date => $list) {
                if (!isset($uploadedDates[$date])) continue; // only the days in this file
                $placed = $this->place($list, $shift, $date);
                $old = $existing[$date] ?? null;
                $action = !$old ? 'new' : ($old->payroll_run_id ? 'locked' : 'update');
                $day = [
                    'key' => "{$empId}-{$date}", 'employee_id' => (int) $empId, 'employee' => $e->full_name, 'work_date' => $date,
                    'shift_id' => $shift?->id, 'shift' => $shift?->name,
                    'time_in' => $placed['time_in'], 'break_in' => $placed['break_in'], 'break_out' => $placed['break_out'], 'time_out' => $placed['time_out'],
                    'punches' => array_map(fn ($t) => $t->format('H:i'), $list), 'ignored' => $placed['ignored'], 'action' => $action,
                ];
                if ($withResult) {
                    $a = new Attendance(['employee_id' => $empId, 'work_date' => $date,
                        'time_in' => $day['time_in'], 'break_in' => $day['break_in'], 'break_out' => $day['break_out'], 'time_out' => $day['time_out']]);
                    $this->calc->compute($a, $shift);
                    $day += [
                        'worked_minutes' => (int) $a->worked_minutes, 'late_minutes' => (int) $a->late_minutes,
                        'undertime_minutes' => (int) $a->undertime_minutes + (int) $a->overbreak_minutes, 'status' => $a->status, 'day_type' => $a->day_type,
                    ];
                }
                $days[] = $day;
            }
        }
        usort($days, fn ($a, $b) => [$a['work_date'], $a['employee']] <=> [$b['work_date'], $b['employee']]);
        return $days;
    }

    /** the work date of a punch (overnight shift: early-morning punches belong to the day before) */
    private function workDate(CarbonImmutable $t, ?Shift $shift): string
    {
        if ($shift && $shift->is_overnight && $shift->time_in) {
            $cut = $t->startOfDay()->setTimeFromTimeString($shift->time_in)->subHours(6);
            if ($t->lessThan($cut)) return $t->subDay()->toDateString();
        }
        return $t->toDateString();
    }

    /** place the punches of one work day into time in / break in / break out / time out */
    private function place(array $list, ?Shift $shift, string $date): array
    {
        // double taps count once
        $p = [];
        foreach ($list as $t) {
            if ($p && end($p)->diffInMinutes($t) < self::DOUBLE_TAP_MINUTES) continue;
            $p[] = $t;
        }
        $fmt = fn ($t) => $t?->format('Y-m-d H:i:s');
        $out = ['time_in' => null, 'break_in' => null, 'break_out' => null, 'time_out' => null, 'ignored' => count($list) - count($p)];
        $n = count($p);
        if ($n === 0) return $out;

        $s = $shift ? $this->calc->schedule($shift, $date) : ['in' => null, 'out' => null, 'break_in' => null, 'break_out' => null];
        if ($n === 1) {
            $mid = $s['in'] && $s['out'] ? $s['in']->addMinutes((int) ($s['in']->diffInMinutes($s['out']) / 2)) : null;
            $key = $mid && $p[0]->greaterThanOrEqualTo($mid) ? 'time_out' : 'time_in';
            $out[$key] = $fmt($p[0]);
            return $out;
        }

        $out['time_in'] = $fmt($p[0]);
        $out['time_out'] = $fmt($p[$n - 1]);
        $middle = array_slice($p, 1, $n - 2);
        if (count($middle) === 1) {
            $m = $middle[0];
            $toIn = $s['break_in'] ? abs($m->diffInMinutes($s['break_in'], false)) : 0;
            $toOut = $s['break_out'] ? abs($m->diffInMinutes($s['break_out'], false)) : PHP_INT_MAX;
            $out[$toIn <= $toOut ? 'break_in' : 'break_out'] = $fmt($m);
        } elseif (count($middle) >= 2) {
            $out['break_in'] = $fmt($middle[0]);
            $out['break_out'] = $fmt($middle[count($middle) - 1]);
            $out['ignored'] += count($middle) - 2;
        }
        return $out;
    }
}