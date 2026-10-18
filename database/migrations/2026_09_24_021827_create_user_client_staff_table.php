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
        Schema::create('m_user_client_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caid')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_client_id')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('fullname', 50)->nullable();
            $table->string('username', 50)->unique()->nullable();
            $table->string('password', 255)->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_pos')->default(0);
            $table->tinyInteger('is_scan_suite')->default(0);
            $table->tinyInteger('is_web')->default(0);
            $table->foreignId('employee_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_user_client_staff');
    }
};