<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger: chat theme (bubble colour) + quick emoji (the 👍 button) per chat – everyone in the chat sees it.
 * Only adds 2 nullable columns – no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_msg_conversation', function (Blueprint $t) {
            if (!Schema::hasColumn('t_msg_conversation', 'theme')) $t->string('theme', 20)->nullable()->after('direct_key');
            if (!Schema::hasColumn('t_msg_conversation', 'emoji')) $t->string('emoji', 16)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('t_msg_conversation', function (Blueprint $t) {
            if (Schema::hasColumn('t_msg_conversation', 'emoji')) $t->dropColumn('emoji');
            if (Schema::hasColumn('t_msg_conversation', 'theme')) $t->dropColumn('theme');
        });
    }
};