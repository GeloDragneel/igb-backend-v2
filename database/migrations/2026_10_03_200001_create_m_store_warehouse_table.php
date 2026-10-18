<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Store / Warehouse list (old tbl2_store_warehouse) – used as "Location" in Receive Inventory */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_store_warehouse')) {
            Schema::create('m_store_warehouse', function (Blueprint $t) {
                $t->id(); // ID
                $t->string('description', 150); // Description
                $t->string('address', 255)->nullable();
                $t->unsignedBigInteger('added_by')->index(); // UserID
                $t->boolean('is_active')->default(true); // Active
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m_store_warehouse');
    }
};
