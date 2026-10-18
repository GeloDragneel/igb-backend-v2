<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\DeclaredMonthSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Task → Loan Management
 *   payable    (we borrowed)  release: Dr Cash / Cr Loans Payable
 *                             payment: Dr Loans Payable + Dr Interest Expense / Cr Cash
 *   receivable (we lent)      release: Dr Loans Receivable / Cr Cash
 *                             collection: Dr Cash / Cr Loans Receivable + Cr Interest Income
 * Journal entries go to t_general_journal (jv_no = loan_no / payment_no).
 */
class LoanController extends SalesController
{
    const L = 't_loans';
    const P = 't_loan_payments';
    const GJ = 't_general_journal';

    /* ---------- helpers ---------- */
    private function isLocked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    private function nextNo(string $table, string $col, string $prefix): string
    {
        $p = $prefix . '-' . date('ymd') . '-';
        $n = DB::table($table)->where($col, 'like', $p . '%')->count() + 1;
        do { $no = $p . str_pad((string) $n++, 3, '0', STR_PAD_LEFT); } while (DB::table($table)->where($col, $no)->exists());
        return $no;
    }

    private function postJournal(Request $request, int $by, string $jvNo, string $date, string $remarks, array $lines): void
    {
        foreach ($lines as $l) {
            $dr = round((float) ($l['debit'] ?? 0), 2);
            $cr = round((float) ($l['credit'] ?? 0), 2);
            if ($dr == 0 && $cr == 0) continue;
            DB::table(self::GJ)->insert([
                'jv_no' => $jvNo, 'account_code' => $l['account_code'], 'subsidiary' => null,
                'debit' => $dr, 'credit' => $cr, 'remarks' => $remarks,
                'transaction_date' => $date, 'declared_month' => substr($date, 0, 7),
                'added_by' => $by, 'employee_id' => $request->user()->employee_id ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Amortization schedule.
     *   diminishing: equal monthly payment  P·r / (1 − (1+r)^−n), interest on remaining balance
     *   straight:    equal principal P/n, interest on the original principal (add-on)
     * The last row absorbs rounding so the balance ends at exactly 0.
     */
    public static function schedule(float $principal, float $annualRate, int $months, string $method, string $firstDue): array
    {
        $r = $annualRate / 100 / 12;
        $rows = [];
        $bal = round($principal, 2);
        $due = Carbon::parse($firstDue);
        $pay = $method === 'diminishing' && $r > 0
            ? round($principal * $r / (1 - pow(1 + $r, -$months)), 2)
            : round($principal / max(1, $months), 2);

        for ($k = 1; $k <= $months; $k++) {
            if ($method === 'diminishing') {
                $int = round($bal * $r, 2);
                $prin = $k === $months ? $bal : round(($r > 0 ? $pay - $int : $pay), 2);
            } else {
                $int = round($principal * $r, 2);
                $prin = $k === $months ? $bal : round($principal / $months, 2);
            }
            $bal = round($bal - $prin, 2);
            $rows[] = [
                'no' => $k,
                'due_date' => $due->copy()->addMonthsNoOverflow($k - 1)->toDateString(),
                'principal' => $prin, 'interest' => $int, 'total' => round($prin + $int, 2), 'balance' => max(0, $bal),
            ];
        }
        return $rows;
    }

    private function summary(object $loan, array $paid): array
    {
        $paidP = (float) ($paid[$loan->id]['principal'] ?? 0);
        $paidI = (float) ($paid[$loan->id]['interest'] ?? 0);
        $sched = self::schedule((float) $loan->principal, (float) $loan->annual_rate, (int) $loan->term_months, $loan->method, (string) $loan->first_due_date);

        // next unpaid installment = first row whose cumulative principal is not covered yet
        $cum = 0; $next = null;
        foreach ($sched as $row) {
            $cum += $row['principal'];
            if ($cum - $paidP > 0.009) { $next = $row; break; }
        }
        $outstanding = round((float) $loan->principal - $paidP, 2);
        return [
            'id' => $loan->id, 'loan_no' => $loan->loan_no, 'direction' => $loan->direction,
            'party_id' => $loan->party_id, 'party_name' => $loan->party_name, 'reference' => $loan->reference,
            'principal' => round((float) $loan->principal, 2), 'annual_rate' => (float) $loan->annual_rate,
            'term_months' => (int) $loan->term_months, 'method' => $loan->method,
            'release_date' => substr((string) $loan->release_date, 0, 10), 'first_due_date' => substr((string) $loan->first_due_date, 0, 10),
            'loan_account' => $loan->loan_account, 'interest_account' => $loan->interest_account, 'cash_account' => $loan->cash_account,
            'status' => $loan->status, 'release_jv_no' => $loan->release_jv_no, 'remarks' => $loan->remarks,
            'monthly' => $sched[0]['total'] ?? 0,
            'paid_principal' => round($paidP, 2), 'paid_interest' => round($paidI, 2),
            'outstanding' => max(0, $outstanding),
            'next_due' => $next ? ['no' => $next['no'], 'date' => $next['due_date'], 'total' => $next['total']] : null,
            'overdue' => $next && $loan->status === 'active' && $next['due_date'] < date('Y-m-d'),
        ];
    }

    private function paidByLoan(int $by, ?array $loanIds = null): array
    {
        return DB::table(self::P)->where('added_by', $by)->where('status', 'posted')
            ->when($loanIds, fn ($q) => $q->whereIn('loan_id', $loanIds))
            ->groupBy('loan_id')->selectRaw('loan_id, SUM(principal) AS principal, SUM(interest + penalty) AS interest')
            ->get()->mapWithKeys(fn ($r) => [$r->loan_id => ['principal' => (float) $r->principal, 'interest' => (float) $r->interest]])->all();
    }

    /* =====================================================================
       GET /api/loans?direction=payable|receivable&status=active|closed|void|all
       ===================================================================== */
    public function lnIndex(Request $request)
    {
        $d = $request->validate(['direction' => ['nullable', 'in:payable,receivable'], 'status' => ['nullable', 'in:active,closed,void,all']]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['loans' => [], 'accounts' => [], 'parties' => []]);

        $loans = DB::table(self::L)->where('added_by', $by)
            ->when($d['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when(($d['status'] ?? 'active') !== 'all', fn ($q) => $q->where('status', $d['status'] ?? 'active'))
            ->orderByDesc('release_date')->orderByDesc('id')->get();
        $paid = $this->paidByLoan($by, $loans->pluck('id')->all());

        return response()->json([
            'loans' => $loans->map(fn ($l) => $this->summary($l, $paid))->values(),
            'accounts' => ChartsOfAccount::where('added_by', $by)->where('root_name', '!=', '0')->orderBy('account_code')->get(['account_code', 'account_name']),
            'parties' => DB::table('m_vendor_customer')->where('entry_by', $by)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /* POST /api/loans/preview   schedule only (for the form) */
    public function lnPreview(Request $request)
    {
        $d = $request->validate([
            'principal' => ['required', 'numeric', 'gt:0'], 'annual_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'], 'method' => ['required', 'in:diminishing,straight'],
            'first_due_date' => ['required', 'date'],
        ]);
        $rows = self::schedule((float) $d['principal'], (float) $d['annual_rate'], (int) $d['term_months'], $d['method'], $d['first_due_date']);
        return response()->json(['rows' => $rows, 'total_interest' => round(array_sum(array_column($rows, 'interest')), 2)]);
    }

    /* GET /api/loans/{id}   loan + schedule (with paid status) + payments */
    public function lnShow(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $loan = DB::table(self::L)->where('added_by', $by)->where('id', $id)->first();
        if (!$loan) return response()->json(['message' => 'Loan not found.'], 404);

        $paid = $this->paidByLoan($by, [$id]);
        $sum = $this->summary($loan, $paid);
        $paidP = $sum['paid_principal'];
        $sched = self::schedule((float) $loan->principal, (float) $loan->annual_rate, (int) $loan->term_months, $loan->method, (string) $loan->first_due_date);
        $cum = 0;
        foreach ($sched as &$row) {
            $cum += $row['principal'];
            $row['status'] = $cum - $paidP <= 0.009 ? 'paid' : ($cum - $row['principal'] < $paidP ? 'partial' : ($row['due_date'] < date('Y-m-d') ? 'overdue' : 'open'));
        }
        unset($row);

        $payments = DB::table(self::P)->where('loan_id', $id)->orderByDesc('payment_date')->orderByDesc('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'payment_no' => $p->payment_no, 'installment_no' => $p->installment_no,
                'date' => substr((string) $p->payment_date, 0, 10), 'principal' => (float) $p->principal, 'interest' => (float) $p->interest,
                'penalty' => (float) $p->penalty, 'total' => (float) $p->total, 'cash_account' => $p->cash_account,
                'reference' => $p->reference, 'status' => $p->status, 'jv_no' => $p->jv_no,
            ]);

        return response()->json($sum + ['schedule' => $sched, 'payments' => $payments]);
    }

    private function rules(): array
    {
        return [
            'direction' => ['required', 'in:payable,receivable'],
            'party_id' => ['nullable', 'integer'],
            'party_name' => ['required', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'principal' => ['required', 'numeric', 'gt:0'],
            'annual_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'method' => ['required', 'in:diminishing,straight'],
            'release_date' => ['required', 'date'],
            'first_due_date' => ['required', 'date', 'after_or_equal:release_date'],
            'loan_account' => ['required', 'string', 'max:20'],
            'interest_account' => ['required', 'string', 'max:20'],
            'cash_account' => ['nullable', 'string', 'max:20'],
            'post_release' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    private const MSG = [
        'party_name.required' => 'Lender / borrower is required',
        'loan_account.required' => 'Pick the loan account',
        'interest_account.required' => 'Pick the interest account',
        'first_due_date.after_or_equal' => 'First due date must be on or after the release date',
    ];

    /* POST /api/loans   new loan; post_release = also post the release to the General Journal */
    public function lnStore(Request $request)
    {
        $d = $request->validate($this->rules(), self::MSG);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);
        $post = (bool) ($d['post_release'] ?? false);
        if ($post && empty($d['cash_account'])) return response()->json(['message' => 'Pick the cash / bank account to post the release.'], 422);
        if ($post && $this->isLocked($this->caidFor($request), [substr($d['release_date'], 0, 7)])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        return DB::transaction(function () use ($request, $by, $d, $post) {
            $no = $this->nextNo(self::L, 'loan_no', 'LN');
            $id = DB::table(self::L)->insertGetId([
                'loan_no' => $no, 'direction' => $d['direction'], 'party_id' => $d['party_id'] ?? null, 'party_name' => trim($d['party_name']),
                'reference' => $d['reference'] ?? null, 'principal' => round((float) $d['principal'], 2), 'annual_rate' => $d['annual_rate'],
                'term_months' => $d['term_months'], 'method' => $d['method'], 'release_date' => $d['release_date'], 'first_due_date' => $d['first_due_date'],
                'loan_account' => $d['loan_account'], 'interest_account' => $d['interest_account'], 'cash_account' => $d['cash_account'] ?? null,
                'status' => 'active', 'release_jv_no' => $post ? $no : null, 'remarks' => $d['remarks'] ?? null,
                'added_by' => $by, 'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($post) {
                $amt = round((float) $d['principal'], 2);
                $payable = $d['direction'] === 'payable';
                $this->postJournal($request, $by, $no, $d['release_date'], ($payable ? 'Loan proceeds – ' : 'Loan released – ') . trim($d['party_name']), [
                    ['account_code' => $d['cash_account'], 'debit' => $payable ? $amt : 0, 'credit' => $payable ? 0 : $amt],
                    ['account_code' => $d['loan_account'], 'debit' => $payable ? 0 : $amt, 'credit' => $payable ? $amt : 0],
                ]);
            }
            return response()->json(['id' => $id, 'loan_no' => $no, 'message' => $post ? "{$no} saved and release posted" : "{$no} saved"]);
        });
    }

    /* PUT /api/loans/{id}   edit details; amounts / terms are locked once a payment exists */
    public function lnUpdate(Request $request, int $id)
    {
        $d = $request->validate($this->rules(), self::MSG);
        $by = $this->addedByFor($request);
        $loan = DB::table(self::L)->where('added_by', $by)->where('id', $id)->first();
        if (!$loan) return response()->json(['message' => 'Loan not found.'], 404);
        if ($loan->status === 'void') return response()->json(['message' => 'This loan is void.'], 422);

        $hasPay = DB::table(self::P)->where('loan_id', $id)->where('status', 'posted')->exists();
        $termsChanged = abs((float) $d['principal'] - (float) $loan->principal) > 0.001 || abs((float) $d['annual_rate'] - (float) $loan->annual_rate) > 0.00001
            || (int) $d['term_months'] !== (int) $loan->term_months || $d['method'] !== $loan->method
            || $d['first_due_date'] !== substr((string) $loan->first_due_date, 0, 10) || $d['direction'] !== $loan->direction;
        if ($hasPay && $termsChanged) return response()->json(['message' => 'Payments are already recorded, so amount, rate, term, method and dates can\'t change. Void the payments first.'], 422);

        $releaseChanged = $loan->release_jv_no && (abs((float) $d['principal'] - (float) $loan->principal) > 0.001
            || $d['release_date'] !== substr((string) $loan->release_date, 0, 10) || $d['loan_account'] !== $loan->loan_account
            || ($d['cash_account'] ?? null) !== $loan->cash_account || $d['direction'] !== $loan->direction);
        if ($releaseChanged && $this->isLocked($this->caidFor($request), [substr((string) $loan->release_date, 0, 7), substr($d['release_date'], 0, 7)])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        DB::transaction(function () use ($request, $by, $d, $loan, $releaseChanged) {
            DB::table(self::L)->where('id', $loan->id)->update([
                'direction' => $d['direction'], 'party_id' => $d['party_id'] ?? null, 'party_name' => trim($d['party_name']), 'reference' => $d['reference'] ?? null,
                'principal' => round((float) $d['principal'], 2), 'annual_rate' => $d['annual_rate'], 'term_months' => $d['term_months'], 'method' => $d['method'],
                'release_date' => $d['release_date'], 'first_due_date' => $d['first_due_date'], 'loan_account' => $d['loan_account'],
                'interest_account' => $d['interest_account'], 'cash_account' => $d['cash_account'] ?? null, 'remarks' => $d['remarks'] ?? null, 'updated_at' => now(),
            ]);
            if ($releaseChanged) {   // re-post the release with the new figures
                DB::table(self::GJ)->where('added_by', $by)->where('jv_no', $loan->release_jv_no)->delete();
                $amt = round((float) $d['principal'], 2);
                $payable = $d['direction'] === 'payable';
                $this->postJournal($request, $by, $loan->release_jv_no, $d['release_date'], ($payable ? 'Loan proceeds – ' : 'Loan released – ') . trim($d['party_name']), [
                    ['account_code' => $d['cash_account'], 'debit' => $payable ? $amt : 0, 'credit' => $payable ? 0 : $amt],
                    ['account_code' => $d['loan_account'], 'debit' => $payable ? 0 : $amt, 'credit' => $payable ? $amt : 0],
                ]);
            }
        });
        return response()->json(['message' => 'Loan saved']);
    }

    /* POST /api/loans/{id}/void   (only without posted payments; removes the release journal) */
    public function lnVoid(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $loan = DB::table(self::L)->where('added_by', $by)->where('id', $id)->first();
        if (!$loan) return response()->json(['message' => 'Loan not found.'], 404);
        if (DB::table(self::P)->where('loan_id', $id)->where('status', 'posted')->exists()) {
            return response()->json(['message' => 'Void the payments first.'], 422);
        }
        if ($loan->release_jv_no && $this->isLocked($this->caidFor($request), [substr((string) $loan->release_date, 0, 7)])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }
        DB::transaction(function () use ($by, $loan) {
            if ($loan->release_jv_no) DB::table(self::GJ)->where('added_by', $by)->where('jv_no', $loan->release_jv_no)->delete();
            DB::table(self::L)->where('id', $loan->id)->update(['status' => 'void', 'updated_at' => now()]);
        });
        return response()->json(['message' => "{$loan->loan_no} voided"]);
    }

    /* POST /api/loans/{id}/payments   { payment_date, principal, interest, penalty?, cash_account, reference?, installment_no? } */
    public function lnPay(Request $request, int $id)
    {
        $d = $request->validate([
            'payment_date' => ['required', 'date'],
            'principal' => ['required', 'numeric', 'min:0'],
            'interest' => ['required', 'numeric', 'min:0'],
            'penalty' => ['nullable', 'numeric', 'min:0'],
            'cash_account' => ['required', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:100'],
            'installment_no' => ['nullable', 'integer', 'min:1'],
        ], ['cash_account.required' => 'Pick the cash / bank account']);
        $by = $this->addedByFor($request);
        $loan = DB::table(self::L)->where('added_by', $by)->where('id', $id)->first();
        if (!$loan) return response()->json(['message' => 'Loan not found.'], 404);
        if ($loan->status !== 'active') return response()->json(['message' => 'This loan is ' . $loan->status . '.'], 422);

        $total = round((float) $d['principal'] + (float) $d['interest'] + (float) ($d['penalty'] ?? 0), 2);
        if ($total <= 0) return response()->json(['message' => 'Enter the amount.'], 422);
        $paidP = (float) ($this->paidByLoan($by, [$id])[$id]['principal'] ?? 0);
        $left = round((float) $loan->principal - $paidP, 2);
        if ((float) $d['principal'] > $left + 0.009) return response()->json(['message' => 'Principal is more than the balance (' . number_format($left, 2) . ').'], 422);
        if ($this->isLocked($this->caidFor($request), [substr($d['payment_date'], 0, 7)])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        return DB::transaction(function () use ($request, $by, $d, $loan, $total, $left) {
            $no = $this->nextNo(self::P, 'payment_no', 'LP');
            $payable = $loan->direction === 'payable';
            $int = round((float) $d['interest'] + (float) ($d['penalty'] ?? 0), 2);
            $this->postJournal($request, $by, $no, $d['payment_date'], ($payable ? 'Loan payment – ' : 'Loan collection – ') . "{$loan->party_name} ({$loan->loan_no})", $payable ? [
                ['account_code' => $loan->loan_account, 'debit' => $d['principal']],
                ['account_code' => $loan->interest_account, 'debit' => $int],
                ['account_code' => $d['cash_account'], 'credit' => $total],
            ] : [
                ['account_code' => $d['cash_account'], 'debit' => $total],
                ['account_code' => $loan->loan_account, 'credit' => $d['principal']],
                ['account_code' => $loan->interest_account, 'credit' => $int],
            ]);
            DB::table(self::P)->insert([
                'loan_id' => $loan->id, 'payment_no' => $no, 'installment_no' => $d['installment_no'] ?? null, 'payment_date' => $d['payment_date'],
                'declared_month' => substr($d['payment_date'], 0, 7), 'principal' => round((float) $d['principal'], 2), 'interest' => round((float) $d['interest'], 2),
                'penalty' => round((float) ($d['penalty'] ?? 0), 2), 'total' => $total, 'cash_account' => $d['cash_account'],
                'reference' => $d['reference'] ?? null, 'status' => 'posted', 'jv_no' => $no, 'added_by' => $by,
                'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $closed = round($left - (float) $d['principal'], 2) <= 0.009;
            if ($closed) DB::table(self::L)->where('id', $loan->id)->update(['status' => 'closed', 'updated_at' => now()]);
            return response()->json(['payment_no' => $no, 'message' => "{$no} posted" . ($closed ? ' – loan fully paid' : '')]);
        });
    }

    /* POST /api/loans/payments/{paymentId}/void   removes its journal; reopens the loan */
    public function lnVoidPayment(Request $request, int $paymentId)
    {
        $by = $this->addedByFor($request);
        $p = DB::table(self::P)->where('added_by', $by)->where('id', $paymentId)->first();
        if (!$p) return response()->json(['message' => 'Payment not found.'], 404);
        if ($p->status === 'void') return response()->json(['message' => 'Already void.'], 422);
        if ($this->isLocked($this->caidFor($request), [substr((string) $p->payment_date, 0, 7)])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        DB::transaction(function () use ($by, $p) {
            if ($p->jv_no) DB::table(self::GJ)->where('added_by', $by)->where('jv_no', $p->jv_no)->delete();
            DB::table(self::P)->where('id', $p->id)->update(['status' => 'void', 'updated_at' => now()]);
            DB::table(self::L)->where('id', $p->loan_id)->where('status', 'closed')->update(['status' => 'active', 'updated_at' => now()]);
        });
        return response()->json(['message' => "{$p->payment_no} voided"]);
    }
}
