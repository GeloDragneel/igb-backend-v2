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
        Schema::create('m_user_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caid')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('username', 50)->unique()->nullable();
            $table->string('password', 255)->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_pos')->default(0);
            $table->tinyInteger('is_web')->default(0);
            $table->tinyInteger('is_mobile')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_user_client');
    }
};