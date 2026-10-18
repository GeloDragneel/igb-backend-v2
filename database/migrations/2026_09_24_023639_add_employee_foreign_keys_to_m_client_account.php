<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_employee', function (Blueprint $table) {
            $table->foreign('com_id')->references('id')->on('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('company_caid')->references('id')->on('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('m_client_account', function (Blueprint $table) {
            $table->foreign('assigned_daid')->references('id')->on('m_employee')->cascadeOnUpdate()->nullOnDelete();
            $table->foreign('assigned_auid')->references('id')->on('m_employee')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('m_user_staff', function (Blueprint $table) {
            $table->foreign('user_type_id')->references('id')->on('m_user_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('user_access_id')->references('id')->on('m_user_access')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('user_level_id')->references('id')->on('m_user_level')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('m_location')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('m_user_staff', function (Blueprint $table) {
            $table->dropForeign(['user_type_id']);
            $table->dropForeign(['user_access_id']);
            $table->dropForeign(['user_level_id']);
            $table->dropForeign(['location_id']);
        });

        Schema::table('m_employee', function (Blueprint $table) {
            $table->dropForeign(['com_id']);
            $table->dropForeign(['company_caid']);
        });

        Schema::table('m_client_account', function (Blueprint $table) {
            $table->dropForeign(['assigned_daid']);
            $table->dropForeign(['assigned_auid']);
        });
    }
};