<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('m_vendor_customer', function (Blueprint $t) {
            $t->index(['entry_by', 'user_type'], 'idx_vc_entry_type2');
        });
        Schema::table('m_items', function (Blueprint $t) {
            $t->index(['added_by', 'item_code'], 'idx_items_added_code');
        });
    }

    public function down(): void
    {
        Schema::table('m_vendor_customer', fn (Blueprint $t) => $t->dropIndex('idx_vc_entry_type2'));
        Schema::table('m_items', fn (Blueprint $t) => $t->dropIndex('idx_items_added_code'));
    }
};
