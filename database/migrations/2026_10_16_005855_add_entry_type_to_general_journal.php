<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * General Journal entry types:
 *   NORMAL    → General Ledger, Trial Balance, Working Trial Balance (DATA) and every report
 *   ADJUSTING → General Ledger, Trial Balance, Working Trial Balance (ADJUSTING ENTRIES) — Fixed Asset depreciation too
 *   CLOSING   → General Ledger only (CE-YYYY)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_general_journal', 'entry_type')) {
            Schema::table('t_general_journal', function (Blueprint $t) {
                $t->string('entry_type', 10)->default('NORMAL')->after('jv_no')->index();
            });
        }

        DB::table('t_general_journal')->whereNull('entry_type')->update(['entry_type' => 'NORMAL']);
        DB::table('t_general_journal')->where('jv_no', 'like', 'CE-%')->update(['entry_type' => 'CLOSING']);

        // entries posted by Fixed Asset Management are adjusting entries
        if (Schema::hasTable('t_fixed_asset_depreciation')) {
            DB::table('t_fixed_asset_depreciation')->whereNotNull('jv_no')->select('added_by', 'jv_no')->distinct()
                ->orderBy('added_by')
                ->each(fn ($r) => DB::table('t_general_journal')->where('added_by', $r->added_by)->where('jv_no', $r->jv_no)->update(['entry_type' => 'ADJUSTING']));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_general_journal', 'entry_type')) {
            Schema::table('t_general_journal', fn (Blueprint $t) => $t->dropColumn('entry_type'));
        }
    }
};