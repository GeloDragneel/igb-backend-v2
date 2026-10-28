<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 15-day trial for clients who signed up with Google / Facebook. NULL = full account (no trial). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('m_client', 'trial_ends_at')) {
            Schema::table('m_client', function (Blueprint $t) {
                $t->dateTime('trial_ends_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_client', 'trial_ends_at')) {
            Schema::table('m_client', fn (Blueprint $t) => $t->dropColumn('trial_ends_at'));
        }
    }
};
