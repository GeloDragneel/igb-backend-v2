<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger: pinned messages (everyone in the chat sees them on top of the chat).
 * Only adds 2 nullable columns – no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_msg_message', function (Blueprint $t) {
            if (!Schema::hasColumn('t_msg_message', 'pinned_at')) $t->timestamp('pinned_at')->nullable()->after('deleted_at');
            if (!Schema::hasColumn('t_msg_message', 'pinned_by')) $t->unsignedBigInteger('pinned_by')->nullable()->after('pinned_at');
        });
    }

    public function down(): void
    {
        Schema::table('t_msg_message', function (Blueprint $t) {
            if (Schema::hasColumn('t_msg_message', 'pinned_by')) $t->dropColumn('pinned_by');
            if (Schema::hasColumn('t_msg_message', 'pinned_at')) $t->dropColumn('pinned_at');
        });
    }
};