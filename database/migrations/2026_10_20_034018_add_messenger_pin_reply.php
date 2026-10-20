<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger: pin chats, reply to a message, react to a message.
 *   t_msg_member.pinned_at      my pinned chats (stay on top of my list)
 *   t_msg_message.reply_to_id   the message this one replies to
 *   t_msg_reaction              one reaction per person per message (❤️ 😆 😮 😢 😠 👍 …)
 * Only adds 2 nullable columns and 1 new table – no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_msg_member', 'pinned_at')) {
            Schema::table('t_msg_member', fn (Blueprint $t) => $t->timestamp('pinned_at')->nullable()->after('last_read_id'));
        }
        if (!Schema::hasColumn('t_msg_message', 'reply_to_id')) {
            Schema::table('t_msg_message', fn (Blueprint $t) => $t->unsignedBigInteger('reply_to_id')->nullable()->after('employee_id'));
        }
        if (!Schema::hasTable('t_msg_reaction')) {
            Schema::create('t_msg_reaction', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('message_id');
                $t->unsignedBigInteger('employee_id');
                $t->string('emoji', 16);
                $t->timestamps();
                $t->unique(['message_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_msg_reaction');
        if (Schema::hasColumn('t_msg_message', 'reply_to_id')) Schema::table('t_msg_message', fn (Blueprint $t) => $t->dropColumn('reply_to_id'));
        if (Schema::hasColumn('t_msg_member', 'pinned_at')) Schema::table('t_msg_member', fn (Blueprint $t) => $t->dropColumn('pinned_at'));
    }
};