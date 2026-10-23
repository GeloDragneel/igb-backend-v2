<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * m_compliance.deadline ← the BIR RETURN DEADLINE schedule, for every compliance whose name has the return code.
 * (Client → Master File → Compliance shows the same text; the dashboards work out the actual date from it.)
 * Compliances that are not BIR returns are not touched. The old texts are kept in a backup table for the rollback.
 */
return new class extends Migration
{
    private const RULES = [
        ['/0619\s*-?\s*E/i', 'Every 10th day after the end of each month'],
        ['/1601\s*-?\s*C/i', 'Every 10th day after the end of each month'],
        ['/1601\s*-?\s*EQ|EXPANDED.*DAT|DAT.*EXPANDED/i', 'Every last day of the month after the end of each quarter'],
        ['/2550\s*-?\s*M/i', 'Voluntary'],
        ['/2550\s*-?\s*Q/i', 'Every 25th day after the end of each month'],
        ['/\bSLSP\b|SUMMARY LIST OF SALES/i', 'Every 25th day after the end of each month'],
        ['/2551\s*-?\s*Q/i', 'Every 25th day after the end of each month'],
        ['/1701\s*-?\s*Q/i', '1st Quarter: May 15 · 2nd Quarter: August 15 · 3rd Quarter: November 15 (45 days after the end of each quarter)'],
        ['/1702\s*-?\s*Q/i', '1st Quarter: May 29 · 2nd Quarter: August 29 · 3rd Quarter: November 29 (60 days after the end of each quarter)'],
        ['/1701(?!\s*-?\s*Q)/i', 'April 15'],
        ['/1702(?!\s*-?\s*Q)/i', 'April 15'],
        ['/1604\s*-?\s*C/i', 'January 31'],
        ['/1604\s*-?\s*E/i', 'March 01'],
    ];

    private function table(): ?string
    {
        foreach (['m_compliance', 'm_compliances'] as $t) if (Schema::hasTable($t)) return $t;
        return null;
    }

    public function up(): void
    {
        $t = $this->table();
        if (!$t || !Schema::hasColumn($t, 'deadline')) return;
        $nameCol = collect(['name', 'compliance', 'description'])->first(fn ($c) => Schema::hasColumn($t, $c));
        if (!$nameCol) return;

        if (!Schema::hasTable('bak_compliance_deadline')) {
            DB::statement("CREATE TABLE bak_compliance_deadline AS SELECT id, deadline FROM `{$t}`");
        }
        foreach (DB::table($t)->get(['id', $nameCol . ' as n']) as $r) {
            foreach (self::RULES as [$re, $text]) {
                if (preg_match($re, (string) $r->n)) {
                    DB::table($t)->where('id', $r->id)->update(['deadline' => $text]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        $t = $this->table();
        if (!$t || !Schema::hasTable('bak_compliance_deadline')) return;
        DB::statement("UPDATE `{$t}` C JOIN bak_compliance_deadline B ON B.id = C.id SET C.deadline = B.deadline");
        Schema::drop('bak_compliance_deadline');
    }
};