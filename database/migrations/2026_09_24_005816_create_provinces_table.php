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
        Schema::create('m_province', function (Blueprint $table) {
            $table->id();
            $table->string('province_name', 45);
            $table->integer('population')->nullable();
            $table->integer('city_count')->nullable();
            $table->integer('mun_count')->nullable();
            $table->integer('brgy_count')->nullable();
            $table->string('border_type', 45)->nullable();
            $table->string('region_desc', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_province');
    }
};
