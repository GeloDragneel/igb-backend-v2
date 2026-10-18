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
        Schema::create('m_city_mun', function (Blueprint $table) {
            $table->id();
            $table->string('description', 60);
            $table->foreignId('province_id') // unsigned BIGINT, same as m_province.id
                  ->comment('connected to m_province')
                  ->constrained('m_province') // references m_province.id
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();
            $table->integer('zip_code');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_city_mun');
    }
};