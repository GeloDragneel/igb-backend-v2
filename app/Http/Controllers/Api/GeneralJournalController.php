<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartsOfAccount;
use App\Models\DeclaredMonthSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task → General Journal (old General_Journal.php)
 * Table t_general_journal (your table): one row per line, lines of one entry share jv_no (the Reference).
 * Columns used: jv_no, account_code, subsidiary, debit, credit, remarks, transaction_date, declared_month, added_by, employee_id
 */
class GeneralJournalController extends SalesController
{
    const T = 't_general_journal';

    private function locked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    /** entry types: NORMAL (all reports) · ADJUSTING (adjusting entries: GL, TB, WTB adjusting columns) · CLOSING (CE-YYYY, General Ledger only) */
    const TYPES = ['NORMAL', 'ADJUSTING', 'CLOSING'];

    private function hasType(): bool
    {
        static $has = null;
        return $has ??= Schema::hasColumn(self::T, 'entry_type');
    }

    /** the type of an entry: CE-… = CLOSING · Fixed Asset depreciation = ADJUSTING · else the saved entry_type (default NORMAL) */
    private function typeOf(string $jvNo, ?string $saved, array $dep): string
    {
        if (str_starts_with(strtoupper($jvNo), 'CE-')) return 'CLOSING';
        if (isset($dep[$jvNo])) return 'ADJUSTING';
        $t = strtoupper(trim((string) $saved));
        return in_array($t, self::TYPES, true) && $t !== 'CLOSING' ? $t : 'NORMAL';
    }

    /** jv_no => true for entries posted by Fixed Asset Management */
    private function depreciationJvNos(int $by): array
    {
        if (!Schema::hasTable('t_fixed_asset_depreciation')) return [];
        return array_fill_keys(DB::table('t_fixed_asset_depreciation')->where('added_by', $by)->whereNotNull('jv_no')->distinct()->pluck('jv_no')->all(), true);
    }

    /* GET /api/general-journal?from=2026-01&to=2026-09&type=ALL|NORMAL|ADJUSTING|CLOSING&account_code=   one row per entry
       (old qry2_general_journal.php) · account_code = only entries with a line on that account (Working Trial Balance link) */
    public function gjIndex(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m'],
            'type' => ['nullable', 'in:ALL,NORMAL,ADJUSTING,CLOSING,all,normal,adjusting,closing'],
            'account_code' => ['nullable', 'string', 'max:20'],
        ]);
        $by = $this->addedByFor($request);
        if (!$by) return response()->json([]);
        $type = strtoupper($d['type'] ?? 'ALL');

        $rows = DB::table(self::T)->where('added_by', $by)
            ->whereRaw("LEFT(COALESCE(NULLIF(declared_month, ''), transaction_date), 7) BETWEEN ? AND ?", [$d['from'], $d['to']])
            ->when(!empty($d['account_code']), fn ($q) => $q->whereIn('jv_no',
                DB::table(self::T)->where('added_by', $by)->where('account_code', $d['account_code'])->select('jv_no')))
            ->groupBy('jv_no')
            ->selectRaw('MIN(id) AS id, jv_no, MAX(transaction_date) AS transaction_date, MAX(declared_month) AS declared_month,
                         SUM(debit) AS debit, SUM(credit) AS credit, COUNT(*) AS lines_count'
                         . ($this->hasType() ? ', MAX(entry_type) AS entry_type' : ", NULL AS entry_type"))
            ->orderByDesc('transaction_date')->orderByDesc('id')
            ->get();

        $dep = $this->depreciationJvNos($by);
        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'jv_no' => $r->jv_no,
            'transaction_date' => substr((string) $r->transaction_date, 0, 10),
            'declared_month' => $r->declared_month,
            'debit' => round((float) $r->debit, 2), 'credit' => round((float) $r->credit, 2),
            'lines_count' => (int) $r->lines_count, 'is_depreciation' => isset($dep[$r->jv_no]),
            'entry_type' => $this->typeOf((string) $r->jv_no, $r->entry_type, $dep),
        ])->filter(fn ($r) => $type === 'ALL' || $r['entry_type'] === $type)->values());
    }

    /* GET /api/general-journal/accounts   (old doGetAccounts) */
    public function gjAccounts(Request $request)
    {
        return response()->json(ChartsOfAccount::where('added_by', $this->addedByFor($request))
            ->where('root_name', '!=', '0')->orderBy('account_code')->get(['account_code', 'account_name']));
    }

    /* GET /api/general-journal/entry/{jvNo}   (old doGetMasterInfo) */
    public function gjShow(Request $request, string $jvNo)
    {
        $lines = DB::table(self::T)->where('added_by', $this->addedByFor($request))->where('jv_no', $jvNo)->orderBy('id')->get();
        if ($lines->isEmpty()) return response()->json(['message' => 'Journal entry not found.'], 404);

        return response()->json([
            'jv_no' => $jvNo,
            'transaction_date' => substr((string) $lines[0]->transaction_date, 0, 10),
            'declared_month' => $lines[0]->declared_month,
            'is_depreciation' => isset($this->depreciationJvNos($this->addedByFor($request))[$jvNo]),
            'entry_type' => $this->typeOf($jvNo, $lines[0]->entry_type ?? null, $this->depreciationJvNos($this->addedByFor($request))),
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id, 'account_code' => $l->account_code, 'remarks' => $l->remarks,
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'subsidiary' => $l->subsidiary ?? null,
            ]),
        ]);
    }

    /* POST /api/general-journal   new or edit (old save_general_journal.php)
       body: { original_jv_no?, jv_no, transaction_date, declared_month, lines: [{account_code, remarks, debit, credit, job}] } */
    public function gjSave(Request $request)
    {
        $d = $request->validate([
            'original_jv_no'         => ['nullable', 'string', 'max:60'],
            'jv_no'                  => ['required', 'string', 'max:60'],
            'transaction_date'       => ['required', 'date'],
            'declared_month'         => ['required', 'date_format:Y-m'],
            'entry_type'             => ['nullable', 'in:NORMAL,ADJUSTING,normal,adjusting'],
            'lines'                  => ['required', 'array', 'min:2'],
            'lines.*.account_code'   => ['required', 'string', 'max:20'],
            'lines.*.remarks'        => ['nullable', 'string', 'max:255'],
            'lines.*.debit'          => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.subsidiary'     => ['nullable'],
        ], [
            'jv_no.required' => 'Please input reference no',
            'lines.min' => 'Add at least two lines (a debit and a credit).',
        ]);

        $by = $this->addedByFor($request);
        if (!$by) return response()->json(['message' => 'No client account selected.'], 422);

        $dr = round(array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $d['lines'])), 2);
        $cr = round(array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $d['lines'])), 2);
        if ($dr <= 0) return response()->json(['message' => 'Enter the amounts.'], 422);
        if (abs($dr - $cr) > 0.009) return response()->json(['message' => "Not balanced: debit {$dr} vs credit {$cr}."], 422);
        foreach ($d['lines'] as $i => $l) {
            if ((float) ($l['debit'] ?? 0) > 0 && (float) ($l['credit'] ?? 0) > 0) {
                return response()->json(['message' => 'Row ' . ($i + 1) . ': put the amount in Debit or Credit, not both.'], 422);
            }
        }

        $original = $d['original_jv_no'] ?? null;
        $oldMonths = $original ? DB::table(self::T)->where('added_by', $by)->where('jv_no', $original)->pluck('declared_month')->all() : [];
        // closing entries (CE-YYYY) are not blocked by the Declared Month lock
        $isClosing = str_starts_with(strtoupper($d['jv_no']), 'CE-') || str_starts_with(strtoupper((string) $original), 'CE-');
        if (!$isClosing && $this->locked($this->caidFor($request), array_merge([$d['declared_month']], $oldMonths))) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }
        if ($d['jv_no'] !== $original && DB::table(self::T)->where('added_by', $by)->where('jv_no', $d['jv_no'])->exists()) {
            return response()->json(['message' => 'Reference no already in use'], 422);
        }

        // CE-… = Closing Entry · a Fixed Asset depreciation entry stays an Adjusting Entry
        $dep = $this->depreciationJvNos($by);
        $entryType = $isClosing ? 'CLOSING'
            : ((isset($dep[$d['jv_no']]) || ($original && isset($dep[$original]))) ? 'ADJUSTING' : strtoupper($d['entry_type'] ?? 'NORMAL'));

        DB::transaction(function () use ($d, $by, $original, $request, $entryType) {
            if ($original) DB::table(self::T)->where('added_by', $by)->where('jv_no', $original)->delete();
            // a renamed depreciation entry keeps its link to Fixed Assets
            if ($original && $original !== $d['jv_no'] && Schema::hasTable('t_fixed_asset_depreciation')) {
                DB::table('t_fixed_asset_depreciation')->where('added_by', $by)->where('jv_no', $original)->update(['jv_no' => $d['jv_no']]);
            }
            foreach ($d['lines'] as $l) {
                DB::table(self::T)->insert([
                    'jv_no' => $d['jv_no'], 'transaction_date' => $d['transaction_date'], 'declared_month' => $d['declared_month'],
                    'account_code' => $l['account_code'], 'remarks' => $l['remarks'] ?? null,
                    'debit' => round((float) ($l['debit'] ?? 0), 2), 'credit' => round((float) ($l['credit'] ?? 0), 2),
                    'subsidiary' => $l['subsidiary'] ?? null, 'added_by' => $by,
                    'employee_id' => $request->user()->employee_id ?? null, 'created_at' => now(), 'updated_at' => now(),
                ] + ($this->hasType() ? ['entry_type' => $entryType] : []));
            }
        });

        return response()->json(['message' => 'Record successfully saved', 'jv_no' => $d['jv_no'], 'entry_type' => $entryType]);
    }

    /* DELETE /api/general-journal/entry/{jvNo} */
    public function gjDelete(Request $request, string $jvNo)
    {
        $by = $this->addedByFor($request);
        $months = DB::table(self::T)->where('added_by', $by)->where('jv_no', $jvNo)->pluck('declared_month')->all();
        if (!$months) return response()->json(['message' => 'Journal entry not found.'], 404);
        if (!str_starts_with(strtoupper($jvNo), 'CE-') && $this->locked($this->caidFor($request), $months)) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }
        DB::transaction(function () use ($by, $jvNo) {
            DB::table(self::T)->where('added_by', $by)->where('jv_no', $jvNo)->delete();
            // a deleted depreciation entry can be posted again
            if (Schema::hasTable('t_fixed_asset_depreciation')) {
                DB::table('t_fixed_asset_depreciation')->where('added_by', $by)->where('jv_no', $jvNo)->delete();
            }
        });
        return response()->json(['message' => 'Journal entry deleted']);
    }
}