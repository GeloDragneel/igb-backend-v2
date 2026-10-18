<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->decimal('bracket_min', 15, 2);
            $table->decimal('bracket_max', 15, 2)->nullable();   // null = no upper limit
            $table->decimal('base_tax', 15, 2)->default(0);
            $table->decimal('rate', 6, 4)->default(0);            // 0.15 = 15%
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_tax_brackets');
    }
};