<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger: forwarded messages show a small "Forwarded" label.
 * Only adds 1 column (default 0) – no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_msg_message', 'forwarded')) {
            Schema::table('t_msg_message', fn (Blueprint $t) => $t->boolean('forwarded')->default(false)->after('reply_to_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_msg_message', 'forwarded')) {
            Schema::table('t_msg_message', fn (Blueprint $t) => $t->dropColumn('forwarded'));
        }
    }
};