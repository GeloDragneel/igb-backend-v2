<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** m_payroll_cutoff.code: 10 → 20 characters (letters, numbers, - and _) */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('m_payroll_cutoff', 'code')) {
            Schema::table('m_payroll_cutoff', function (Blueprint $t) {
                $t->string('code', 20)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('m_payroll_cutoff', function (Blueprint $t) {
            $t->string('code', 10)->change();
        });
    }
};