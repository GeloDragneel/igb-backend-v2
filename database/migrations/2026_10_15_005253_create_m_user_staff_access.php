<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Administration → User Access: which Staff pages each m_user_staff login can open
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_user_staff_access')) return;
        Schema::create('m_user_staff_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_staff_id')->index();   // m_user_staff.id
            $table->string('path', 150);                              // sidebar link, e.g. /billing/dashboard
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();
            $table->unique(['user_staff_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_user_staff_access');
    }
};