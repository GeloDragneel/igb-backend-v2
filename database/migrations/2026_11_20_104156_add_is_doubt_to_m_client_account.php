<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_client_account', function (Blueprint $t) {
            if (!Schema::hasColumn('m_client_account', 'is_doubt_eo')) $t->tinyInteger('is_doubt_eo')->default(0);
            if (!Schema::hasColumn('m_client_account', 'is_doubt_pf')) $t->tinyInteger('is_doubt_pf')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('m_client_account', function (Blueprint $t) {
            $t->dropColumn(['is_doubt_eo', 'is_doubt_pf']);
        });
    }
};