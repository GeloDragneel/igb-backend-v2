<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * t_ai_message – the AI Assistant chat (header), one conversation per login.
 * Only creates a new table – no existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_ai_message')) return;
        Schema::create('t_ai_message', function (Blueprint $t) {
            $t->id();
            $t->string('user_type', 20);                 // Client | Staff | ClientStaff
            $t->unsignedBigInteger('user_id');           // m_user_client / m_user_staff / m_user_client_staff .id
            $t->string('role', 10);                      // user | assistant
            $t->text('content');
            $t->unsignedInteger('tokens')->nullable();
            $t->timestamps();
            $t->index(['user_type', 'user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_ai_message');
    }
};