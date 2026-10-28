<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Google / Facebook accounts linked to a FEMS login (m_user_staff, m_user_client or m_user_client_staff) */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_user_social')) return;
        Schema::create('m_user_social', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20);                 // google | facebook
            $t->string('provider_id', 100);             // the account id at Google / Facebook
            $t->string('email', 150)->nullable();
            $t->string('name', 150)->nullable();
            $t->string('avatar', 500)->nullable();
            $t->string('user_type', 20);                // Staff | Client | ClientStaff
            $t->unsignedBigInteger('user_id');
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();

            $t->unique(['provider', 'provider_id']);              // one Google account → one FEMS login
            $t->unique(['provider', 'user_type', 'user_id']);     // one Google account per FEMS login
            $t->index(['user_type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_user_social');
    }
};
