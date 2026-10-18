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
        Schema::create('m_compliance', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->nullable();
            $table->text('defination')->nullable();
            $table->text('deadline')->nullable();
            $table->string('terms', 40)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_compliance');
    }
};
