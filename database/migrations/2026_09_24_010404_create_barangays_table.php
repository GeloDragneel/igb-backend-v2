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
        Schema::create('m_barangay', function (Blueprint $table) {
            $table->id();
            $table->string('description', 60);
            $table->foreignId('city_id')                     // creates the column: unsigned BIGINT
                  ->comment('connected to m_city_mun')
                  ->constrained('m_city_mun')                // references m_city_mun.id
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_barangay');
    }
};