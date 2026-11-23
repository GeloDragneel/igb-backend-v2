<?php

namespace App\Console\Commands;

use App\Services\LendingFlatCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan lending:import-excel {file=storage/app/lending_import.json} {--dry-run}
 *
 * Loads the IGBLending Excel (made into lending_import.json) into the lending tables:
 *   Borrowers           → m_lending_member      (code B0001…; linked to m_employee when the name matches)
 *   Loan                → t_lending_loan        (LN-0001…; interest_type = flat, rate per due)
 *   RepaymentSchedule   → t_lending_loan_schedule
 *   Transactions + AR   → t_lending_loan_payment (AR# in or_number, principal / interest split)
 *   Capital&Withdrawal  → t_lending_investment + t_lending_investment_txn (one investment per investor)
 * Already there = skipped (member by code / employee / name, loan by LN-#, investment by CAP-<code>), so it can run again safely.
 */
class ImportLendingExcel extends Command
{
    protected $signature = 'lending:import-excel {file=storage/app/lending_import.json} {--dry-run : show what would be added, change nothing}';
    protected $description = 'Import the IGBLending Excel data (borrowers, loans, schedules, payments, capital) into the lending tables';

    private array $stats = ['members_new' => 0, 'members_linked' => 0, 'members_found' => 0, 'loans' => 0, 'loans_skipped' => 0,
        'schedules' => 0, 'payments' => 0, 'investments' => 0, 'investments_skipped' => 0, 'investment_txns' => 0];

    public function handle(): int
    {
        $path = $this->argument('file');
        $path = is_file($path) ? $path : base_path($path);
        if (!is_file($path)) { $this->error("File not found: {$path}"); return self::FAILURE; }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data) || !isset($data['members'], $data['loans'])) { $this->error('Not a lending import file.'); return self::FAILURE; }

        $dry = (bool) $this->option('dry-run');
        $now = now();

        DB::beginTransaction();
        try {
            /* ---------- members ---------- */
            $employees = $this->employees();
            $memberId = [];                                         // code → m_lending_member.id
            foreach ($data['members'] as $m) {
                $memberId[$m['code']] = $this->member($m, $employees, $now);
            }

            /* ---------- loans + schedules + payments ---------- */
            $penalty = (float) ($data['penalty_rate_per_day'] ?? LendingFlatCalculator::EXCEL_PENALTY_PER_DAY);
            $setting = DB::table('m_lending_setting')->where('name', 'IGB Loan 2.5% Bi-Monthly (Flat)')->value('id');
            $calc = app(LendingFlatCalculator::class);
            foreach ($data['loans'] as $l) {
                if (DB::table('t_lending_loan')->where('reference_no', $l['reference_no'])->exists()) { $this->stats['loans_skipped']++; continue; }
                $mid = $memberId[$l['member_code']] ?? null;
                if (!$mid) { $this->warn("{$l['reference_no']}: borrower {$l['member_code']} not found – skipped"); continue; }
                $emp = DB::table('m_lending_member')->where('id', $mid)->value('employee_id');

                $loanId = DB::table('t_lending_loan')->insertGetId([
                    'reference_no' => $l['reference_no'], 'member_id' => $mid, 'employee_id' => $emp,
                    'lending_setting_id' => abs($l['interest_rate'] - 0.025) < 0.00001 && $l['due_frequency'] === 'semi_monthly' ? $setting : null,
                    'interest_type' => 'flat', 'interest_rate' => $l['interest_rate'], 'fixed_interest' => 0, 'penalty_rate_per_day' => $penalty,
                    'principal' => $l['principal'], 'term_months' => $calc->flatMonths((int) $l['dues'], $l['due_frequency']), 'dues' => $l['dues'],
                    'due_frequency' => $l['due_frequency'], 'date_released' => $l['date_released'],
                    'first_due_date' => $l['first_due_date'], 'maturity_date' => $l['maturity_date'],
                    'total_interest' => $l['total_interest'], 'total_payable' => $l['total_payable'], 'amount_per_due' => $l['amount_per_due'],
                    'total_paid' => $l['total_paid'], 'balance' => $l['balance'],
                    'deduction_mode' => $emp && $l['deduction_mode'] === 'payroll' ? 'payroll' : 'manual',
                    'purpose' => $l['purpose'], 'co_maker' => $l['co_maker'], 'collection_method' => $l['collection_method'],
                    'status' => $l['status'], 'approved_at' => $l['date_released'], 'approver_remarks' => 'Imported from the IGBLending Excel',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->stats['loans']++;

                $schedId = [];
                foreach ($l['schedules'] as $s) {
                    $schedId[$s['due_no']] = DB::table('t_lending_loan_schedule')->insertGetId([
                        'loan_id' => $loanId, 'due_no' => $s['due_no'], 'due_date' => $s['due_date'],
                        'principal_due' => $s['principal_due'] ?? 0, 'interest_due' => $s['interest_due'] ?? 0,
                        'amount_due' => $s['amount_due'] ?? round(($s['principal_due'] ?? 0) + ($s['interest_due'] ?? 0), 2),
                        'penalty' => $s['penalty'] ?? 0, 'amount_paid' => $s['amount_paid'] ?? 0, 'status' => $s['status'], 'paid_date' => $s['paid_date'],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->stats['schedules']++;
                }

                foreach ($l['payments'] as $p) {
                    DB::table('t_lending_loan_payment')->insert([
                        'loan_id' => $loanId, 'schedule_id' => $p['payment_no'] ? ($schedId[$p['payment_no']] ?? null) : null,
                        'payment_date' => $p['payment_date'] ?: $l['date_released'], 'amount' => $p['amount'],
                        'principal_paid' => $p['principal_paid'], 'interest_paid' => $p['interest_paid'], 'penalty_paid' => 0,
                        'source' => 'manual', 'payment_type' => $p['payment_type'] ?: null, 'or_number' => $p['or_number'],
                        'remarks' => trim('Excel · ' . ($p['payment_no'] !== null ? "Payment #{$p['payment_no']}" : '') . ($p['remarks'] ? " · {$p['remarks']}" : '')),
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->stats['payments']++;
                }
            }

            /* ---------- capital → investments ---------- */
            foreach ($data['investments'] ?? [] as $inv) {
                $mid = $memberId[$inv['member_code']] ?? null;
                if (!$mid || !$inv['entries']) continue;
                $ref = 'CAP-' . $inv['member_code'];
                if (DB::table('t_lending_investment')->where('reference_no', $ref)->exists()) { $this->stats['investments_skipped']++; continue; }
                $total = round(array_sum(array_column($inv['entries'], 'amount')), 2);
                $first = $inv['entries'][0]['date'];
                $last = end($inv['entries'])['date'];
                $iid = DB::table('t_lending_investment')->insertGetId([
                    'reference_no' => $ref, 'member_id' => $mid, 'lending_setting_id' => null,
                    'interest_type' => 'fixed', 'interest_rate' => 0, 'fixed_interest' => 0, 'lock_in_days' => 0,
                    'date_invested' => $first, 'total_invested' => $total, 'principal_balance' => $total,
                    'interest_balance' => 0, 'total_interest_earned' => 0, 'total_cashed_out' => 0,
                    'last_accrual_date' => $last, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->stats['investments']++;
                $run = 0;
                foreach ($inv['entries'] as $i => $e) {
                    $run = round($run + $e['amount'], 2);
                    DB::table('t_lending_investment_txn')->insert([
                        'investment_id' => $iid, 'txn_type' => $i === 0 ? 'deposit' : 'top_up', 'txn_date' => $e['date'], 'amount' => $e['amount'],
                        'days' => null, 'principal_after' => $run, 'interest_after' => 0,
                        'remarks' => trim(($e['ref'] ?: '') . ' · Excel ' . $e['type'], ' ·'), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->stats['investment_txns']++;
                }
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->table(['What', 'Count'], collect($this->stats)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all());
        foreach ($data['warnings'] ?? [] as $w) $this->line("<comment>note:</comment> {$w}");
        $this->info($dry ? 'Dry run — nothing was saved.' : 'Import done.');
        return self::SUCCESS;
    }

    /** m_employee → [[id, last, first]] (normalized) */
    private function employees(): array
    {
        return DB::table('m_employee')->orderByDesc('is_active')->orderByDesc('id')->get(['id', 'last_name', 'first_name', 'is_active'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'active' => (int) $e->is_active, 'last' => $this->norm($e->last_name), 'first' => explode(' ', $this->norm($e->first_name))[0] ?? ''])
            ->filter(fn ($e) => $e['last'] !== '' && $e['first'] !== '')->values()->all();
    }

    /** the member for an Excel borrower: by code → by employee → by name → new */
    private function member(array $m, array $employees, $now): int
    {
        $byCode = DB::table('m_lending_member')->where('code', $m['code'])->value('id');
        if ($byCode) { $this->stats['members_found']++; return (int) $byCode; }

        // employee: the employee's last name and first name are both in the Excel name ("Nulo, Jesson R." / "Joenas B. Nisnisan")
        $name = ' ' . $this->norm($m['name']) . ' ';
        $hits = array_values(array_filter($employees, fn ($e) => str_contains($name, " {$e['last']} ") && str_contains($name, " {$e['first']} ")));
        // the same person twice in m_employee → the active (newest) one
        $active = array_values(array_filter($hits, fn ($e) => $e['active'] === 1));
        $empId = count($hits) === 1 ? $hits[0]['id'] : (count($active) >= 1 ? $active[0]['id'] : ($hits[0]['id'] ?? null));

        $existing = $empId ? DB::table('m_lending_member')->where('employee_id', $empId)->value('id') : null;
        $existing = $existing ?: DB::table('m_lending_member')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($m['name']))])->value('id');
        if ($existing) {
            DB::table('m_lending_member')->where('id', $existing)->update(array_filter([
                'code' => $m['code'], 'employee_id' => $empId, 'member_type' => $empId ? 'employee' : null, 'updated_at' => $now,
            ], fn ($v) => $v !== null));
            $this->stats['members_found']++;
            return (int) $existing;
        }

        $this->stats['members_new']++;
        if ($empId) $this->stats['members_linked']++;
        return (int) DB::table('m_lending_member')->insertGetId([
            'code' => $m['code'], 'member_type' => $empId ? 'employee' : 'outside', 'employee_id' => $empId,
            'name' => mb_substr($m['name'], 0, 150), 'contact_no' => $m['contact_no'], 'email' => $m['email'], 'address' => $m['address'],
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function norm(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = strtr($s, ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}