<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('m_client_account', 'logo')) return;
        Schema::table('m_client_account', function (Blueprint $table) {
            $table->string('logo', 255)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('m_client_account', 'logo')) return;
        Schema::table('m_client_account', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};