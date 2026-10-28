<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\BiometricLog;
use App\Models\BiometricMap;
use App\Models\ClientAccount;
use App\Models\ContributionSetting;
use App\Models\Employee;
use App\Models\EmployeeBank;
use App\Models\EmployeeLeaveCredit;
use App\Models\EmployeeSalary;
use App\Models\EmployeeShiftLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LendingInvestment;
use App\Models\LendingLoan;
use App\Models\LendingMember;
use App\Models\LendingSetting;
use App\Models\NightDiffSetting;
use App\Models\Overtime;
use App\Models\PayRate;
use App\Models\PayrollCutoff;
use App\Models\PhicBracket;
use App\Models\SssBracket;
use App\Models\PayrollExclusion;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\WithholdingTax;
use App\Services\AttendanceCalculator;
use App\Services\BiometricImporter;
use App\Services\LendingCalculator;
use App\Services\PayrollCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use App\Http\Controllers\Concerns\IdentifiesStaff;

/**
 * HR Management + Lending — every date / time is Philippine time (Asia/Manila).
 * Routes: routes/api_hr.php
 */
class HRManagmentController extends Controller
{
    use IdentifiesStaff; // me(), loginName(), photoUrl(), employeeRules() — shared with ProfileController

    const TZ = 'Asia/Manila';

    public function __construct(
        private AttendanceCalculator $attendanceCalc,
        private PayrollCalculator $payrollCalc,
        private LendingCalculator $lending,
    ) {
    }

    /** m_user_type ids that can approve payroll by override: 2 = CEO, 4 = SUPERVISOR */
    const SUPERVISOR_TYPES = [2, 4];

    /**
     * supervisor override: ALWAYS a supervisor's username + password (m_user_staff, hashed)
     * returns [approver employee_id or null, 'override', approver login]
     */
    private function supervisorApproval(Request $request): array
    {
        $d = $request->validate(['username' => ['required', 'string'], 'password' => ['required', 'string']],
            ['username.required' => 'Supervisor username is required', 'password.required' => 'Supervisor password is required']);
        $q = DB::table('m_user_staff')->where('username', $d['username']);
        if (Schema::hasColumn('m_user_staff', 'is_active')) $q->where('is_active', 1);
        $u = $q->first();
        if (!$u || !Hash::check((string) $d['password'], (string) $u->password)) abort(422, 'Wrong supervisor username or password.');
        if (!in_array((int) ($u->user_type_id ?? 0), self::SUPERVISOR_TYPES, true)) abort(422, "{$u->username} is not a supervisor.");
        $empId = (int) ($u->employee_id ?? 0);
        return [$empId ?: null, 'override', $u];
    }

    /* POST /hr/payroll/{id}/exclusions   { employee_id, skip_sss, skip_philhealth, skip_pagibig, skip_loans, remarks }  before approval → recomputes that employee */
    public function savePayrollExclusion(Request $request, int $id)
    {
        $d = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:m_employee,id'],
            'skip_sss' => ['boolean'], 'skip_philhealth' => ['boolean'], 'skip_pagibig' => ['boolean'], 'skip_loans' => ['boolean'],
            'skip_loan_ids' => ['nullable', 'array'], 'skip_loan_ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $run = PayrollRun::findOrFail($id);
        if (!in_array($run->status, ['draft', 'computed'], true)) abort(422, 'The payroll is already approved. Undo the approval first.');
        $flags = array_map(fn ($k) => (bool) ($d[$k] ?? false), ['skip_sss' => 'skip_sss', 'skip_philhealth' => 'skip_philhealth', 'skip_pagibig' => 'skip_pagibig', 'skip_loans' => 'skip_loans']);
        $loanIds = array_values(array_unique(array_map('intval', $d['skip_loan_ids'] ?? [])));
        if (in_array(true, $flags, true) || $loanIds) {
            PayrollExclusion::updateOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $d['employee_id']], $flags + [
                'skip_loan_ids' => $loanIds ?: null, 'remarks' => $d['remarks'] ?? null, 'created_by' => $this->me($request),
            ]);
        } else {
            PayrollExclusion::query()->where('payroll_run_id', $run->id)->where('employee_id', $d['employee_id'])->delete();
        }
        if ($run->status === 'computed') $this->attempt(fn () => $this->payrollCalc->compute($run, [$d['employee_id']]));
        return response()->json(['message' => 'Deductions of this employee updated.']);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ);
    }

    /** run a calculator call and turn its RuntimeException into a 422 */
    private function attempt(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    /* =====================================================================
       SETTINGS
       ===================================================================== */

    /* GET /hr/lookups */
    public function lookups()
    {
        return response()->json([
            'shifts'        => Shift::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'leave_types'   => LeaveType::query()->orderBy('name')->get(),
            'pay_rates'     => PayRate::query()->orderBy('code')->get(),
            'night_diff'    => NightDiffSetting::query()->orderByDesc('effective_from')->get(),
            'contributions' => ContributionSetting::query()->orderBy('type')->orderByDesc('effective_from')->get(),
            'lending'       => LendingSetting::query()->orderBy('applies_to')->orderBy('name')->get(),
            'today'         => $this->now()->toDateString(),
        ]);
    }

    /* POST /hr/shifts   PUT /hr/shifts/{id} */
    public function saveShift(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'code' => ['nullable', 'string', 'max:20'], 'name' => ['required', 'string', 'max:100'],
            'time_in' => ['required', 'date_format:H:i'], 'break_in' => ['nullable', 'date_format:H:i'],
            'break_out' => ['nullable', 'date_format:H:i', 'required_with:break_in'], 'time_out' => ['required', 'date_format:H:i'],
            'is_overnight' => ['boolean'], 'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'work_days' => ['nullable', 'array'], 'work_days.*' => ['integer', 'between:1,7'],
            'paid_hours' => ['nullable', 'numeric', 'min:1', 'max:24'], 'is_default' => ['boolean'], 'is_active' => ['boolean'],
        ]);
        $d['work_days'] = implode(',', $d['work_days'] ?? [1, 2, 3, 4, 5]);
        $d['is_overnight'] = (bool) ($d['is_overnight'] ?? $d['time_out'] <= $d['time_in']);

        $shift = DB::transaction(function () use ($d, $id, $request) {
            if (!empty($d['is_default'])) Shift::query()->where('is_default', true)->update(['is_default' => false]);
            return $id ? tap(Shift::findOrFail($id))->update($d) : Shift::create($d + ['created_by' => $this->me($request)]);
        });
        return response()->json(['message' => 'Shift saved.', 'shift' => $shift]);
    }

    /* PUT /hr/shifts/{id}/default   the shift used for employees without one */
    public function setDefaultShift(int $id)
    {
        DB::transaction(function () use ($id) {
            Shift::query()->update(['is_default' => false]);
            Shift::findOrFail($id)->update(['is_default' => true, 'is_active' => true]);
        });
        return response()->json(['message' => 'Default shift updated.']);
    }

    /* POST /hr/night-diff   a new night-diff setting from a date (old ones stay as history) */
    public function saveNightDiff(Request $request)
    {
        $d = $request->validate([
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'rate' => ['required', 'numeric', 'min:0', 'max:1'], 'effective_from' => ['required', 'date'],
        ]);
        $row = NightDiffSetting::create($d + ['is_active' => true, 'created_by' => $this->me($request)]);
        return response()->json(['message' => 'Night differential saved.', 'setting' => $row]);
    }

    /* PUT /hr/pay-rates/{id} */
    public function savePayRate(Request $request, int $id)
    {
        $d = $request->validate(['description' => ['required', 'string', 'max:150'], 'multiplier' => ['required', 'numeric', 'min:0', 'max:10'], 'is_active' => ['boolean']]);
        PayRate::findOrFail($id)->update($d);
        return response()->json(['message' => 'Pay rate saved.']);
    }

    /* GET /hr/holidays?year=   POST /hr/holidays   DELETE /hr/holidays/{id} */
    public function holidays(Request $request)
    {
        $year = (int) ($request->query('year') ?: $this->now()->year);
        return response()->json(Holiday::query()->whereYear('holiday_date', $year)->orderBy('holiday_date')->get());
    }

    public function saveHoliday(Request $request)
    {
        $d = $request->validate([
            'holiday_date' => ['required', 'date'], 'name' => ['required', 'string', 'max:150'],
            'holiday_type' => ['required', 'in:regular,special,special_working'],
        ]);
        $row = Holiday::updateOrCreate(['holiday_date' => $d['holiday_date']], $d);
        return response()->json(['message' => 'Holiday saved.', 'holiday' => $row]);
    }

    public function deleteHoliday(int $id)
    {
        Holiday::findOrFail($id)->delete();
        return response()->json(['message' => 'Holiday removed.']);
    }

    /* POST /hr/leave-types   PUT /hr/leave-types/{id} */
    public function saveLeaveType(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('m_leave_type', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:100'], 'is_paid' => ['boolean'], 'default_days' => ['required', 'numeric', 'min:0', 'max:366'],
            'is_convertible' => ['boolean'], 'requires_attachment' => ['boolean'], 'is_active' => ['boolean'],
        ]);
        $row = $id ? tap(LeaveType::findOrFail($id))->update($d) : LeaveType::create($d);
        return response()->json(['message' => 'Leave type saved.', 'leave_type' => $row]);
    }

    /* POST /hr/contribution-settings   a new rate from a date (old ones stay as history) */
    public function saveContribution(Request $request)
    {
        $d = $request->validate([
            'type' => ['required', 'in:SSS,PHILHEALTH,PAGIBIG'], 'effective_from' => ['required', 'date'],
            'ee_rate' => ['required', 'numeric', 'min:0', 'max:1'], 'er_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'salary_floor' => ['nullable', 'numeric', 'min:0'], 'salary_ceiling' => ['nullable', 'numeric', 'min:0'],
            'bracket_step' => ['nullable', 'numeric', 'min:0'], 'low_salary_limit' => ['nullable', 'numeric', 'min:0'],
            'low_ee_rate' => ['nullable', 'numeric', 'min:0', 'max:1'], 'ec_threshold' => ['nullable', 'numeric', 'min:0'],
            'ec_low' => ['nullable', 'numeric', 'min:0'], 'ec_high' => ['nullable', 'numeric', 'min:0'], 'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $row = ContributionSetting::updateOrCreate(['type' => $d['type'], 'effective_from' => $d['effective_from']], $d + ['is_active' => true]);
        return response()->json(['message' => 'Contribution rate saved.', 'setting' => $row]);
    }

    /* GET /hr/contribution-preview?monthly=25000   what SSS / PhilHealth / Pag-IBIG would be */
    public function contributionPreview(Request $request)
    {
        $d = $request->validate(['monthly' => ['required', 'numeric', 'min:0'], 'date' => ['nullable', 'date']]);
        return response()->json($this->payrollCalc->contributions((float) $d['monthly'], $d['date'] ?? $this->now()->toDateString()));
    }

    /* GET /hr/withholding-tax?frequency=semi_monthly */
    public function withholdingTax(Request $request)
    {
        $f = $request->query('frequency', 'semi_monthly');
        return response()->json(WithholdingTax::query()->where('frequency', $f)->orderByDesc('effective_from')->orderBy('compensation_from')->get());
    }

    /* =====================================================================
       EMPLOYEE: shift, salary history, bank, leave credits
       ===================================================================== */

    /* GET /hr/employees?status=active|inactive|all   employees for the list and pickers (shift, default rate, company) */
    public function employees(Request $request)
    {
        $status = $request->query('status', 'active');
        $shifts = Shift::query()->pluck('name', 'id');
        $default = Shift::default();
        $rows = Employee::query()
            ->when($status === 'active', fn ($q) => $q->where('is_active', 1))
            ->when($status === 'inactive', fn ($q) => $q->where(fn ($w) => $w->where('is_active', 0)->orWhereNull('is_active')))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'last_name', 'first_name', 'middle_name', 'suffix', 'designation_id', 'shift_id', 'salary_month', 'salary_day', 'company_caid', 'is_active', 'birthdate']);

        // user type = m_user_type of designation_id
        $typeTable = $this->lookupTable('designations');
        $types = collect($this->lookupRows('designations'))->pluck('label', 'value');

        // company = the client account in company_caid
        $companies = ClientAccount::query()->whereIn('id', $rows->pluck('company_caid')->filter()->unique()->values()->all() ?: [0])
            ->get(['id', 'branch_code', 'trade_name'])
            ->mapWithKeys(fn ($a) => [$a->id => trim((string) $a->trade_name) ?: trim((string) $a->branch_code)]);

        return response()->json($rows->map(fn ($e) => [
            'id' => $e->id, 'name' => $e->full_name, 'designation_id' => $e->designation_id, 'is_active' => (bool) $e->is_active,
            'company_caid' => $e->company_caid, 'company' => $companies[$e->company_caid] ?? '',
            'shift_id' => $e->shift_id, 'shift' => $e->shift_id ? ($shifts[$e->shift_id] ?? '') : ($default?->name ?? ''),
            'shift_is_default' => !$e->shift_id, 'salary_month' => (float) $e->salary_month, 'salary_day' => (float) $e->salary_day,
            'user_type' => $e->designation_id ? ($types[(int) $e->designation_id] ?? '') : '',
            'birthdate' => $e->birthdate ? substr((string) $e->birthdate, 0, 10) : null,
        ]));
    }

    /* GET /hr/employees/{id}   everything HR about one employee */
    public function employee(Request $request, int $id)
    {
        $e = Employee::findOrFail($id);
        $year = (int) ($request->query('year') ?: $this->now()->year);
        $shift = $this->attendanceCalc->shiftOf($e);
        return response()->json([
            'employee'      => $e->only(['id', 'last_name', 'first_name', 'middle_name', 'suffix', 'sss_no', 'phic_no', 'hdmf_no', 'tin', 'shift_id', 'salary_month', 'salary_day']) + ['full_name' => $e->full_name],
            'profile'       => array_intersect_key($e->toArray(), array_flip(array_merge(['id'], array_keys($this->employeeRules())))),
            'photo_url'     => $this->photoUrl($e->photo_path ?? null),
            'shift'         => $shift,
            'shift_is_default' => !$e->shift_id,
            'shift_history' => EmployeeShiftLog::query()->with('shift:id,code,name')->where('employee_id', $id)->orderByDesc('effective_from')->orderByDesc('id')->get(),
            'salaries'      => EmployeeSalary::query()->where('employee_id', $id)->orderByDesc('effective_from')->orderByDesc('id')->get(),
            'banks'         => EmployeeBank::query()->where('employee_id', $id)->orderByDesc('is_default')->get(),
            'leave_credits' => EmployeeLeaveCredit::query()->with('leaveType:id,code,name')->where('employee_id', $id)->where('year', $year)->get(),
        ]);
    }

    /* PUT /hr/employees/{id}/shift   { shift_id (null = default shift), effective_from, reason } */
    public function setEmployeeShift(Request $request, int $id)
    {
        $d = $request->validate($this->shiftRules());
        $e = Employee::findOrFail($id);
        DB::transaction(fn () => $this->applyShift($e, $d, $this->me($request)));
        return response()->json(['message' => 'Shift updated.']);
    }

    private function shiftRules(string $p = ''): array
    {
        return [
            "{$p}shift_id" => ['nullable', 'integer', 'exists:m_shift,id'], "{$p}effective_from" => ['nullable', 'date'], "{$p}reason" => ['nullable', 'string', 'max:255'],
        ];
    }

    private function applyShift(Employee $e, array $d, $me): void
    {
        EmployeeShiftLog::create([
            'employee_id' => $e->id, 'shift_id' => $d['shift_id'] ?? null, 'previous_shift_id' => $e->shift_id,
            'effective_from' => $d['effective_from'] ?? $this->now()->toDateString(), 'reason' => $d['reason'] ?? null,
            'changed_by' => $me,
        ]);
        $e->update(['shift_id' => $d['shift_id'] ?? null]);
    }

    /**
     * POST /hr/employees/{id}/salaries   add a rate (old rates stay as history).
     * is_default → the previous default ends the day before and m_employee.salary_month / salary_day are updated.
     */
    public function addSalary(Request $request, int $id)
    {
        $d = $request->validate($this->salaryRules());
        $e = Employee::findOrFail($id);
        $row = DB::transaction(fn () => $this->insertSalary($e, $d, $this->me($request)));
        return response()->json(['message' => 'Salary saved.', 'salary' => $row]);
    }

    private function salaryRules(string $p = ''): array
    {
        return [
            "{$p}salary_type" => ['required', 'in:daily,monthly'],
            "{$p}daily_rate" => ['nullable', 'numeric', 'min:0', "required_if:{$p}salary_type,daily"],
            "{$p}monthly_rate" => ['nullable', 'numeric', 'min:0', "required_if:{$p}salary_type,monthly"],
            "{$p}allowance" => ['nullable', 'numeric', 'min:0'], "{$p}work_days_per_year" => ['nullable', 'numeric', 'min:200', 'max:366'],
            "{$p}description" => ['nullable', 'string', 'max:150'], "{$p}reason" => ['nullable', 'string', 'max:255'],
            "{$p}effective_from" => ['required', 'date'], "{$p}is_default" => ['boolean'],
        ];
    }

    private function insertSalary(Employee $e, array $d, $me): EmployeeSalary
    {
        $days = (float) ($d['work_days_per_year'] ?? 313);
        if ($d['salary_type'] === 'monthly') $d['daily_rate'] = $d['daily_rate'] ?? round($d['monthly_rate'] * 12 / $days, 2);
        if ($d['salary_type'] === 'daily') $d['monthly_rate'] = $d['monthly_rate'] ?? round($d['daily_rate'] * $days / 12, 2);
        $row = EmployeeSalary::create($d + [
            'employee_id' => $e->id, 'work_days_per_year' => $days, 'is_default' => false, 'status' => 'approved',
            'approved_by' => $me, 'approved_at' => $this->now(), 'created_by' => $me,
        ]);
        if (!empty($d['is_default']) || !EmployeeSalary::query()->where('employee_id', $e->id)->where('is_default', true)->exists()) {
            $this->makeDefaultSalary($row);
        }
        return $row->fresh();
    }

    /* PUT /hr/salaries/{id}/default */
    public function setDefaultSalary(int $id)
    {
        DB::transaction(fn () => $this->makeDefaultSalary(EmployeeSalary::findOrFail($id)));
        return response()->json(['message' => 'Default salary updated.']);
    }

    private function makeDefaultSalary(EmployeeSalary $row): void
    {
        $from = CarbonImmutable::parse($row->effective_from, self::TZ);

        EmployeeSalary::query()
            ->where('employee_id', $row->employee_id)
            ->where('is_default', true)
            ->where('id', '!=', $row->id)
            ->each(function (EmployeeSalary $old) use ($from) {
                $old->update([
                    'is_default'   => false,
                    'effective_to' => $old->effective_to ?? $from->subDay()->toDateString(),
                ]);
            });

        $row->update(['is_default' => true, 'effective_to' => null]);

        Employee::query()->whereKey($row->employee_id)->update([
            'salary_month' => $row->monthly_rate,
            'salary_day'   => $row->daily_rate,
        ]);
    }

    /* POST /hr/employees/{id}/banks   PUT /hr/banks/{bankId}   DELETE /hr/banks/{bankId} */
    public function saveBank(Request $request, ?int $id = null, ?int $bankId = null)
    {
        $d = $request->validate($this->bankRules());
        $bank = DB::transaction(fn () => $this->putBank($d, $id, $bankId, $this->me($request)));
        return response()->json(['message' => 'Bank account saved.', 'bank' => $bank]);
    }

    private function bankRules(string $p = ''): array
    {
        return [
            "{$p}bank_name" => ['required', 'string', 'max:100'], "{$p}branch" => ['nullable', 'string', 'max:100'],
            "{$p}account_name" => ['required', 'string', 'max:150'], "{$p}account_no" => ['required', 'string', 'max:50'],
            "{$p}account_type" => ['nullable', 'string', 'max:20'], "{$p}is_default" => ['boolean'], "{$p}is_active" => ['boolean'],
        ];
    }

    private function putBank(array $d, ?int $id, ?int $bankId, $me): EmployeeBank
    {
        $bank = $bankId ? EmployeeBank::findOrFail($bankId) : new EmployeeBank(['employee_id' => $id, 'created_by' => $me]);
        $bank->fill($d)->save();
        $first = !EmployeeBank::query()->where('employee_id', $bank->employee_id)->where('id', '!=', $bank->id)->where('is_default', true)->exists();
        if (!empty($d['is_default']) || $first) {
            EmployeeBank::query()->where('employee_id', $bank->employee_id)->where('id', '!=', $bank->id)->update(['is_default' => false]);
            $bank->update(['is_default' => true]);
        }
        return $bank->fresh();
    }

    public function updateBank(Request $request, int $bankId)
    {
        return $this->saveBank($request, null, $bankId);
    }

    public function deleteBank(int $bankId)
    {
        EmployeeBank::findOrFail($bankId)->update(['is_active' => false, 'is_default' => false]);
        return response()->json(['message' => 'Bank account removed.']);
    }

    /* PUT /hr/employees/{id}/leave-credits   { year, leave_type_id, allowed_days, carried_over } */
    public function saveLeaveCredit(Request $request, int $id)
    {
        $d = $request->validate($this->creditRules());
        $row = $this->putCredit($id, $d, $this->me($request));
        return response()->json(['message' => 'Leave credit saved.', 'credit' => $row]);
    }

    private function creditRules(string $p = ''): array
    {
        return [
            "{$p}year" => ['required', 'integer', 'min:2000'], "{$p}leave_type_id" => ['required', 'integer', 'exists:m_leave_type,id'],
            "{$p}allowed_days" => ['required', 'numeric', 'min:0', 'max:366'], "{$p}carried_over" => ['nullable', 'numeric', 'min:0', 'max:366'],
        ];
    }

    private function putCredit(int $id, array $d, $me): EmployeeLeaveCredit
    {
        return EmployeeLeaveCredit::updateOrCreate(
            ['employee_id' => $id, 'leave_type_id' => $d['leave_type_id'], 'year' => $d['year']],
            ['allowed_days' => $d['allowed_days'], 'carried_over' => $d['carried_over'] ?? 0, 'created_by' => $me]
        );
    }

    /* POST /hr/leave-credits/generate   { year }  every active employee gets each leave type's default days (existing rows are kept) */
    public function generateLeaveCredits(Request $request)
    {
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000']]);
        $types = LeaveType::query()->where('is_active', true)->where('default_days', '>', 0)->get();
        $made = 0;
        foreach (Employee::query()->where('is_active', 1)->pluck('id') as $empId) {
            foreach ($types as $t) {
                $row = EmployeeLeaveCredit::firstOrCreate(
                    ['employee_id' => $empId, 'leave_type_id' => $t->id, 'year' => $d['year']],
                    ['allowed_days' => $t->default_days, 'created_by' => $this->me($request)]
                );
                if ($row->wasRecentlyCreated) $made++;
            }
        }
        return response()->json(['message' => "{$made} leave credit(s) created."]);
    }

    /* =====================================================================
       ATTENDANCE
       ===================================================================== */

    /* GET /hr/attendance?from=&to=&employee_id= */
    public function attendance(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'employee_id' => ['nullable', 'integer']]);
        return response()->json(Attendance::query()->with('employee:id,last_name,first_name,middle_name', 'shift:id,code,name')
            ->whereBetween('work_date', [$d['from'], $d['to']])
            ->when($d['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('work_date')->orderBy('time_in')->get()); // latest date first, earliest time in first
    }

    /* POST /hr/attendance   manual entry / correction of a day (punches as "YYYY-MM-DD HH:MM") */
    public function saveAttendance(Request $request)
    {
        $d = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:m_employee,id'], 'work_date' => ['required', 'date'],
            'shift_id' => ['nullable', 'integer', 'exists:m_shift,id'],
            'time_in' => ['nullable', 'date'], 'break_in' => ['nullable', 'date'], 'break_out' => ['nullable', 'date'], 'time_out' => ['nullable', 'date'],
            'status' => ['nullable', 'in:present,absent,on_leave,half_day,holiday,rest_day'], 'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $row = Attendance::firstOrNew(['employee_id' => $d['employee_id'], 'work_date' => $d['work_date']]);
        if ($row->payroll_run_id) abort(422, 'This day is already in an approved payroll.');
        $row->fill($d + ['source' => 'manual', 'updated_by' => $this->me($request)]);
        if (!$row->exists) $row->created_by = $this->me($request);
        $shift = !empty($d['shift_id']) ? Shift::find($d['shift_id']) : $this->attendanceCalc->shiftOf(Employee::find($d['employee_id']));
        $this->attendanceCalc->compute($row, $shift)->save();
        return response()->json(['message' => 'Attendance saved.', 'attendance' => $row->fresh()]);
    }

    /* =====================================================================
       BIOMETRIC  (TXT from the device → preview → submit)
       ===================================================================== */

    /* GET /hr/biometric/map   EnNo → employee */
    public function biometricMap()
    {
        $rows = BiometricMap::query()->with('employee:id,last_name,first_name,middle_name,suffix')->orderByRaw('CAST(en_no AS UNSIGNED)')->get();
        return response()->json($rows->map(fn ($m) => [
            'id' => $m->id, 'en_no' => $m->en_no, 'employee_id' => $m->employee_id, 'device_name' => $m->device_name,
            'employee' => $m->employee?->full_name ?? '(employee not found)',
        ]));
    }

    /* POST /hr/biometric/map   { en_no, employee_id, device_name? }  add or change */
    public function saveBiometricMap(Request $request)
    {
        $d = $request->validate([
            'en_no' => ['required', 'string', 'max:20'], 'employee_id' => ['required', 'integer', 'exists:m_employee,id'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $taken = BiometricMap::query()->where('employee_id', $d['employee_id'])->where('en_no', '!=', $d['en_no'])->value('en_no');
        if ($taken) abort(422, "This employee already has EnNo {$taken}.");
        BiometricMap::updateOrCreate(['en_no' => $d['en_no']], $d + ['created_by' => $this->me($request)]);
        BiometricLog::query()->where('en_no', $d['en_no'])->update(['employee_id' => $d['employee_id']]);
        return response()->json(['message' => "EnNo {$d['en_no']} saved."]);
    }

    /* DELETE /hr/biometric/map/{id} */
    public function deleteBiometricMap(int $id)
    {
        BiometricMap::findOrFail($id)->delete();
        return response()->json(['message' => 'EnNo removed.']);
    }

    /* POST /hr/biometric/preview   { rows: [{ en_no, name, mode, at: "Y-m-d H:i:s" }] }  nothing is saved */
    public function biometricPreview(Request $request)
    {
        $request->validate(['rows' => ['required', 'array', 'max:100000']]);
        @set_time_limit(300);
        return response()->json(app(BiometricImporter::class)->preview($request->input('rows')));
    }

    /* POST /hr/biometric/import   { rows }  new days are added, days already there are updated */
    public function biometricImport(Request $request)
    {
        $request->validate(['rows' => ['required', 'array', 'max:100000']]);
        @set_time_limit(300);
        $r = app(BiometricImporter::class)->import($request->input('rows'), $this->me($request));
        return response()->json(['message' => "Biometric saved: {$r['created']} new day(s), {$r['updated']} updated"
            . ($r['skipped_locked'] ? ", {$r['skipped_locked']} in an approved payroll not changed" : '') . '.', 'result' => $r]);
    }

    /* GET /hr/attendance/today   my attendance today (or last night's open overnight shift) for the punch clock */
    public function myToday(Request $request)
    {
        $me = $this->me($request);
        if (!$me) return response()->json(['attendance' => null, 'message' => 'No employee linked to your login.']);
        $now = $this->now();
        $open = Attendance::query()->where('employee_id', $me)->whereNotNull('time_in')->whereNull('time_out')
            ->whereDate('work_date', $now->subDay()->toDateString())->first();
        $row = $open ?? Attendance::query()->where('employee_id', $me)->whereDate('work_date', $now->toDateString())->first();
        $shift = $this->attendanceCalc->shiftOf(Employee::findOrFail($me));
        return response()->json(['attendance' => $row, 'shift' => $shift]);
    }

    /* POST /hr/attendance/punch   { employee_id?, type: time_in | break_in | break_out | time_out }  — now, Philippine time */
    public function punch(Request $request)
    {
        $d = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:m_employee,id'], 'type' => ['required', 'in:time_in,break_in,break_out,time_out'],
            'source' => ['nullable', 'in:biometric,manual,mobile,web'],
        ]);
        $empId = $d['employee_id'] ?? $this->me($request) ?? abort(422, 'No employee linked to your login.');
        $e = Employee::findOrFail($empId);
        $now = $this->now();
        $shift = $this->attendanceCalc->shiftOf($e);

        // the work date: today, or yesterday when an overnight shift started yesterday and is still open
        $workDate = $now->toDateString();
        if ($d['type'] !== 'time_in') {
            $open = Attendance::query()->where('employee_id', $empId)->whereNotNull('time_in')->whereNull('time_out')
                ->whereBetween('work_date', [$now->subDay()->toDateString(), $now->toDateString()])->orderByDesc('work_date')->first();
            if ($open) $workDate = \Carbon\Carbon::parse($open->work_date)->toDateString();
        }
        $row = Attendance::firstOrNew(['employee_id' => $empId, 'work_date' => $workDate]);
        if ($row->{$d['type']}) abort(422, ucwords(str_replace('_', ' ', $d['type'])) . ' is already recorded today.');
        if ($d['type'] !== 'time_in' && !$row->time_in) abort(422, 'Time in first.');
        $row->{$d['type']} = $now;
        $row->source = $d['source'] ?? 'web';
        if (!$row->exists) $row->created_by = $this->me($request);
        $this->attendanceCalc->compute($row, $shift)->save();
        return response()->json(['message' => ucwords(str_replace('_', ' ', $d['type'])) . ' ' . $now->format('h:i A'), 'attendance' => $row->fresh()]);
    }

    /* POST /hr/attendance/recompute   { from, to }  after a shift / holiday / night-diff change */
    public function recomputeAttendance(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $n = 0;
        Attendance::query()->whereBetween('work_date', [$d['from'], $d['to']])->whereNull('payroll_run_id')
            ->chunkById(500, function ($rows) use (&$n) {
                /** @var \Illuminate\Support\Collection<int, \App\Models\Attendance> $rows */
                foreach ($rows as $row) {
                    $this->attendanceCalc->compute($row)->save();
                    $n++;
                }
            });
        return response()->json(['message' => "{$n} day(s) recomputed."]);
    }

    /* =====================================================================
       OVERTIME (with approval)
       ===================================================================== */

    /* GET /hr/overtime?status=&employee_id=&from=&to= */
    public function overtime(Request $request)
    {
        return response()->json(Overtime::query()->with('employee:id,last_name,first_name', 'approver:id,last_name,first_name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('work_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('work_date', '<=', $v))
            ->orderByDesc('work_date')->limit(1000)->get());
    }

    /* POST /hr/overtime   { employee_id?, work_date, time_start, time_end, pay_rate_code?, reason } */
    public function fileOvertime(Request $request)
    {
        $d = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:m_employee,id'], 'work_date' => ['required', 'date'],
            'time_start' => ['required', 'date'], 'time_end' => ['required', 'date', 'after:time_start'],
            'pay_rate_code' => ['nullable', 'string', 'exists:m_pay_rate,code'], 'reason' => ['required', 'string', 'max:255'],
        ]);
        $empId = $d['employee_id'] ?? $this->me($request) ?? abort(422, 'No employee linked to your login.');
        $start = CarbonImmutable::parse($d['time_start'], self::TZ);
        $end = CarbonImmutable::parse($d['time_end'], self::TZ);
        $row = Overtime::create($d + [
            'employee_id' => $empId, 'minutes' => (int) $start->diffInMinutes($end), 'status' => 'pending', 'created_by' => $this->me($request),
        ]);
        return response()->json(['message' => 'Overtime filed.', 'overtime' => $row]);
    }

    /* PUT /hr/overtime/{id}/{action}   action = approve | reject | cancel   { remarks, minutes? (approved minutes) } */
    public function decideOvertime(Request $request, int $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel'], true), 404);
        $d = $request->validate(['remarks' => ['nullable', 'string', 'max:255'], 'minutes' => ['nullable', 'integer', 'min:1']]);
        $ot = Overtime::findOrFail($id);
        if ($ot->status !== 'pending' && $action !== 'cancel') abort(422, "This overtime is already {$ot->status}.");
        if ($ot->payroll_run_id) abort(422, 'This overtime is already paid.');
        $ot->update([
            'status' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action],
            'minutes' => $action === 'approve' && !empty($d['minutes']) ? $d['minutes'] : $ot->minutes,
            'approved_by' => $this->me($request), 'approved_by_name' => $this->loginName($request), 'approved_at' => $this->now(), 'approver_remarks' => $d['remarks'] ?? null,
        ]);
        if ($att = Attendance::query()->where('employee_id', $ot->employee_id)->whereDate('work_date', $ot->work_date)->first()) {
            $this->attendanceCalc->compute($att)->save();
        }
        return response()->json(['message' => 'Overtime ' . $ot->status . '.']);
    }

    /* =====================================================================
       LEAVE (with approval, against the employee's leave credit)
       ===================================================================== */

    /* GET /hr/leaves?status=&employee_id=&year= */
    public function leaves(Request $request)
    {
        return response()->json(LeaveRequest::query()->with('employee:id,last_name,first_name', 'leaveType:id,code,name', 'approver:id,last_name,first_name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('year'), fn ($q, $v) => $q->whereYear('date_from', $v))
            ->orderByDesc('date_from')->limit(1000)->get());
    }

    /* POST /hr/leaves   { employee_id?, leave_type_id, date_from, date_to, is_half_day, half_day_part, reason } */
    public function fileLeave(Request $request)
    {
        $d = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:m_employee,id'], 'leave_type_id' => ['required', 'integer', 'exists:m_leave_type,id'],
            'date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'is_half_day' => ['boolean'], 'half_day_part' => ['nullable', 'in:AM,PM'], 'reason' => ['required', 'string', 'max:255'],
            'attachment' => ['nullable', 'string', 'max:255'],
        ]);
        $empId = $d['employee_id'] ?? $this->me($request) ?? abort(422, 'No employee linked to your login.');
        $days = !empty($d['is_half_day']) ? 0.5 : $this->workingDays(Employee::findOrFail($empId), $d['date_from'], $d['date_to']);
        if ($days <= 0) abort(422, 'There are no working days in that range.');
        $this->checkCredit($empId, (int) $d['leave_type_id'], (int) substr($d['date_from'], 0, 4), $days);

        $row = LeaveRequest::create($d + ['employee_id' => $empId, 'days' => $days, 'status' => 'pending', 'created_by' => $this->me($request)]);
        return response()->json(['message' => "Leave filed ({$days} day(s)).", 'leave' => $row]);
    }

    /* PUT /hr/leaves/{id}/{action}   action = approve | reject | cancel */
    public function decideLeave(Request $request, int $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel'], true), 404);
        $d = $request->validate(['remarks' => ['nullable', 'string', 'max:255']]);
        $leave = LeaveRequest::with('leaveType')->findOrFail($id);
        if ($action !== 'cancel' && $leave->status !== 'pending') abort(422, "This leave is already {$leave->status}.");

        DB::transaction(function () use ($leave, $action, $d, $request) {
            $dateFrom = CarbonImmutable::parse($leave->date_from);
            $dateTo   = CarbonImmutable::parse($leave->date_to);

            $year = $dateFrom->year;
            $wasApproved = $leave->status === 'approved';
            if ($action === 'approve') $this->checkCredit($leave->employee_id, $leave->leave_type_id, $year, (float) $leave->days);

            $leave->update([
                'status' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action],
                'approved_by' => $this->me($request), 'approved_by_name' => $this->loginName($request), 'approved_at' => $this->now(), 'approver_remarks' => $d['remarks'] ?? null,
            ]);
            // used days on the credit
            $credit = EmployeeLeaveCredit::query()->where('employee_id', $leave->employee_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $year)->first();
            if ($credit && $action === 'approve') $credit->increment('used_days', (float) $leave->days);
            if ($credit && $action === 'cancel' && $wasApproved) $credit->decrement('used_days', (float) $leave->days);

            // attendance of those days
            $status = $action === 'approve' ? 'on_leave' : null;
            for ($day = $dateFrom; $day->lessThanOrEqualTo($dateTo); $day = $day->addDay()) {
                $att = Attendance::firstOrNew(['employee_id' => $leave->employee_id, 'work_date' => $day->toDateString()]);
                if ($att->payroll_run_id || $att->time_in) continue;
                if ($status) { $att->status = $status; $att->remarks = $leave->leaveType?->code; $this->attendanceCalc->compute($att)->save(); }
                elseif ($att->exists && $att->status === 'on_leave') { $att->status = 'absent'; $this->attendanceCalc->compute($att)->save(); }
            }
        });
        return response()->json(['message' => 'Leave ' . $leave->fresh()->status . '.']);
    }

    /** working days of an employee between two dates (shift work days, holidays skipped) */
    private function workingDays(Employee $e, string $from, string $to): float
    {
        $shift = $this->attendanceCalc->shiftOf($e);
        $days = 0;
        for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            if (!$shift || $this->attendanceCalc->dayType($shift, $d->toDateString()) === 'regular') $days++;
        }
        return (float) $days;
    }

    /** the employee must have enough credit for a paid leave type */
    private function checkCredit(int $empId, int $typeId, int $year, float $days): void
    {
        $type = LeaveType::findOrFail($typeId);
        if (!$type->is_paid) return;
        $credit = EmployeeLeaveCredit::query()->where('employee_id', $empId)->where('leave_type_id', $typeId)->where('year', $year)->first();
        $remaining = $credit ? $credit->remaining_days : 0;
        if ($days > $remaining + 0.001) abort(422, "Not enough {$type->name} credit: {$remaining} day(s) left, {$days} asked.");
    }

    /* =====================================================================
       PAYROLL
       ===================================================================== */

    /* GET /hr/payroll?year= */
    public function payrollRuns(Request $request)
    {
        $year = (int) ($request->query('year') ?: $this->now()->year);
        return response()->json(PayrollRun::query()->whereYear('period_to', $year)->orderByDesc('period_from')->orderByDesc('id')->get());
    }

    /* =====================================================================
       EMPLOYEE PROFILE (m_employee): add / edit
       ===================================================================== */

    /** lookup tables of the employee form: [key => [model, fallback table]] */
    private const EMP_LOOKUPS = [
        'designations'        => ['App\Models\UserType', 'm_user_type'],
        'employment_statuses' => ['App\Models\EmploymentStatus', 'm_employment_status'],
        'civil_statuses'      => ['App\Models\CivilStatus', 'm_civil_status'],
        'religions'           => ['App\Models\Religion', 'm_religion'],
        'citizenships'        => ['App\Models\Citizenship', 'm_citizenship'],
        'provinces'           => ['App\Models\Province', 'm_province'],
        'cities'              => ['App\Models\CityMun', 'm_city_mun'],
        'barangays'           => ['App\Models\Barangay', 'm_barangay'],
    ];

    private function lookupTable(string $key): ?string
    {
        [$model, $table] = self::EMP_LOOKUPS[$key];
        $t = class_exists($model) ? (new $model)->getTable() : $table;
        return Schema::hasTable($t) ? $t : null;
    }

    /** primary key of a lookup table (the model's key, else id, else the first column) */
    private function lookupKey(string $key, string $table): string
    {
        [$model] = self::EMP_LOOKUPS[$key];
        $cols = Schema::getColumnListing($table);
        $pk = class_exists($model) ? (new $model)->getKeyName() : 'id';
        return in_array($pk, $cols, true) ? $pk : (in_array('id', $cols, true) ? 'id' : $cols[0]);
    }

    /**
     * the column to show of a lookup table:
     *   a known name (name, description, provDesc, citymunDesc, brgyDesc …) → any column with "desc" / "name" / "title"
     *   → the first text column that is not a code / id
     */
    private function labelColumn(string $table, string $pk): string
    {
        $cols = collect(Schema::getColumns($table));
        $names = $cols->pluck('name');
        $lower = $names->mapWithKeys(fn ($n) => [strtolower($n) => $n]);
        foreach (['name', 'description', 'title', 'provdesc', 'citymundesc', 'brgydesc', 'province_name', 'city_name', 'citymun_name', 'barangay_name', 'brgy_name',
            'province', 'city_mun', 'citymun', 'city', 'municipality', 'barangay', 'brgy', 'status', 'religion', 'citizenship', 'nationality', 'type', 'user_type'] as $want) {
            if (isset($lower[$want]) && $lower[$want] !== $pk) return $lower[$want];
        }
        $isKey = fn ($n) => $n === $pk || preg_match('/(^id$|_id$|id$|code$|_code|^code|psgc|reg|created|updated|deleted)/i', $n);
        $hit = $names->first(fn ($n) => !$isKey($n) && preg_match('/desc|name|title|label/i', $n));
        if ($hit) return $hit;
        $text = $cols->first(fn ($c) => !$isKey($c['name']) && preg_match('/char|text|string/i', (string) ($c['type_name'] ?? $c['type'] ?? '')));
        return $text['name'] ?? $pk;
    }

    /** value + label of a lookup table */
    private function lookupRows(string $key, ?callable $where = null, int $limit = 3000): array
    {
        $table = $this->lookupTable($key);
        if (!$table) return [];
        $pk = $this->lookupKey($key, $table);
        $label = $this->labelColumn($table, $pk);
        $q = DB::table($table)->select(DB::raw("`{$pk}` as v"), DB::raw("`{$label}` as label"));
        if ($where) $where($q, Schema::getColumnListing($table), $pk);
        return $q->orderBy($label)->limit($limit)->get()
            ->map(fn ($r) => ['value' => is_numeric($r->v) ? (int) $r->v : $r->v, 'label' => trim((string) $r->label) !== '' ? trim((string) $r->label) : (string) $r->v])->all();
    }

    /* GET /hr/employee-lookups   everything the employee form needs (cities / barangays come by parent) */
    public function employeeLookups(Request $request)
    {
        $out = [];
        foreach (['designations', 'employment_statuses', 'civil_statuses', 'religions', 'citizenships', 'provinces'] as $key) {
            $out[$key] = $this->lookupRows($key);
        }
        $out['companies'] = ClientAccount::query()->get(['id', 'branch_code', 'trade_name'])
            ->map(fn ($a) => ['value' => $a->id, 'label' => trim((string) $a->trade_name) ?: trim((string) $a->branch_code)])
            ->sortBy('label')->values();
        $out['shifts'] = Shift::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['value' => $x->id, 'label' => $x->name]);
        // Work → Company on the form: my client's accounts (MAIN + branches) · default = my own company
        $out['my_companies'] = collect($this->myCompanies($request))->map(fn ($l, $id) => ['value' => $id, 'label' => $l])->values();
        $out['my_company'] = $this->myCompany($request);
        return response()->json($out);
    }

    /* GET /hr/places/{kind}?parent=   kind = cities (parent = province) | barangays (parent = city) */
    public function places(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['cities', 'barangays'], true), 404);
        return response()->json($this->placeRows($kind, (string) $request->query('parent')));
    }

    /** cities of a province / barangays of a city ("all" = every one) */
    private function placeRows(string $kind, string $parent): array
    {
        if ($parent === '') return [];
        // the parent: province of a city · city of a barangay
        [$parentKey, $idCol, $codeCol] = $kind === 'cities'
            ? ['provinces', '/^(province|prov)_?id$/i', '/^(province|prov)_?code$/i']
            : ['cities', '/^(city|citymun|city_mun|municipality|mun)_?id$/i', '/^(city|citymun|city_mun|municipality|mun)_?code$/i'];

        return $this->lookupRows($kind, function ($q, $cols, $pk) use ($parent, $parentKey, $idCol, $codeCol) {
            if ($parent === 'all') return;
            // 1) linked by the parent's id (province_id …)
            $col = collect($cols)->first(fn ($c) => $c !== $pk && preg_match($idCol, $c));
            if ($col) { $q->where($col, $parent); return; }
            // 2) linked by the parent's code (provCode, citymunCode … as in the PSGC tables)
            $col = collect($cols)->first(fn ($c) => preg_match($codeCol, $c));
            $pt = $this->lookupTable($parentKey);
            if ($col && $pt) {
                $pcode = collect(Schema::getColumnListing($pt))->first(fn ($c) => preg_match($codeCol, $c));
                $code = $pcode ? DB::table($pt)->where($this->lookupKey($parentKey, $pt), $parent)->value($pcode) : null;
                $q->where($col, $code ?? '__none__');
                return;
            }
            $q->whereRaw('1 = 0'); // no link column found
        });
    }

    /** com_id of a new employee = the logged-in user's com_id – nothing is added to m_client_account */
    private function userComId(Request $request): int
    {
        $com = (int) $this->userComIdOrNull($request);
        abort_if($com <= 0, 422, 'Your login has no com_id, so the employee can\'t be added. Ask IT to set m_user_staff.comid for your account.');
        return $com;
    }

    /** the logged-in user's com_id: m_user_staff.comid, else the com_id of his own employee record */
    private function userComIdOrNull(Request $request): ?int
    {
        $u = $request->user();
        if (!$u) return null;
        $com = (int) ($u->comid ?? 0);
        if ($com <= 0 && Schema::hasTable('m_user_staff') && Schema::hasColumn('m_user_staff', 'comid')) {
            $com = (int) DB::table('m_user_staff')->where('id', $u->id ?? 0)->value('comid');
        }
        if ($com <= 0 && !empty($u->employee_id)) {
            $com = (int) DB::table('m_employee')->where('id', $u->employee_id)->value('com_id');
        }
        return $com > 0 ? $com : null;
    }

    /** the logged-in user's own company: company_caid of his employee record (m_employee.com_id = his comid) */
    private function myCompany(Request $request): ?int
    {
        $com = $this->userComIdOrNull($request);
        if (!$com) return null;
        $caid = (int) DB::table('m_employee')->where('com_id', $com)->whereNotNull('company_caid')->orderByDesc('is_active')->value('company_caid');
        return $caid > 0 ? $caid : null;
    }

    /**
     * Company dropdown: every ACTIVE client account of the same client as my company (same client_num)
     *   e.g. Angelo → MAIN 00000 IGB ACCOUNTING … and all its branches (00001 ICB CONSTRUCTION SUPPLIES …)
     * → [id => "MAIN 00000 – IGB ACCOUNTING AND BUSINESS CONSULTANCY"]   (empty = no company of my own → all)
     */
    private function myCompanies(Request $request): array
    {
        $mine = $this->myCompany($request);
        if (!$mine) return [];
        $num = DB::table('m_client_account')->where('id', $mine)->value('client_num');
        $q = ClientAccount::query();
        ($num !== null && $num !== '') ? $q->where('client_num', $num) : $q->whereKey($mine);
        // active accounts only
        $active = collect(['is_active', 'active'])->first(fn ($c) => Schema::hasColumn('m_client_account', $c));
        if ($active) $q->where($active, 1);
        return $q->get(['id', 'branch_code', 'branch_type', 'trade_name'])
            ->sortBy(fn ($a) => [strtoupper((string) $a->branch_type) === 'MAIN' ? 0 : 1, (string) $a->branch_code, (string) $a->trade_name])
            ->mapWithKeys(fn ($a) => [(int) $a->id => trim(implode(' ', array_filter([strtoupper((string) $a->branch_type), (string) $a->branch_code])) . ' – ' . trim((string) $a->trade_name), ' –')])
            ->all();
    }

    /** a company picked on the form must be one of myCompanies() */
    private function checkCompany(Request $request, $caid): void
    {
        if ($caid === null || $caid === '') return;
        $list = $this->myCompanies($request);
        abort_if($list && !array_key_exists((int) $caid, $list), 422, 'That company is not one of your client accounts.');
    }

    /**
     * POST /hr/employees   add an employee, all in one save:
     *   profile fields + optional shift {…} · salary {…} · banks [{…}] · leave_credits [{…}] · photo (data URL → S3)
     *   everything but the photo is one transaction (nothing is saved when one part fails)
     *   com_id = the logged-in user's com_id (no new m_client_account row)
     */
    public function createEmployee(Request $request)
    {
        $rules = $this->employeeRules();
        $extra = ['shift' => ['nullable', 'array'], 'salary' => ['nullable', 'array'], 'banks' => ['nullable', 'array', 'max:10'],
            'leave_credits' => ['nullable', 'array', 'max:50'], 'photo' => ['nullable', 'string']];
        if ($request->filled('shift')) $extra += $this->shiftRules('shift.');
        if ($request->filled('salary')) $extra += $this->salaryRules('salary.');
        if ($request->filled('banks')) $extra += $this->bankRules('banks.*.');
        if ($request->filled('leave_credits')) $extra += $this->creditRules('leave_credits.*.');
        $d = $request->validate($rules + $extra, [], [
            'salary.monthly_rate' => 'monthly rate', 'salary.daily_rate' => 'daily rate', 'salary.effective_from' => 'salary effective date',
            'banks.*.bank_name' => 'bank', 'banks.*.account_name' => 'account name', 'banks.*.account_no' => 'account no.',
            'leave_credits.*.leave_type_id' => 'leave type', 'leave_credits.*.allowed_days' => 'allowed days',
        ]);
        $profile = array_intersect_key($d, $rules);
        $me = $this->me($request);

        // com_id = the logged-in user's com_id · company = the one picked, default my own company (e.g. MAIN 00000 IGB ACCOUNTING …)
        $comId = $this->userComId($request);
        if (empty($profile['company_caid'])) $profile['company_caid'] = $this->myCompany($request);
        $this->checkCompany($request, $profile['company_caid'] ?? null);

        $e = DB::transaction(function () use ($d, $profile, $me, $comId) {
            $e = new Employee();
            $e->forceFill($this->fkValid('m_employee', $this->notNullSafe('m_employee', array_merge($profile, [
                'is_active' => $profile['is_active'] ?? true,
                'com_id'    => $comId,
            ]))))->save();
            if (!empty($d['shift']['shift_id'])) $this->applyShift($e, $d['shift'], $me);
            if (!empty($d['salary'])) $this->insertSalary($e, $d['salary'] + ['is_default' => true], $me);
            foreach ($d['banks'] ?? [] as $b) $this->putBank($b + ['is_active' => true], $e->id, null, $me);
            foreach ($d['leave_credits'] ?? [] as $c) $this->putCredit($e->id, $c, $me);
            return $e->fresh();
        });

        $message = "{$e->full_name} added.";
        if (!empty($d['photo'])) {
            try {
                $this->storePhoto($e, $d['photo']);
            } catch (\Throwable $x) {
                $message .= ' The photo was not saved: ' . ($x instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $x->getMessage() : 'S3 upload failed.') . ' Upload it again.';
            }
        }
        return response()->json(['message' => $message, 'employee' => ['id' => $e->id, 'name' => $e->full_name]]);
    }

    /** S3 folder of the employee photos */
    const PHOTO_FOLDER = 'Employee_Profile';

    /* POST /hr/employees/{id}/photo   { photo: "data:image/jpeg;base64,…" } · { photo: null } removes it  →  S3 Employee_Profile/ */
    public function saveEmployeePhoto(Request $request, int $id)
    {
        $e = Employee::findOrFail($id);
        $data = $request->input('photo');
        if (!$data) {
            $old = $e->photo_path ?? null;
            if ($old) Storage::disk('s3')->delete($old);
            Employee::query()->whereKey($id)->update(['photo_path' => null]);
            return response()->json(['message' => 'Photo removed.', 'photo_url' => null]);
        }
        $key = $this->storePhoto($e, (string) $data);
        return response()->json(['message' => 'Photo saved.', 'photo_url' => $this->photoUrl($key)]);
    }

    /**
     * every single-column foreign key of $table: a value that doesn't exist in its table
     * (0, empty, or old data like nob_id) → NULL when the column allows it, else the first row of that table
     */
    private function fkValid(string $table, array $row): array
    {
        $cols = collect(Schema::getColumns($table))->keyBy('name');
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) !== 1) continue;
            $col = $fk['columns'][0];
            $ref = $fk['foreign_columns'][0];
            if (!array_key_exists($col, $row)) continue;
            $v = $row[$col];
            if ($v !== null && $v !== '' && DB::table($fk['foreign_table'])->where($ref, $v)->exists()) continue;
            $row[$col] = ($cols[$col]['nullable'] ?? false) ? null : DB::table($fk['foreign_table'])->orderBy($ref)->value($ref);
        }
        return $row;
    }

    /**
     * NOT NULL columns can't take NULL: an empty field gets the column default,
     * or 0 for numbers / '' for text when the column has none (allowance, etc.).
     * Dates without a value are left out. $onlyGiven = true (update): only the fields sent.
     */
    private function notNullSafe(string $table, array $row, bool $onlyGiven = false): array
    {
        foreach (Schema::getColumns($table) as $c) {
            $name = $c['name'];
            if ($c['nullable'] || $c['auto_increment']) continue;
            if ($onlyGiven && !array_key_exists($name, $row)) continue;
            if (array_key_exists($name, $row) && $row[$name] !== null && $row[$name] !== '') continue;
            $type = strtolower($c['type_name']);
            $isNum = str_contains($type, 'int') || in_array($type, ['decimal', 'double', 'float', 'bit'], true);
            if ($c['default'] !== null) {
                if (array_key_exists($name, $row) && $row[$name] === null) unset($row[$name]);
                continue;
            }
            if (in_array($type, ['date', 'datetime', 'timestamp', 'time', 'year'], true)) { unset($row[$name]); continue; }
            if ($isNum) {
                $row[$name] = 0;
            } elseif (!array_key_exists($name, $row) || $row[$name] === null) {
                $row[$name] = '';
            }
        }
        return $row;
    }
    /* PUT /hr/employees/{id}   update the profile */
    public function updateEmployee(Request $request, int $id)
    {
        $e = Employee::findOrFail($id);
        $d = $request->validate($this->employeeRules());
        if (array_key_exists('company_caid', $d) && (int) $d['company_caid'] !== (int) $e->company_caid) $this->checkCompany($request, $d['company_caid']);
        $e->update($this->notNullSafe('m_employee', $d, true));
        return response()->json(['message' => "{$e->full_name} updated."]);
    }

    /* ---------- SSS / PhilHealth brackets (HR Settings) ---------- */
    private function bracketModel(string $type): string
    {
        return match ($type) { 'sss' => SssBracket::class, 'phic' => PhicBracket::class, default => abort(404) };
    }

    /* GET /hr/brackets/{type}   type = sss | phic */
    public function brackets(string $type)
    {
        $m = $this->bracketModel($type);
        return response()->json($m::query()->orderBy('range_from')->get());
    }

    /* POST /hr/brackets/{type} · PUT /hr/brackets/{type}/{id} */
    public function saveBracket(Request $request, string $type, ?int $id = null)
    {
        $m = $this->bracketModel($type);
        $common = [
            'bracket' => ['required', 'integer', 'min:1'], 'bracket_code' => ['required', 'string', 'max:20'],
            'range_from' => ['required', 'numeric', 'min:0'], 'range_to' => ['required', 'numeric', 'gte:range_from'],
            'mo_sal_credit' => ['required', 'numeric', 'min:0'], 'is_active' => ['boolean'],
        ];
        $d = $request->validate($common + ($type === 'sss'
            ? ['er' => ['required', 'numeric', 'min:0'], 'ee' => ['required', 'numeric', 'min:0'], 'ec' => ['required', 'numeric', 'min:0']]
            : ['ps' => ['required', 'numeric', 'min:0'], 'es' => ['required', 'numeric', 'min:0'], 'msb' => ['nullable', 'numeric', 'min:0']]));
        $overlap = $m::query()->where('is_active', true)->when($id, fn ($q) => $q->where('id', '!=', $id))
            ->where('range_from', '<=', $d['range_to'])->where('range_to', '>=', $d['range_from'])->value('bracket_code');
        if ($overlap && ($d['is_active'] ?? true)) abort(422, "The range overlaps bracket {$overlap}.");
        $row = $id ? tap($m::findOrFail($id))->update($d) : $m::create($d);
        return response()->json(['message' => "Bracket {$row->bracket_code} saved.", 'bracket' => $row]);
    }

    /* GET /hr/payroll-cutoffs */
    public function payrollCutoffs()
    {
        return response()->json(PayrollCutoff::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    /* POST /hr/payroll-cutoffs · PUT /hr/payroll-cutoffs/{id}
       { code, name, frequency, start_day, end_day (31 = end of month), pay_days_after, sss_share, philhealth_share, pagibig_share (0 | 0.5 | 1), deduct_loans, is_active, sort_order } */
    public function savePayrollCutoff(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('m_payroll_cutoff', 'code')->ignore($id)],
            'name' => ['nullable', 'string', 'max:50'], 'frequency' => ['required', 'in:semi_monthly,monthly'],
            'start_day' => ['required', 'integer', 'between:1,31'], 'end_day' => ['required', 'integer', 'between:1,31'],
            'pay_days_after' => ['required', 'integer', 'between:0,31'],
            'sss_share' => ['required', 'numeric', 'in:0,0.5,1'], 'philhealth_share' => ['required', 'numeric', 'in:0,0.5,1'], 'pagibig_share' => ['required', 'numeric', 'in:0,0.5,1'],
            'deduct_loans' => ['boolean'], 'is_active' => ['boolean'], 'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['name'] = $d['name'] ?: "{$d['start_day']} – " . ($d['end_day'] >= 31 ? 'end' : $d['end_day']);
        $row = $id ? tap(PayrollCutoff::findOrFail($id))->update($d) : PayrollCutoff::create($d);
        return response()->json(['message' => "Cut-off {$row->name} saved.", 'cutoff' => $row]);
    }

    /**
     * POST /hr/payroll
     *   code?: type your own payroll code (blank = automatic PR-2026-10-A)
     *   from a cut-off: { cutoff_id, month: "2026-10" (the month the period ends), period_from?, period_to?, pay_date?, *_share?, deduct_loans? }
     *   custom dates  : { frequency, period_from, period_to, pay_date, cutoff_no?, deduct_contributions?, deduct_loans? }
     */
    public function createPayroll(Request $request)
    {
        $d = $request->validate([
            'cutoff_id' => ['nullable', 'integer', 'exists:m_payroll_cutoff,id'], 'month' => ['nullable', 'required_with:cutoff_id', 'date_format:Y-m'],
            'frequency' => ['nullable', 'required_without:cutoff_id', 'in:semi_monthly,monthly,weekly'],
            'period_from' => ['nullable', 'required_without:cutoff_id', 'date'], 'period_to' => ['nullable', 'required_without:cutoff_id', 'date'],
            'pay_date' => ['nullable', 'required_without:cutoff_id', 'date'],
            'cutoff_no' => ['nullable', 'integer', 'between:1,5'], 'deduct_contributions' => ['nullable', 'boolean'], 'deduct_loans' => ['nullable', 'boolean'],
            'sss_share' => ['nullable', 'numeric', 'in:0,0.5,1'], 'philhealth_share' => ['nullable', 'numeric', 'in:0,0.5,1'], 'pagibig_share' => ['nullable', 'numeric', 'in:0,0.5,1'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $cutoff = !empty($d['cutoff_id']) ? PayrollCutoff::findOrFail($d['cutoff_id']) : null;
        if ($cutoff) {
            $p = $cutoff->period($d['month']);
            $d['frequency'] = $cutoff->frequency;
            $d['period_from'] = $d['period_from'] ?? $p['period_from'];
            $d['period_to'] = $d['period_to'] ?? $p['period_to'];
            $d['pay_date'] = $d['pay_date'] ?? $p['pay_date'];
            foreach (['sss_share', 'philhealth_share', 'pagibig_share'] as $k) $d[$k] = $d[$k] ?? $cutoff->{$k};
            $d['deduct_loans'] = $d['deduct_loans'] ?? $cutoff->deduct_loans;
        } else {
            $share = ($d['deduct_contributions'] ?? true) ? 1 : 0;
            foreach (['sss_share', 'philhealth_share', 'pagibig_share'] as $k) $d[$k] = $d[$k] ?? $share;
        }
        if ($d['period_to'] < $d['period_from']) abort(422, 'Period to must be on or after period from.');

        $to = CarbonImmutable::parse($d['period_to']);
        $cut = $cutoff ? ((int) $cutoff->sort_order ?: 1) : (int) ($d['cutoff_no'] ?? ($to->day <= 15 ? 1 : 2));
        // PR-2026-10-A (cut-off code) · PR-2026-10-M (monthly) · PR-2026-10-W1 (weekly)
        $base = 'PR-' . $to->format('Y-m') . '-' . ($cutoff ? $cutoff->code : match ($d['frequency']) {
            'monthly' => 'M',
            'weekly'  => 'W' . $cut,
            default   => chr(64 + $cut),
        });
        // only one live payroll per frequency for the same days
        $active = PayrollRun::query()->where('frequency', $d['frequency'])->where('status', '!=', 'cancelled')
            ->whereDate('period_from', '<=', $d['period_to'])->whereDate('period_to', '>=', $d['period_from'])->first();
        if ($active) abort(422, "Payroll {$active->code} already covers these dates ({$active->status}). Open it, or cancel it first.");
        if (!empty($d['code'])) {
            // the code typed by the user
            $code = strtoupper($d['code']);
            if (PayrollRun::query()->where('code', $code)->exists()) abort(422, "Payroll code {$code} is already used.");
        } else {
            // a cancelled run keeps its code, so the new one gets the next free code: PR-2026-10-A-2, -3, …
            $code = $base;
            for ($n = 2; PayrollRun::query()->where('code', $code)->exists(); $n++) $code = "{$base}-{$n}";
        }

        $run = PayrollRun::create([
            'code' => $code, 'frequency' => $d['frequency'], 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'pay_date' => $d['pay_date'],
            'cutoff_no' => min($cut, 255), 'cutoff_id' => $cutoff?->id,
            'sss_share' => $d['sss_share'], 'philhealth_share' => $d['philhealth_share'], 'pagibig_share' => $d['pagibig_share'],
            'deduct_contributions' => ($d['sss_share'] + $d['philhealth_share'] + $d['pagibig_share']) > 0,
            'deduct_loans' => $d['deduct_loans'] ?? true, 'remarks' => $d['remarks'] ?? null,
            'status' => 'draft', 'prepared_by' => $this->me($request),
        ]);
        return response()->json(['message' => "Payroll {$code} created.", 'run' => $run]);
    }

    /* GET /hr/payroll/{id}   the run + every employee line */
    public function payroll(int $id)
    {
        $run = PayrollRun::findOrFail($id);
        return response()->json([
            'run' => $run, 'items' => PayrollItem::query()->with('employee:id,last_name,first_name,middle_name')->where('payroll_run_id', $id)->get(),
            'exclusions' => PayrollExclusion::query()->where('payroll_run_id', $id)->get()->keyBy('employee_id'),
        ]);
    }

    /* GET /hr/payroll/items/{id}   one payslip */
    public function payslip(int $id)
    {
        $item = PayrollItem::with('run', 'employee:id,last_name,first_name,middle_name,sss_no,phic_no,hdmf_no,tin', 'lines')->findOrFail($id);
        // not approved yet: recompute this employee first, so the payslip always matches the attendance, OT, leave and loans of today
        if (in_array($item->run->status, ['draft', 'computed'], true)) {
            $run = $item->run;
            $empId = $item->employee_id;
            $this->attempt(fn () => $this->payrollCalc->compute($run, [$empId]));
            $item = PayrollItem::with('run', 'employee:id,last_name,first_name,middle_name,sss_no,phic_no,hdmf_no,tin', 'lines')
                ->where('payroll_run_id', $run->id)->where('employee_id', $empId)->firstOrFail();
        }
        // loans due this cut-off (one checkbox each on the payslip), including the ones skipped
        $dues = [];
        if ($item->run->deduct_loans) {
            foreach ($this->lending->payrollDues($item->employee_id, CarbonImmutable::parse($item->run->period_to)->toDateString(), $item->run->pay_date) as $due) {
                $dues[] = ['loan_id' => $due['loan']->id, 'reference_no' => $due['loan']->reference_no, 'amount' => $due['amount']];
            }
            // already posted (approved): show the deducted loans from the lines
            if (!$dues) {
                foreach ($item->lines->where('reference_type', 'lending_loan') as $l) {
                    $dues[] = ['loan_id' => $l->reference_id, 'reference_no' => trim(str_replace('Loan', '', $l->description)), 'amount' => (float) $l->amount];
                }
            }
        }
        // employee share of each benefit in this payroll (whole month × the cut-off share), shown on the switches even when skipped
        $c = $this->payrollCalc->contributions((float) $item->monthly_rate, CarbonImmutable::parse($item->run->period_to)->toDateString(), (float) $item->gross_pay);
        $amounts = [];
        foreach (['sss_share' => 'SSS', 'philhealth_share' => 'PHILHEALTH', 'pagibig_share' => 'PAGIBIG'] as $key => $type) {
            $amounts[$key] = round((float) ($c[$type]['ee'] ?? 0) * $item->run->shareOf($type), 2);
        }
        return response()->json($item->toArray() + ['loan_dues' => $dues, 'benefit_amounts' => $amounts]);
    }

    /* POST /hr/payroll/{id}/compute   { employee_ids? } */
    public function computePayroll(Request $request, int $id)
    {
        $d = $request->validate(['employee_ids' => ['nullable', 'array'], 'employee_ids.*' => ['integer']]);
        $run = $this->attempt(fn () => $this->payrollCalc->compute(PayrollRun::findOrFail($id), $d['employee_ids'] ?? null));
        return response()->json(['message' => 'Payroll computed.', 'run' => $run]);
    }

    /* POST /hr/payroll/{id}/approve   posts loan deductions, locks attendance */
    public function approvePayroll(Request $request, int $id)
    {
        [$by, $via, $sup] = $this->supervisorApproval($request);
        $run = $this->attempt(fn () => $this->payrollCalc->approve(PayrollRun::findOrFail($id), $by));
        $run->update(['approved_via' => $via, 'approval_requested_by' => $this->me($request),
            'remarks' => trim(($run->remarks ? $run->remarks . ' · ' : '') . "Approved by {$sup->username}")]);
        return response()->json(['message' => "Payroll approved by {$sup->username}.", 'run' => $run->fresh()]);
    }

    /* POST /hr/payroll/{id}/release */
    public function releasePayroll(Request $request, int $id)
    {
        $run = PayrollRun::findOrFail($id);
        if ($run->status !== 'approved') abort(422, 'Approve the payroll first.');
        $run->update(['status' => 'released', 'released_at' => $this->now()]);
        return response()->json(['message' => 'Payroll released.']);
    }

    /* POST /hr/payroll/{id}/undo-release   released → approved */
    public function undoReleasePayroll(Request $request, int $id)
    {
        $this->supervisorApproval($request);
        $run = PayrollRun::findOrFail($id);
        if ($run->status !== 'released') abort(422, 'Only a released payroll can be undone.');
        $run->update(['status' => 'approved', 'released_at' => null]);
        return response()->json(['message' => "Release of {$run->code} undone. It is approved again."]);
    }

    /* POST /hr/payroll/{id}/undo-approve   approved → computed (loan payments removed, attendance unlocked) */
    public function undoApprovePayroll(Request $request, int $id)
    {
        $this->supervisorApproval($request);
        $run = $this->attempt(fn () => $this->payrollCalc->undoApprove(PayrollRun::findOrFail($id)));
        return response()->json(['message' => "Approval of {$run->code} undone. You can recompute it now.", 'run' => $run]);
    }

    /* DELETE /hr/payroll/{id}   only draft / computed */
    public function cancelPayroll(Request $request, int $id)
    {
        [, , $sup] = $this->supervisorApproval($request); // supervisor override, same as approve
        $run = PayrollRun::findOrFail($id);
        if (!in_array($run->status, ['draft', 'computed'], true)) abort(422, 'An approved payroll cannot be cancelled.');
        DB::transaction(function () use ($run, $sup) {
            $run->items()->delete();
            $run->update(['status' => 'cancelled', 'remarks' => trim(($run->remarks ? $run->remarks . ' · ' : '') . "Cancelled by {$sup->username}")]);
        });
        return response()->json(['message' => "Payroll {$run->code} cancelled by {$sup->username}."]);
    }

    /* =====================================================================
       LENDING
       ===================================================================== */

    /* GET /lending/summary   the fund at a glance */
    public function lendingSummary()
    {
        $invested = (float) LendingInvestment::query()->where('status', 'active')->sum('principal_balance');
        $interestOwed = (float) LendingInvestment::query()->where('status', 'active')->sum('interest_balance');
        $outstanding = (float) LendingLoan::query()->where('status', 'released')->sum('balance');
        $released = (float) LendingLoan::query()->whereIn('status', ['released', 'paid'])->sum('principal');
        $collected = (float) LendingLoan::query()->whereIn('status', ['released', 'paid'])->sum('total_paid');
        $cashedOut = (float) LendingInvestment::query()->sum('total_cashed_out');
        $deposits = (float) LendingInvestment::query()->sum('total_invested');
        return response()->json([
            'active_investments' => round($invested, 2),
            'interest_owed_to_investors' => round($interestOwed, 2),
            'loans_outstanding' => round($outstanding, 2),
            'loan_interest_earned' => round((float) LendingLoan::query()->whereIn('status', ['released', 'paid'])->sum('total_interest'), 2),
            'cash_on_hand' => round($deposits - $cashedOut - $released + $collected, 2),
        ]);
    }

    /* POST /lending/settings   PUT /lending/settings/{id} */
    public function saveLendingSetting(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'applies_to' => ['required', 'in:loan,investment'],
            'interest_type' => ['required', 'in:per_day,per_month,fixed'], 'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'fixed_interest' => ['nullable', 'numeric', 'min:0'], 'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'], 'max_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'penalty_rate_per_day' => ['nullable', 'numeric', 'min:0', 'max:1'], 'lock_in_days' => ['nullable', 'integer', 'min:0'],
            'payroll_deductible' => ['boolean'], 'is_default' => ['boolean'], 'is_active' => ['boolean'],
        ]);
        $row = DB::transaction(function () use ($d, $id, $request) {
            if (!empty($d['is_default'])) LendingSetting::query()->where('applies_to', $d['applies_to'])->update(['is_default' => false]);
            return $id ? tap(LendingSetting::findOrFail($id))->update($d) : LendingSetting::create($d + ['created_by' => $this->me($request)]);
        });
        return response()->json(['message' => 'Lending setting saved.', 'setting' => $row]);
    }

    /* GET /lending/members   POST /lending/members   { employee_id } or { name, contact_no, … } */
    public function members()
    {
        $members = LendingMember::query()->withSum(['investments as invested' => fn ($q) => $q->where('status', 'active')], 'principal_balance')
            ->withSum(['loans as loan_balance' => fn ($q) => $q->where('status', 'released')], 'balance')->get();
        // active employees who are not members yet can be picked too (they become members on their first investment / loan)
        $taken = $members->pluck('employee_id')->filter()->values()->all();
        $employees = Employee::query()->where('is_active', 1)->whereNotIn('id', $taken ?: [0])
            ->orderBy('last_name')->orderBy('first_name')->get(['id', 'last_name', 'first_name', 'middle_name', 'suffix']);
        $rows = $members->map(fn (LendingMember $m) => $m->toArray() + ['key' => "m{$m->id}", 'is_member' => true])
            ->concat($employees->map(fn (Employee $e) => [
                'key' => "e{$e->id}", 'id' => null, 'is_member' => false, 'member_type' => 'employee', 'employee_id' => $e->id,
                'name' => $e->full_name, 'is_active' => true, 'invested' => null, 'loan_balance' => null,
            ]))
            ->sortBy(fn ($r) => mb_strtolower((string) $r['name']))->values();
        return response()->json($rows);
    }

    /** the investor / borrower: { member_id } or { employee_id } (an employee becomes a member on first use) */
    private function memberFrom(Request $request): LendingMember
    {
        $d = $request->validate([
            'member_id' => ['nullable', 'required_without:employee_id', 'integer', 'exists:m_lending_member,id'],
            'employee_id' => ['nullable', 'integer', 'exists:m_employee,id'],
        ]);
        if (!empty($d['member_id'])) return LendingMember::findOrFail($d['member_id']);
        $e = Employee::findOrFail($d['employee_id']);
        return LendingMember::firstOrCreate(['employee_id' => $e->id], [
            'member_type' => 'employee', 'name' => $e->full_name, 'is_active' => true, 'created_by' => $this->me($request),
        ]);
    }

    public function saveMember(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'member_type' => ['required', 'in:employee,outside'],
            'employee_id' => ['nullable', 'required_if:member_type,employee', 'integer', 'exists:m_employee,id', Rule::unique('m_lending_member', 'employee_id')->ignore($id)],
            'name' => ['nullable', 'required_if:member_type,outside', 'string', 'max:150'],
            'contact_no' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'address' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:100'], 'account_no' => ['nullable', 'string', 'max:50'], 'is_active' => ['boolean'],
        ]);
        if ($d['member_type'] === 'employee') {
            $d['name'] = Employee::findOrFail($d['employee_id'])->full_name;
        } else {
            $d['employee_id'] = null;
        }
        $row = $id ? tap(LendingMember::findOrFail($id))->update($d) : LendingMember::create($d + ['created_by' => $this->me($request)]);
        return response()->json(['message' => 'Member saved.', 'member' => $row]);
    }

    /* GET /lending/investments?status= */
    public function investments(Request $request)
    {
        $today = LendingCalculator::today();
        $investments = LendingInvestment::query()->with('member:id,name,member_type')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('date_invested')
            ->get();

        $rows = $investments->map(fn (LendingInvestment $inv) => $inv->toArray() + [
            'today' => $inv->status === 'active' ? $this->lending->preview($inv, $today) : null,
        ]);

        return response()->json($rows);
    }

    /* GET /lending/investments/{id}?as_of=   statement + "if cashed out on" value */
    public function investment(Request $request, int $id)
    {
        $inv = LendingInvestment::with('member', 'transactions')->findOrFail($id);
        return response()->json(['investment' => $inv, 'preview' => $this->lending->preview($inv, $request->query('as_of'))]);
    }

    /* POST /lending/investments   { member_id, amount, date_invested, lending_setting_id?, interest_type?, interest_rate?, fixed_interest?, lock_in_days? } */
    public function invest(Request $request)
    {
        $d = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'date_invested' => ['nullable', 'date'], 'lending_setting_id' => ['nullable', 'integer', 'exists:m_lending_setting,id'],
            'interest_type' => ['nullable', 'in:per_day,per_month,fixed'], 'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'fixed_interest' => ['nullable', 'numeric', 'min:0'], 'lock_in_days' => ['nullable', 'integer', 'min:0'],
        ]);
        $setting = !empty($d['lending_setting_id']) ? LendingSetting::find($d['lending_setting_id']) : null;
        $member = $this->memberFrom($request);
        $inv = $this->attempt(fn () => $this->lending->invest($member, (float) $d['amount'],
            $d['date_invested'] ?? LendingCalculator::today(), $setting,
            array_filter(array_intersect_key($d, array_flip(['interest_type', 'interest_rate', 'fixed_interest', 'lock_in_days'])), fn ($v) => $v !== null),
            $this->me($request)));
        return response()->json(['message' => "Investment {$inv->reference_no} saved.", 'investment' => $inv]);
    }

    /* POST /lending/investments/{id}/top-up   { amount, date } */
    public function topUp(Request $request, int $id)
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'date' => ['nullable', 'date']]);
        $inv = $this->attempt(fn () => $this->lending->topUp(LendingInvestment::findOrFail($id), (float) $d['amount'], $d['date'] ?? LendingCalculator::today(), $this->me($request)));
        return response()->json(['message' => 'Top-up saved.', 'investment' => $inv]);
    }

    /* POST /lending/investments/{id}/cashout   { amount (empty = all), date } */
    public function cashout(Request $request, int $id)
    {
        $d = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01'], 'date' => ['nullable', 'date']]);
        $res = $this->attempt(fn () => $this->lending->cashout(LendingInvestment::findOrFail($id), isset($d['amount']) ? (float) $d['amount'] : null,
            $d['date'] ?? LendingCalculator::today(), $this->me($request)));
        return response()->json(['message' => 'Cash-out of ' . number_format($res['paid'], 2) . ' saved.'] + $res);
    }

    /* GET /lending/loans?status=&member_id= */
    public function loans(Request $request)
    {
        return response()->json(LendingLoan::query()->with('member:id,name,member_type')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('member_id', $v))
            ->orderByDesc('id')->limit(1000)->get());
    }

    /* GET /lending/loans/{id} */
    public function loan(int $id)
    {
        return response()->json(LendingLoan::with('member', 'schedules', 'payments')->findOrFail($id));
    }

    /* POST /lending/loans/preview   the computation before applying */
    public function previewLoan(Request $request)
    {
        $d = $this->loanInput($request);
        $setting = !empty($d['lending_setting_id']) ? LendingSetting::find($d['lending_setting_id']) : null;
        $terms = $this->attempt(fn () => $this->lending->loanTerms((float) $d['principal'], $d['interest_type'] ?? $setting?->interest_type ?? 'per_month',
            (float) ($d['interest_rate'] ?? $setting?->interest_rate ?? 0), (float) ($d['fixed_interest'] ?? $setting?->fixed_interest ?? 0),
            (int) $d['term_months'], $d['date_released'] ?? LendingCalculator::today(), $d['due_frequency'] ?? 'monthly'));
        return response()->json($terms);
    }

    /* POST /lending/loans   apply (pending) */
    public function applyLoan(Request $request)
    {
        $d = $this->loanInput($request);
        $setting = !empty($d['lending_setting_id']) ? LendingSetting::find($d['lending_setting_id']) : null;
        if ($setting && $d['principal'] < (float) $setting->min_amount) abort(422, 'Below the minimum amount of ' . number_format($setting->min_amount, 2) . '.');
        if ($setting && $setting->max_amount && $d['principal'] > (float) $setting->max_amount) abort(422, 'Above the maximum amount of ' . number_format($setting->max_amount, 2) . '.');
        if ($setting && $setting->max_term_months && $d['term_months'] > $setting->max_term_months) abort(422, "Longest term is {$setting->max_term_months} month(s).");
        $member = $this->memberFrom($request);
        $loan = $this->attempt(fn () => $this->lending->applyLoan($member, $d, $this->me($request)));
        return response()->json(['message' => "Loan {$loan->reference_no} filed.", 'loan' => $loan]);
    }

    private function loanInput(Request $request): array
    {
        return $request->validate([
            'principal' => ['required', 'numeric', 'min:1'], 'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'lending_setting_id' => ['nullable', 'integer', 'exists:m_lending_setting,id'],
            'interest_type' => ['nullable', 'in:per_day,per_month,fixed'], 'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'fixed_interest' => ['nullable', 'numeric', 'min:0'], 'penalty_rate_per_day' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'due_frequency' => ['nullable', 'in:monthly,semi_monthly'], 'deduction_mode' => ['nullable', 'in:payroll,manual'],
            'date_released' => ['nullable', 'date'], 'purpose' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /* PUT /lending/loans/{id}/{action}   action = approve | reject | cancel */
    public function decideLoan(Request $request, int $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel'], true), 404);
        $d = $request->validate(['remarks' => ['nullable', 'string', 'max:255'], 'deduction_mode' => ['nullable', 'in:payroll,manual']]);
        $loan = LendingLoan::findOrFail($id);
        $allowed = ['approve' => ['pending'], 'reject' => ['pending'], 'cancel' => ['pending', 'approved']][$action];
        if (!in_array($loan->status, $allowed, true)) abort(422, "This loan is already {$loan->status}.");
        $loan->update([
            'status' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action],
            'deduction_mode' => $loan->employee_id ? ($d['deduction_mode'] ?? $loan->deduction_mode) : 'manual',
            'approved_by' => $this->me($request), 'approved_at' => $this->now(), 'approver_remarks' => $d['remarks'] ?? null,
        ]);
        return response()->json(['message' => 'Loan ' . $loan->status . '.']);
    }

    /* POST /lending/loans/{id}/release   { date }  builds the schedule */
    public function releaseLoan(Request $request, int $id)
    {
        $d = $request->validate(['date' => ['nullable', 'date']]);
        $loan = $this->attempt(fn () => $this->lending->release(LendingLoan::findOrFail($id), $d['date'] ?? LendingCalculator::today(), $this->me($request)));
        return response()->json(['message' => 'Loan released.', 'loan' => $loan]);
    }

    /* POST /lending/loans/{id}/payments   manual payment { amount, date, or_number, remarks } */
    public function payLoan(Request $request, int $id)
    {
        $d = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'], 'date' => ['nullable', 'date'],
            'or_number' => ['nullable', 'string', 'max:50'], 'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $this->attempt(fn () => $this->lending->pay(LendingLoan::findOrFail($id), (float) $d['amount'], $d['date'] ?? LendingCalculator::today(),
            'manual', null, $this->me($request), $d['or_number'] ?? null, $d['remarks'] ?? null));
        return response()->json(['message' => 'Payment saved.', 'loan' => LendingLoan::with('schedules', 'payments')->find($id)]);
    }
}