<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\DeclaredMonthSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Task → Fixed Asset Management
 * Straight-line depreciation: (cost − salvage) ÷ useful life (months), last month takes the rounding difference.
 * "Post Depreciation" writes one General Journal entry per month:
 *   Dr Depreciation Expense / Cr Accumulated Depreciation (per asset accounts), jv_no = DEP-YYYY-MM
 */
class FixedAssetController extends SalesController
{
    const A = 't_fixed_assets';
    const DEP = 't_fixed_asset_depreciation';
    const GJ = 't_general_journal';

    /* ---------- depreciation math ---------- */
    private function monthIndex(string $start, string $period): int
    {
        [$y1, $m1] = array_map('intval', explode('-', $start));
        [$y2, $m2] = array_map('intval', explode('-', $period));
        return ($y2 - $y1) * 12 + ($m2 - $m1);
    }

    /** depreciation for one asset in one month (0 outside its life) */
    private function amountFor(object $a, string $period): float
    {
        $life = max(1, (int) $a->useful_life_months);
        $k = $this->monthIndex($a->depreciation_start, $period);
        if ($k < 0 || $k >= $life) return 0;
        if ($a->status === 'disposed' && $a->disposed_date && $period > substr((string) $a->disposed_date, 0, 7)) return 0;
        $base = max(0, round((float) $a->cost - (float) $a->salvage_value, 2));
        $monthly = round($base / $life, 2);
        return $k === $life - 1 ? round($base - $monthly * ($life - 1), 2) : $monthly;
    }

    private function nextNo(): string
    {
        $prefix = 'FA-' . date('ymd') . '-';
        $n = DB::table(self::A)->where('asset_no', 'like', $prefix . '%')->count() + 1;
        do { $no = $prefix . str_pad((string) $n++, 3, '0', STR_PAD_LEFT); } while (DB::table(self::A)->where('asset_no', $no)->exists());
        return $no;
    }

    private function locked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    /* GET /api/fixed-assets */
    public function faIndex(Request $request)
    {
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $assets = DB::table(self::A)->where('added_by', $by)->orderByDesc('id')->get();
        $posted = DB::table(self::DEP)->where('added_by', $by)->groupBy('asset_id')
            ->selectRaw('asset_id, SUM(amount) AS total, MAX(period) AS last_period')->get()->keyBy('asset_id');
        $names = ChartsOfAccount::where('added_by', $by)->pluck('account_name', 'account_code');
        $thisMonth = date('Y-m');

        return response()->json($assets->map(function ($a) use ($posted, $names, $thisMonth) {
            $acc = (float) ($posted[$a->id]->total ?? 0);
            $life = max(1, (int) $a->useful_life_months);
            return (array) $a + [
                'acquisition_date' => substr((string) $a->acquisition_date, 0, 10),
                'monthly' => round(max(0, (float) $a->cost - (float) $a->salvage_value) / $life, 2),
                'accumulated' => round($acc, 2),
                'book_value' => round((float) $a->cost - $acc, 2),
                'last_posted' => $posted[$a->id]->last_period ?? null,
                'end_period' => Carbon::createFromFormat('Y-m-d', $a->depreciation_start . '-01')->addMonths($life - 1)->format('Y-m'),
                'due_this_month' => $this->amountFor($a, $thisMonth),
                'asset_account_name' => $names[$a->asset_account] ?? '',
            ];
        }));
    }

    /* GET /api/fixed-assets/accounts */
    public function faAccounts(Request $request)
    {
        return response()->json(ChartsOfAccount::where('added_by', $this->addedByFor($request))
            ->where('root_name', '!=', '0')->orderBy('account_code')->get(['account_code', 'account_name']));
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:150'],
            'serial_no' => ['nullable', 'string', 'max:100'],
            'acquisition_date' => ['required', 'date'],
            'depreciation_start' => ['required', 'date_format:Y-m'],
            'cost' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lte:cost'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'asset_account' => ['required', 'string', 'max:20'],
            'accum_dep_account' => ['required', 'string', 'max:20'],
            'dep_expense_account' => ['required', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /* POST /api/fixed-assets */
    public function faStore(Request $request)
    {
        $d = $request->validate($this->rules());
        $by = $this->addedByFor($request);
        $id = DB::table(self::A)->insertGetId($d + [
            'asset_no' => $this->nextNo(), 'salvage_value' => $d['salvage_value'] ?? 0, 'method' => 'straight_line',
            'status' => 'active', 'added_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Asset saved']);
    }

    /* PUT /api/fixed-assets/{id}   (cost / life / start are locked once depreciation is posted) */
    public function faUpdate(Request $request, int $id)
    {
        $d = $request->validate($this->rules());
        $by = $this->addedByFor($request);
        $a = DB::table(self::A)->where('added_by', $by)->where('id', $id)->first();
        if (!$a) return response()->json(['message' => 'Asset not found.'], 404);

        if (DB::table(self::DEP)->where('asset_id', $id)->exists()) {
            $changed = abs((float) $d['cost'] - (float) $a->cost) > 0.001
                || abs((float) ($d['salvage_value'] ?? 0) - (float) $a->salvage_value) > 0.001
                || (int) $d['useful_life_months'] !== (int) $a->useful_life_months
                || (string) $d['depreciation_start'] !== (string) $a->depreciation_start
                || (string) $d['accum_dep_account'] !== (string) $a->accum_dep_account
                || (string) $d['dep_expense_account'] !== (string) $a->dep_expense_account;
            {
                if ($changed) {
                    return response()->json(['message' => 'Depreciation is already posted for this asset, so cost, salvage value, life, start and depreciation accounts can\'t change. Delete the posted entries in General Journal first.'], 422);
                }
            }
        }
        DB::table(self::A)->where('id', $id)->update($d + ['salvage_value' => $d['salvage_value'] ?? 0, 'updated_at' => now()]);
        return response()->json(['message' => 'Asset saved']);
    }

    /* DELETE /api/fixed-assets/{id} */
    public function faDelete(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        if (!DB::table(self::A)->where('added_by', $by)->where('id', $id)->exists()) return response()->json(['message' => 'Asset not found.'], 404);
        if (DB::table(self::DEP)->where('asset_id', $id)->exists()) {
            return response()->json(['message' => 'This asset has posted depreciation. Mark it as disposed instead.'], 422);
        }
        DB::table(self::A)->where('id', $id)->delete();
        return response()->json(['message' => 'Asset deleted']);
    }

    /* POST /api/fixed-assets/{id}/dispose   { disposed_date, disposal_amount }  (no more depreciation after that month) */
    public function faDispose(Request $request, int $id)
    {
        $d = $request->validate(['disposed_date' => ['required', 'date'], 'disposal_amount' => ['nullable', 'numeric', 'min:0'], 'remarks' => ['nullable', 'string', 'max:255']]);
        $by = $this->addedByFor($request);
        $a = DB::table(self::A)->where('added_by', $by)->where('id', $id)->first();
        if (!$a) return response()->json(['message' => 'Asset not found.'], 404);
        DB::table(self::A)->where('id', $id)->update([
            'status' => 'disposed', 'disposed_date' => $d['disposed_date'], 'disposal_amount' => $d['disposal_amount'] ?? 0,
            'remarks' => $d['remarks'] ?? $a->remarks, 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Asset marked as disposed']);
    }

    /* POST /api/fixed-assets/{id}/restore   (undo disposal) */
    public function faRestore(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $n = DB::table(self::A)->where('added_by', $by)->where('id', $id)
            ->update(['status' => 'active', 'disposed_date' => null, 'disposal_amount' => 0, 'updated_at' => now()]);
        return $n ? response()->json(['message' => 'Asset is active again']) : response()->json(['message' => 'Asset not found.'], 404);
    }

    /* GET /api/fixed-assets/{id}/schedule   month by month for the whole life */
    public function faSchedule(Request $request, int $id)
    {
        $by = $this->addedByFor($request);
        $a = DB::table(self::A)->where('added_by', $by)->where('id', $id)->first();
        if (!$a) return response()->json(['message' => 'Asset not found.'], 404);
        $posted = DB::table(self::DEP)->where('asset_id', $id)->get()->keyBy('period');

        $rows = [];
        $acc = 0;
        $p = Carbon::createFromFormat('Y-m-d', $a->depreciation_start . '-01');
        for ($k = 0; $k < max(1, (int) $a->useful_life_months); $k++, $p->addMonth()) {
            $period = $p->format('Y-m');
            $amt = $this->amountFor($a, $period);
            $acc += $amt;
            $rows[] = [
                'period' => $period, 'label' => $p->format('M Y'), 'amount' => $amt,
                'accumulated' => round($acc, 2), 'book_value' => round((float) $a->cost - $acc, 2),
                'jv_no' => $posted[$period]->jv_no ?? null, 'posted' => isset($posted[$period]),
            ];
        }
        return response()->json(['asset' => $a, 'rows' => $rows]);
    }

    /* GET /api/fixed-assets/depreciation/preview?period=2026-10   what "Post" would create */
    public function faPreview(Request $request)
    {
        $d = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        return response()->json($this->buildRun($this->addedByFor($request), $d['period']));
    }

    private function buildRun(int $by, string $period): array
    {
        $assets = DB::table(self::A)->where('added_by', $by)->where('depreciation_start', '<=', $period)->get();
        $done = DB::table(self::DEP)->where('added_by', $by)->where('period', $period)->pluck('asset_id')->all();
        $names = ChartsOfAccount::where('added_by', $by)->pluck('account_name', 'account_code');

        $items = [];
        foreach ($assets as $a) {
            if (in_array($a->id, $done)) continue;
            $amt = $this->amountFor($a, $period);
            if ($amt <= 0) continue;
            $items[] = ['asset_id' => $a->id, 'asset_no' => $a->asset_no, 'name' => $a->name, 'amount' => $amt,
                'dep_expense_account' => $a->dep_expense_account, 'accum_dep_account' => $a->accum_dep_account];
        }

        // journal lines grouped by account
        $lines = [];
        foreach ($items as $it) {
            $lines['D' . $it['dep_expense_account']] ??= ['account_code' => $it['dep_expense_account'], 'account_name' => $names[$it['dep_expense_account']] ?? '', 'debit' => 0, 'credit' => 0];
            $lines['D' . $it['dep_expense_account']]['debit'] += $it['amount'];
            $lines['C' . $it['accum_dep_account']] ??= ['account_code' => $it['accum_dep_account'], 'account_name' => $names[$it['accum_dep_account']] ?? '', 'debit' => 0, 'credit' => 0];
            $lines['C' . $it['accum_dep_account']]['credit'] += $it['amount'];
        }
        $lines = array_map(fn ($l) => ['debit' => round($l['debit'], 2), 'credit' => round($l['credit'], 2)] + $l, array_values($lines));
        usort($lines, fn ($x, $y) => $y['debit'] <=> $x['debit']);

        return [
            'period' => $period, 'jv_no' => 'DEP-' . $period, 'items' => $items, 'lines' => $lines,
            'total' => round(array_sum(array_column($items, 'amount')), 2), 'already_posted' => count($done),
        ];
    }

    /* POST /api/fixed-assets/depreciation/post   { period: 2026-10, transaction_date: 2026-10-31 } */
    public function faPost(Request $request)
    {
        $d = $request->validate(['period' => ['required', 'date_format:Y-m'], 'transaction_date' => ['required', 'date']]);
        $by = $this->addedByFor($request);
        if ($this->locked($this->caidFor($request), [$d['period']])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        $run = $this->buildRun($by, $d['period']);
        if (!$run['items']) return response()->json(['message' => 'Nothing to post for ' . Carbon::createFromFormat('Y-m-d', $d['period'] . '-01')->format('F Y') . '.'], 422);

        // DEP-2026-10, or DEP-2026-10-2 when part of the month was posted before
        $jv = $run['jv_no'];
        for ($n = 2; DB::table(self::GJ)->where('added_by', $by)->where('jv_no', $jv)->exists(); $n++) $jv = $run['jv_no'] . '-' . $n;

        DB::transaction(function () use ($run, $d, $by, $jv, $request) {
            foreach ($run['lines'] as $l) {
                DB::table(self::GJ)->insert([
                    'jv_no' => $jv, 'transaction_date' => $d['transaction_date'], 'declared_month' => $d['period'],
                    'account_code' => $l['account_code'], 'remarks' => 'Depreciation ' . $d['period'],
                    'debit' => $l['debit'], 'credit' => $l['credit'],
                    'added_by' => $by, 'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($run['items'] as $it) {
                DB::table(self::DEP)->insert([
                    'asset_id' => $it['asset_id'], 'period' => $d['period'], 'amount' => $it['amount'], 'jv_no' => $jv,
                    'added_by' => $by, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => "Depreciation posted ({$jv})", 'jv_no' => $jv, 'total' => $run['total']]);
    }
}