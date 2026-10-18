<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_2551Q_atc', function (Blueprint $table) {
            $table->id();
            $table->string('atc', 20);                        // e.g. PT 010
            $table->string('description', 255)->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);    // fraction: 0.03 = 3%
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_2551Q_atc');
    }
};