<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task → Inventory → Settings (one row per client account)
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_inventory_settings')) return;

        Schema::create('m_inventory_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('caid')->unique();                 // client account
            $t->string('inventory_method', 20)->default('FIFO');      // FIFO | WAC | SPECIFIC
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_inventory_settings');
    }
};
