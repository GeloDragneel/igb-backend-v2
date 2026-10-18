<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup → Discount List   (old tbl2_discount_list: ID, Description, Value, AddedBy)
 * Setup → Store/Warehouse (old tbl2_store_warehouse) – adds Code and Type to m_store_warehouse
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_discount_list')) {
            Schema::create('m_discount_list', function (Blueprint $t) {
                $t->id();                                      // ID
                $t->string('description', 150);                // Description
                $t->decimal('value', 8, 4)->default(0);        // Value (percent)
                $t->unsignedBigInteger('added_by')->index();   // AddedBy (user client id)
                $t->timestamps();
            });
        }

        Schema::table('m_store_warehouse', function (Blueprint $t) {
            if (!Schema::hasColumn('m_store_warehouse', 'code')) $t->string('code', 30)->nullable()->after('id');  // Code
            if (!Schema::hasColumn('m_store_warehouse', 'type')) $t->string('type', 20)->nullable()->after('description'); // Store | Warehouse
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_discount_list');
        Schema::table('m_store_warehouse', function (Blueprint $t) {
            if (Schema::hasColumn('m_store_warehouse', 'type')) $t->dropColumn('type');
            if (Schema::hasColumn('m_store_warehouse', 'code')) $t->dropColumn('code');
        });
    }
};
