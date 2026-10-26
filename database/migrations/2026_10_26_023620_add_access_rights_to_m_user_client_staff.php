<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Team → Module List: the ticked m_menu_data ids, "1-4-15" (read by TeamController and the login → sidebar) */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('m_user_client_staff', 'access_rights')) {
            Schema::table('m_user_client_staff', function (Blueprint $t) {
                $t->text('access_rights')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_user_client_staff', 'access_rights')) {
            Schema::table('m_user_client_staff', function (Blueprint $t) {
                $t->dropColumn('access_rights');
            });
        }
    }
};