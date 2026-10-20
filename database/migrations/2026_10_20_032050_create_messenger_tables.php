<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger (employees only: UserStaff + UserClientStaff → m_employee.id).
 *   t_msg_conversation   a direct chat (2 people) or a group
 *   t_msg_member         who is in it + last message they have seen
 *   t_msg_message        text / image / system ("Ana created the group")
 *   t_msg_presence       last time each employee had the app open (online = within 1 minute)
 * Only creates new tables – no existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_msg_conversation')) {
            Schema::create('t_msg_conversation', function (Blueprint $t) {
                $t->id();
                $t->boolean('is_group')->default(false);
                $t->string('name', 80)->nullable();                 // groups only
                $t->string('direct_key', 41)->nullable()->unique(); // "12-45" (smaller employee id first) for direct chats
                $t->unsignedBigInteger('created_by');               // m_employee.id
                $t->timestamp('last_message_at')->nullable()->index();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_msg_member')) {
            Schema::create('t_msg_member', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id');
                $t->unsignedBigInteger('employee_id')->index();
                $t->boolean('is_admin')->default(false);
                $t->unsignedBigInteger('last_read_id')->default(0);  // t_msg_message.id
                $t->timestamp('left_at')->nullable();
                $t->timestamps();
                $t->unique(['conversation_id', 'employee_id']);
            });
        }

        if (!Schema::hasTable('t_msg_message')) {
            Schema::create('t_msg_message', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('conversation_id');
                $t->unsignedBigInteger('employee_id')->nullable();   // null = system message
                $t->string('type', 10)->default('text');            // text | image | system
                $t->text('body')->nullable();
                $t->string('file_key', 255)->nullable();            // S3 Messenger/<conversation>/<uuid>.<ext>
                $t->string('file_name', 255)->nullable();
                $t->unsignedInteger('file_size')->nullable();
                $t->timestamp('deleted_at')->nullable();             // unsent
                $t->timestamps();
                $t->index(['conversation_id', 'id']);
            });
        }

        if (!Schema::hasTable('t_msg_presence')) {
            Schema::create('t_msg_presence', function (Blueprint $t) {
                $t->unsignedBigInteger('employee_id')->primary();
                $t->timestamp('last_seen_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_msg_presence');
        Schema::dropIfExists('t_msg_message');
        Schema::dropIfExists('t_msg_member');
        Schema::dropIfExists('t_msg_conversation');
    }
};