<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\DeclaredMonthSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task → Banking
 *   Collection  (old Sales/Receive_Money.php)         t_rvoucher_master / t_rvoucher_detail
 *   Payment     (old Vendors_and_Purchases/Pay_Bill)   t_pvoucher_master / t_pvoucher_detail
 *   Petty Cash  (new, imprest)                         t_petty_cash_fund / _voucher / _replenishment
 * Journal entries made by Petty Cash go to t_general_journal.
 */
class BankingController extends SalesController
{
    const GJ = 't_general_journal';

    private function accountNames(int $by)
    {
        return ChartsOfAccount::where('added_by', $by)->pluck('account_name', 'account_code');
    }

    private function isLocked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    private function monthLabel(?string $ym): string
    {
        return $ym && preg_match('/^\d{4}-\d{2}/', $ym) ? Carbon::createFromFormat('Y-m-d', substr($ym, 0, 7) . '-01')->format('F Y') : (string) $ym;
    }

    /* =====================================================================
       COLLECTION
       ===================================================================== */

    /* GET /api/banking/collections?from=2026-01&to=2026-12 */
    public function colIndex(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $names = $this->accountNames($by);

        $rows = DB::table('t_rvoucher_master as A')
            ->leftJoin('m_vendor_customer as C', 'C.id', '=', 'A.customer_id')
            ->where('A.added_by', $by)
            ->whereRaw("LEFT(COALESCE(NULLIF(A.declared_month, ''), A.rv_date), 7) BETWEEN ? AND ?", [$d['from'], $d['to']])
            ->orderByDesc('A.rv_date')->orderByDesc('A.id')
            ->get(['A.id', 'A.rvoucher_no', 'A.rv_date', 'A.invoice_no', 'A.document_no', 'A.sub_total', 'A.total', 'A.bank',
                'A.2307_it', 'A.2307_vt', 'A.rv_status', DB::raw("COALESCE(C.name, 'WALK IN') AS customer")]);

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'rvoucher_no' => $r->rvoucher_no, 'date' => substr((string) $r->rv_date, 0, 10),
            'customer' => $r->customer, 'invoice_no' => $r->invoice_no, 'document_no' => $r->document_no,
            'amount' => round((float) ($r->total ?: $r->sub_total), 2),
            'withholding' => round((float) $r->{'2307_it'} + (float) $r->{'2307_vt'}, 2),
            'bank' => $r->bank, 'payment_method' => $r->bank ? trim($r->bank . ' - ' . ($names[$r->bank] ?? '')) : '—',
            'status' => $r->rv_status,
        ]));
    }

    /* GET /api/banking/collections/{no}   invoices paid by this receipt */
    public function colDetails(Request $request, string $no)
    {
        $by = $this->addedByFor($request);
        $names = $this->accountNames($by);
        $rows = DB::table('t_rvoucher_detail')->where('added_by', $by)->where('rvoucher_no', $no)->orderBy('id')
            ->get(['id', 'invoice_no', 'account_code', 'sub_total', '2307_it', '2307_vt']);
        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'invoice_no' => $r->invoice_no, 'account_code' => $r->account_code,
            'account_name' => $names[$r->account_code] ?? '', 'amount' => round((float) $r->sub_total, 2),
            'withholding' => round((float) $r->{'2307_it'} + (float) $r->{'2307_vt'}, 2),
        ]));
    }

    /* GET /api/banking/collections/{no}/journal   (old doGetJournalList) */
    public function colJournal(Request $request, string $no)
    {
        $by = $this->addedByFor($request);
        $m = DB::table('t_rvoucher_master')->where('added_by', $by)->where('rvoucher_no', $no)->first();
        if (!$m) return response()->json([]);
        $names = $this->accountNames($by);
        $atc = fn ($id) => DB::table('m_atc')->where('id', $id)->first();

        $rows = [['account_code' => (string) $m->bank, 'account_name' => $names[$m->bank] ?? '', 'debit' => round((float) $m->sub_total, 2), 'credit' => 0]];
        foreach ([['2307_it', 'atc_it_id'], ['2307_vt', 'atc_vt_id']] as [$col, $atcCol]) {
            if ((float) $m->$col > 0) {
                $a = $m->$atcCol ? $atc($m->$atcCol) : null;
                $label = 'Withholding Tax - Receivable' . ($a ? ' - ' . ($a->atc ?? $a->code ?? '') . ' ' . round(((float) ($a->rate ?? 0)) * 100) . '%' : '');
                $rows[] = ['account_code' => '21910', 'account_name' => $label, 'debit' => round((float) $m->$col, 2), 'credit' => 0];
            }
        }
        foreach (DB::table('t_rvoucher_detail')->where('added_by', $by)->where('rvoucher_no', $no)
            ->groupBy('account_code')->selectRaw('account_code, SUM(sub_total) AS t')->get() as $l) {
            $rows[] = ['account_code' => (string) $l->account_code, 'account_name' => $names[$l->account_code] ?? '', 'debit' => 0, 'credit' => round((float) $l->t, 2)];
        }
        return response()->json($rows);
    }

    /* =====================================================================
       PAYMENT
       ===================================================================== */

    /* GET /api/banking/payments?from=2026-01&to=2026-12 */
    public function payIndex(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $names = $this->accountNames($by);

        $rows = DB::table('t_pvoucher_master as A')
            ->leftJoin('m_vendor_customer as V', 'V.id', '=', 'A.vendor_id')
            ->where('A.added_by', $by)
            ->whereRaw('LEFT(A.pv_date, 7) BETWEEN ? AND ?', [$d['from'], $d['to']])
            ->orderByDesc('A.pv_date')->orderByDesc('A.id')
            ->get(['A.id', 'A.pvoucher_no', 'A.pv_date', 'A.document_no', 'A.ap_invoice_no', 'A.total_amount', 'A.bank',
                'A.payment_type', 'A.ref_data', 'A.pay_to', 'A.pv_status', DB::raw('V.name AS vendor')]);

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'pvoucher_no' => $r->pvoucher_no, 'date' => substr((string) $r->pv_date, 0, 10),
            'vendor' => $r->vendor ?: ($r->pay_to ?: '—'), 'document_no' => $r->document_no, 'ap_invoice_no' => $r->ap_invoice_no,
            'amount' => round((float) $r->total_amount, 2), 'bank' => $r->bank,
            'payment_method' => $r->payment_type ?: ($r->bank ? trim($r->bank . ' - ' . ($names[$r->bank] ?? '')) : '—'),
            'ref_data' => $r->ref_data, 'status' => $r->pv_status,
        ]));
    }

    /* GET /api/banking/payments/{no}   (old doGetPayBilLDetails) */
    public function payDetails(Request $request, string $no)
    {
        $by = $this->addedByFor($request);
        if (!DB::table('t_pvoucher_master')->where('added_by', $by)->where('pvoucher_no', $no)->exists()) return response()->json([]);
        $names = $this->accountNames($by);
        $rows = DB::table('t_pvoucher_detail as A')->leftJoin('m_items as B', 'B.id', '=', 'A.item_id')
            ->where('A.pvoucher_no', $no)->orderBy('A.id')
            ->get(['A.id', 'A.ap_invoice_no', 'A.ref_data', 'A.acc_code', 'A.amount', 'A.qty',
                DB::raw("COALESCE(B.item_code, '') AS item_code"), DB::raw("COALESCE(B.description, '') AS description")]);
        return response()->json($rows->map(fn ($r) => (array) $r + ['account_name' => $names[$r->acc_code] ?? '', 'amount' => round((float) $r->amount, 2)]));
    }

    /* GET /api/banking/payments/{no}/journal   Dr detail accounts / Cr bank */
    public function payJournal(Request $request, string $no)
    {
        $by = $this->addedByFor($request);
        $m = DB::table('t_pvoucher_master')->where('added_by', $by)->where('pvoucher_no', $no)->first();
        if (!$m) return response()->json([]);
        $names = $this->accountNames($by);
        $rows = [];
        foreach (DB::table('t_pvoucher_detail')->where('pvoucher_no', $no)->groupBy('acc_code')->selectRaw('acc_code, SUM(amount) AS t')->get() as $l) {
            $rows[] = ['account_code' => (string) $l->acc_code, 'account_name' => $names[$l->acc_code] ?? '', 'debit' => round((float) $l->t, 2), 'credit' => 0];
        }
        $rows[] = ['account_code' => (string) $m->bank, 'account_name' => $names[$m->bank] ?? '', 'debit' => 0, 'credit' => round((float) $m->total_amount, 2)];
        return response()->json($rows);
    }

    /* =====================================================================
       PETTY CASH
       ===================================================================== */
    const F = 't_petty_cash_fund';
    const V = 't_petty_cash_voucher';
    const R = 't_petty_cash_replenishment';

    private function nextNo(string $table, string $col, string $prefix): string
    {
        $p = $prefix . '-' . date('ymd') . '-';
        $n = DB::table($table)->where($col, 'like', $p . '%')->count() + 1;
        do { $no = $p . str_pad((string) $n++, 3, '0', STR_PAD_LEFT); } while (DB::table($table)->where($col, $no)->exists());
        return $no;
    }

    /** writes one balanced entry into t_general_journal */
    private function postJournal(Request $request, int $by, string $jvNo, string $date, string $month, string $remarks, array $lines): void
    {
        $emp = $request->user()->employee_id ?? null;
        foreach ($lines as $l) {
            if (round((float) $l['debit'], 2) == 0 && round((float) $l['credit'], 2) == 0) continue;
            DB::table(self::GJ)->insert([
                'jv_no' => $jvNo, 'account_code' => $l['account_code'], 'subsidiary' => null,
                'debit' => round((float) $l['debit'], 2), 'credit' => round((float) $l['credit'], 2),
                'remarks' => $l['remarks'] ?? $remarks, 'transaction_date' => $date, 'declared_month' => $month,
                'added_by' => $by, 'employee_id' => $emp, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function fundRow(object $f, array $open): array
    {
        $spent = (float) ($open[$f->id] ?? 0);
        return [
            'id' => $f->id, 'name' => $f->name, 'custodian' => $f->custodian, 'account_code' => $f->account_code,
            'fund_amount' => round((float) $f->fund_amount, 2), 'is_active' => (bool) $f->is_active,
            'unreplenished' => round($spent, 2), 'on_hand' => round((float) $f->fund_amount - $spent, 2),
        ];
    }

    /* GET /api/banking/petty-cash   funds + accounts */
    public function pcOverview(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['funds' => [], 'accounts' => []]);
        $open = DB::table(self::V)->where('added_by', $by)->where('status', 'open')->groupBy('fund_id')
            ->selectRaw('fund_id, SUM(amount) AS t')->pluck('t', 'fund_id')->all();
        $funds = DB::table(self::F)->where('added_by', $by)->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn ($f) => $this->fundRow($f, $open));
        $accounts = ChartsOfAccount::where('added_by', $by)->where('root_name', '!=', '0')
            ->orderBy('account_code')->get(['account_code', 'account_name']);
        return response()->json(['funds' => $funds, 'accounts' => $accounts]);
    }

    /* POST /api/banking/petty-cash/funds          new fund (posts Dr Petty Cash / Cr Bank)
       PUT  /api/banking/petty-cash/funds/{id}     edit; a new fund amount posts the difference */
    public function pcFundSave(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'custodian' => ['nullable', 'string', 'max:120'],
            'account_code' => ['required', 'string', 'max:20'],
            'fund_amount' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'bank_account' => ['nullable', 'string', 'max:20'],
            'txn_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['account_code.required' => 'Pick the Petty Cash account.']);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);

        $old = $id ? DB::table(self::F)->where('added_by', $by)->where('id', $id)->first() : null;
        if ($id && !$old) return response()->json(['message' => 'Fund not found.'], 404);

        $diff = round((float) $d['fund_amount'] - (float) ($old->fund_amount ?? 0), 2);
        if ($old && $diff != 0 && $old->account_code !== $d['account_code']) {
            return response()->json(['message' => 'Change the amount and the account in two separate saves.'], 422);
        }
        $date = $d['txn_date'] ?? date('Y-m-d');
        $month = substr($date, 0, 7);
        if ($diff != 0) {
            if (empty($d['bank_account'])) return response()->json(['message' => 'Pick the bank / cash account the money comes from (or goes back to).'], 422);
            if ($this->isLocked($this->caidFor($request), [$month])) return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        return DB::transaction(function () use ($request, $by, $d, $old, $diff, $date, $month) {
            $row = ['name' => trim($d['name']), 'custodian' => $d['custodian'] ?? null, 'account_code' => $d['account_code'],
                'fund_amount' => round((float) $d['fund_amount'], 2), 'is_active' => $d['is_active'] ?? true, 'updated_at' => now()];
            if ($old) {
                DB::table(self::F)->where('id', $old->id)->update($row);
                $fundId = $old->id;
            } else {
                $fundId = DB::table(self::F)->insertGetId($row + ['added_by' => $by, 'created_at' => now()]);
            }

            if ($diff != 0) {
                $no = $this->nextNo(self::R, 'replenish_no', 'PCF');
                $remarks = ($old ? ($diff > 0 ? 'Petty cash fund increase' : 'Petty cash fund decrease') : 'Petty cash fund set-up') . ' – ' . $row['name'];
                $this->postJournal($request, $by, $no, $date, $month, $remarks, [
                    ['account_code' => $d['account_code'], 'debit' => max($diff, 0), 'credit' => max(-$diff, 0)],
                    ['account_code' => $d['bank_account'], 'debit' => max(-$diff, 0), 'credit' => max($diff, 0)],
                ]);
                DB::table(self::R)->insert([
                    'replenish_no' => $no, 'fund_id' => $fundId, 'type' => 'fund', 'txn_date' => $date, 'declared_month' => $month,
                    'amount' => $diff, 'bank_account' => $d['bank_account'], 'reference' => $d['reference'] ?? null, 'remarks' => $remarks,
                    'jv_no' => $no, 'added_by' => $by, 'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return response()->json(['id' => $fundId, 'message' => $diff != 0 ? 'Fund saved and journal posted' : 'Fund saved']);
        });
    }

    public function pcFundUpdate(Request $request, int $id)
    {
        return $this->pcFundSave($request, $id);
    }

    /* GET /api/banking/petty-cash/vouchers?fund_id=&status=open|replenished|void|all&from=&to= */
    public function pcVouchers(Request $request)
    {
        $d = $request->validate([
            'fund_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,replenished,void,all'],
            'from' => ['nullable', 'date_format:Y-m'], 'to' => ['nullable', 'date_format:Y-m'],
        ]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $names = $this->accountNames($by);

        $rows = DB::table(self::V . ' as V')->leftJoin(self::F . ' as F', 'F.id', '=', 'V.fund_id')
            ->where('V.added_by', $by)
            ->when($d['fund_id'] ?? null, fn ($q, $f) => $q->where('V.fund_id', $f))
            ->when(($d['status'] ?? 'all') !== 'all', fn ($q) => $q->where('V.status', $d['status']))
            // open vouchers always show; others follow the period
            ->when(($d['from'] ?? null) && ($d['to'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('V.status', 'open')
                ->orWhereRaw("LEFT(COALESCE(NULLIF(V.declared_month, ''), V.pcv_date), 7) BETWEEN ? AND ?", [$d['from'], $d['to']])))
            ->orderByDesc('V.pcv_date')->orderByDesc('V.id')
            ->get(['V.*', DB::raw('F.name AS fund_name')]);

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'pcv_no' => $r->pcv_no, 'fund_id' => $r->fund_id, 'fund_name' => $r->fund_name,
            'date' => substr((string) $r->pcv_date, 0, 10), 'declared_month' => $r->declared_month,
            'payee' => $r->payee, 'particulars' => $r->particulars, 'reference' => $r->reference,
            'expense_account' => $r->expense_account, 'expense_name' => $names[$r->expense_account] ?? '',
            'amount' => round((float) $r->amount, 2), 'status' => $r->status, 'replenish_no' => $r->replenish_no,
        ]));
    }

    /* POST /api/banking/petty-cash/vouchers        new PCV
       PUT  /api/banking/petty-cash/vouchers/{id}   edit (open vouchers only) */
    public function pcVoucherSave(Request $request, ?int $id = null)
    {
        $d = $request->validate([
            'fund_id' => ['required', 'integer'],
            'pcv_date' => ['required', 'date'],
            'declared_month' => ['required', 'date_format:Y-m'],
            'payee' => ['required', 'string', 'max:150'],
            'particulars' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expense_account' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ], ['payee.required' => 'Payee is required', 'expense_account.required' => 'Pick the expense account', 'amount.gt' => 'Amount is required']);
        $by = $this->addedByFor($request);

        $fund = DB::table(self::F)->where('added_by', $by)->where('id', $d['fund_id'])->first();
        if (!$fund) return response()->json(['message' => 'Fund not found.'], 404);
        if (!$fund->is_active) return response()->json(['message' => 'This fund is closed.'], 422);

        $old = $id ? DB::table(self::V)->where('added_by', $by)->where('id', $id)->first() : null;
        if ($id && !$old) return response()->json(['message' => 'Voucher not found.'], 404);
        if ($old && $old->status !== 'open') return response()->json(['message' => 'Only open vouchers can be edited.'], 422);

        $spent = (float) DB::table(self::V)->where('fund_id', $fund->id)->where('status', 'open')->when($old, fn ($q) => $q->where('id', '<>', $old->id))->sum('amount');
        $onHand = round((float) $fund->fund_amount - $spent, 2);
        if ((float) $d['amount'] > $onHand + 0.001) {
            return response()->json(['message' => 'Not enough cash on hand (' . number_format($onHand, 2) . '). Replenish the fund first.'], 422);
        }

        $row = [
            'fund_id' => $fund->id, 'pcv_date' => $d['pcv_date'], 'declared_month' => $d['declared_month'], 'payee' => trim($d['payee']),
            'particulars' => $d['particulars'] ?? null, 'reference' => $d['reference'] ?? null, 'expense_account' => $d['expense_account'],
            'amount' => round((float) $d['amount'], 2), 'updated_at' => now(),
        ];
        if ($old) {
            DB::table(self::V)->where('id', $old->id)->update($row);
            return response()->json(['id' => $old->id, 'pcv_no' => $old->pcv_no, 'message' => 'Voucher saved']);
        }
        $no = $this->nextNo(self::V, 'pcv_no', 'PCV');
        $newId = DB::table(self::V)->insertGetId($row + ['pcv_no' => $no, 'status' => 'open', 'added_by' => $by,
            'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now()]);
        return response()->json(['id' => $newId, 'pcv_no' => $no, 'message' => "{$no} saved"]);
    }

    public function pcVoucherUpdate(Request $request, int $id)
    {
        return $this->pcVoucherSave($request, $id);
    }

    /* POST /api/banking/petty-cash/vouchers/{id}/void   (open vouchers only) */
    public function pcVoucherVoid(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $v = DB::table(self::V)->where('added_by', $by)->where('id', $id)->first();
        if (!$v) return response()->json(['message' => 'Voucher not found.'], 404);
        if ($v->status !== 'open') return response()->json(['message' => 'Only open vouchers can be voided.'], 422);
        DB::table(self::V)->where('id', $id)->update(['status' => 'void', 'updated_at' => now()]);
        return response()->json(['message' => "{$v->pcv_no} voided"]);
    }

    /* POST /api/banking/petty-cash/replenish
       { fund_id, voucher_ids[], txn_date, bank_account, reference? }
       Dr each expense account / Cr bank, vouchers become 'replenished' */
    public function pcReplenish(Request $request)
    {
        $d = $request->validate([
            'fund_id' => ['required', 'integer'],
            'voucher_ids' => ['required', 'array', 'min:1'], 'voucher_ids.*' => ['integer'],
            'txn_date' => ['required', 'date'],
            'bank_account' => ['required', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], ['voucher_ids.required' => 'Pick at least one voucher', 'bank_account.required' => 'Pick the bank / cash account']);
        $by = $this->addedByFor($request);
        $fund = DB::table(self::F)->where('added_by', $by)->where('id', $d['fund_id'])->first();
        if (!$fund) return response()->json(['message' => 'Fund not found.'], 404);

        $vouchers = DB::table(self::V)->where('added_by', $by)->where('fund_id', $fund->id)->where('status', 'open')
            ->whereIn('id', $d['voucher_ids'])->get();
        if ($vouchers->isEmpty()) return response()->json(['message' => 'Those vouchers are already replenished or voided.'], 422);

        $month = substr($d['txn_date'], 0, 7);
        if ($this->isLocked($this->caidFor($request), [$month])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        return DB::transaction(function () use ($request, $by, $d, $fund, $vouchers, $month) {
            $no = $this->nextNo(self::R, 'replenish_no', 'PCR');
            $total = round($vouchers->sum(fn ($v) => (float) $v->amount), 2);
            $lines = $vouchers->groupBy('expense_account')->map(fn ($g, $acc) => [
                'account_code' => $acc, 'debit' => round($g->sum(fn ($v) => (float) $v->amount), 2), 'credit' => 0,
                'remarks' => 'Petty cash: ' . $g->pluck('pcv_no')->implode(', '),
            ])->values()->all();
            $lines[] = ['account_code' => $d['bank_account'], 'debit' => 0, 'credit' => $total];

            $this->postJournal($request, $by, $no, $d['txn_date'], $month, "Petty cash replenishment – {$fund->name}", $lines);
            DB::table(self::R)->insert([
                'replenish_no' => $no, 'fund_id' => $fund->id, 'type' => 'replenish', 'txn_date' => $d['txn_date'], 'declared_month' => $month,
                'amount' => $total, 'bank_account' => $d['bank_account'], 'reference' => $d['reference'] ?? null,
                'remarks' => $vouchers->count() . ' voucher(s)', 'jv_no' => $no, 'added_by' => $by,
                'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table(self::V)->whereIn('id', $vouchers->pluck('id'))->update(['status' => 'replenished', 'replenish_no' => $no, 'updated_at' => now()]);

            return response()->json(['replenish_no' => $no, 'total' => $total, 'message' => "Replenished {$vouchers->count()} voucher(s) – {$no}"]);
        });
    }

    /* GET /api/banking/petty-cash/history?fund_id=   fund set-up / changes and replenishments */
    public function pcHistory(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $names = $this->accountNames($by);
        $rows = DB::table(self::R . ' as R')->leftJoin(self::F . ' as F', 'F.id', '=', 'R.fund_id')
            ->where('R.added_by', $by)->when($request->integer('fund_id'), fn ($q, $f) => $q->where('R.fund_id', $f))
            ->orderByDesc('R.txn_date')->orderByDesc('R.id')
            ->get(['R.*', DB::raw('F.name AS fund_name')]);
        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'replenish_no' => $r->replenish_no, 'type' => $r->type, 'fund_name' => $r->fund_name,
            'date' => substr((string) $r->txn_date, 0, 10), 'month' => $this->monthLabel($r->declared_month),
            'amount' => round((float) $r->amount, 2), 'bank_account' => $r->bank_account,
            'bank_name' => $names[$r->bank_account] ?? '', 'reference' => $r->reference, 'remarks' => $r->remarks, 'jv_no' => $r->jv_no,
        ]));
    }

    /* GET /api/banking/petty-cash/journal/{jvNo}   the General Journal lines of a PCF / PCR */
    public function pcJournal(Request $request, string $jvNo)
    {
        $by = $this->addedByFor($request);
        $names = $this->accountNames($by);
        return response()->json(DB::table(self::GJ)->where('added_by', $by)->where('jv_no', $jvNo)->orderBy('id')
            ->get(['account_code', 'debit', 'credit', 'remarks'])
            ->map(fn ($l) => ['account_code' => $l->account_code, 'account_name' => $names[$l->account_code] ?? '',
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'remarks' => $l->remarks]));
    }
}
