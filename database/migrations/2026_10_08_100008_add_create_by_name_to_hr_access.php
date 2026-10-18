<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** m_hr_access.created_by_name: the login that added the row (the IT Officer may have no employee record) */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('m_hr_access', 'created_by_name')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->string('created_by_name', 150)->nullable()->after('created_by');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_hr_access', 'created_by_name')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->dropColumn('created_by_name');
            });
        }
    }
};