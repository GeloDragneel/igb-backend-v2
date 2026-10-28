<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Client → Business Ownership: "Top Withholding Agent" (TWA) – yes / no */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_client') && !Schema::hasColumn('m_client', 'is_top_withholding_agent')) {
            Schema::table('m_client', function (Blueprint $t) {
                $t->boolean('is_top_withholding_agent')->default(false)->after('business_ownership_type_detail_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_client', 'is_top_withholding_agent')) {
            Schema::table('m_client', fn (Blueprint $t) => $t->dropColumn('is_top_withholding_agent'));
        }
    }
};