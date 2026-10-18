<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_user_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comid')->nullable();
            $table->string('username', 50)->unique()->nullable();
            $table->string('password', 255)->nullable();
            $table->string('salutation', 50)->nullable();
            $table->unsignedBigInteger('user_type_id')->nullable();
            $table->unsignedBigInteger('user_access_id')->nullable();
            $table->unsignedBigInteger('user_level_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->tinyInteger('is_reset_password')->default(0);
            $table->tinyInteger('is_active')->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_user_staff');
    }
};