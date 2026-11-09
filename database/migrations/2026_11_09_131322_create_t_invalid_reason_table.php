<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_invalid_reason')) return;

        Schema::create('t_invalid_reason', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 100)->index();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_invalid_reason');
    }
};