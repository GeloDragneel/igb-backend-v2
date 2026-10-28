<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * m_charts_of_account.account_name was too short for names like
 * "Accumulated Depreciation - Fuel Pumps, Tanks and Station Equipment" (Data too long for column 'account_name')
 * → VARCHAR(150). Existing names are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['m_charts_of_account', 'm_nature_coa_template'] as $t) {
            if (!Schema::hasTable($t) || !Schema::hasColumn($t, 'account_name')) continue;
            $col = collect(Schema::getColumns($t))->firstWhere('name', 'account_name');
            if (!preg_match('/char\((\d+)\)/i', (string) ($col['type'] ?? ''), $m)) continue;   // text etc. – already long enough
            if ((int) $m[1] >= 150) continue;
            $null = !empty($col['nullable']) ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE `{$t}` MODIFY `account_name` VARCHAR(150) {$null}");
        }
    }

    public function down(): void
    {
        // not shortened again (longer names would be cut)
    }
};