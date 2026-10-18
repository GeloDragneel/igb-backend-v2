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
        Schema::create('m_business_ownership_type_detail', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->nullable();
            $table->unsignedBigInteger('business_ownership_type_id');
            $table->foreign('business_ownership_type_id', 'fk_botd_bot_id')->references('id')->on('m_business_ownership_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_business_ownership_type_detail');
    }
};
