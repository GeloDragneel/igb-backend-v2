<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger: chat background (behind the messages) per chat – everyone in the chat sees it.
 * Only adds 1 nullable column – no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_msg_conversation', 'background')) {
            Schema::table('t_msg_conversation', fn (Blueprint $t) => $t->string('background', 20)->nullable()->after('emoji'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_msg_conversation', 'background')) {
            Schema::table('t_msg_conversation', fn (Blueprint $t) => $t->dropColumn('background'));
        }
    }
};