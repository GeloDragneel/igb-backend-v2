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
        Schema::create('m_service_fee', function (Blueprint $table) {
            $table->id();
            $table->decimal('service_fee', 10, 2)->default(0.00);
            $table->string('remarks', 255);
            $table->integer('service_cat_id')->nullable();
            $table->integer('com_dep_id')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->foreignId('ser_list_id')->nullable()->constrained('m_service_list')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_service_fee');
    }
};
