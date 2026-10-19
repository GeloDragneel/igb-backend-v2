<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile app tables that had no new-schema version yet:
 *   m_biometric_register        (old tbl2_biometric_register)
 *   t_collection_deposit_file   (old tbl2_collection_deposit_file)
 *   t_chat_room / t_chat_room_member / t_chat_room_message / t_chat_read_receipt / t_chat_attachment
 *                               (old tbl2_chat_room / _room_member / _message / _read_receipt / _attachment)
 *   t_chat_message              (old tbl2_chat_messages – direct messages)
 *   t_chat_group / t_chat_group_member / t_chat_group_message / t_chat_group_read
 *                               (old tbl2_chat_groups / _group_members / _group_messages / _group_read)
 * user_type = User1 (client, m_user_client) | User2 (staff, m_user_staff) | User3 (client staff, m_user_client_staff)
 * Each table is only created when it doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_biometric_register')) {
            Schema::create('m_biometric_register', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('ca_id')->index();          // m_client_account.id
                $t->unsignedBigInteger('user_id');                 // m_user_client.id or m_user_staff.id
                $t->string('user_type', 10)->default('client');    // client | staff
                $t->text('fingerprint_data');
                $t->timestamps();
                $t->index(['user_id', 'user_type']);
            });
        }

        if (!Schema::hasTable('t_collection_deposit_file')) {
            Schema::create('t_collection_deposit_file', function (Blueprint $t) {
                $t->id();
                $t->string('bill_type', 20);                       // Collection | Deposit
                $t->string('table_name', 60)->default('t_payment');
                $t->unsignedBigInteger('table_id')->index();       // t_payment.id
                $t->string('filename', 150);                       // S3 key inside Billing_Collection / Billing_Deposit
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_chat_room')) {
            Schema::create('t_chat_room', function (Blueprint $t) {
                $t->id();
                $t->string('room_name', 150)->nullable();
                $t->string('created_by', 30);
                $t->string('created_by_type', 10);
                $t->tinyInteger('is_group')->default(0);
                $t->tinyInteger('is_active')->default(1);
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_chat_room_member')) {
            Schema::create('t_chat_room_member', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('room_id')->index();        // t_chat_room.id
                $t->string('user_id', 30);
                $t->string('user_type', 10);
                $t->string('full_name', 200)->nullable();
                $t->dateTime('joined_at')->nullable();
                $t->tinyInteger('is_active')->default(1);
                $t->index(['user_id', 'user_type']);
            });
        }

        if (!Schema::hasTable('t_chat_room_message')) {
            Schema::create('t_chat_room_message', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('room_id')->index();
                $t->string('sender_id', 30);
                $t->string('user_type', 10);
                $t->string('full_name', 200)->nullable();
                $t->text('message')->nullable();
                $t->tinyInteger('has_attachment')->default(0);
                $t->tinyInteger('is_deleted')->default(0);
                $t->dateTime('sent_at')->nullable();
            });
        }

        if (!Schema::hasTable('t_chat_read_receipt')) {
            Schema::create('t_chat_read_receipt', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('message_id')->index();     // t_chat_room_message.id
                $t->string('user_id', 30);
                $t->string('user_type', 10);
                $t->dateTime('read_at')->nullable();
            });
        }

        if (!Schema::hasTable('t_chat_attachment')) {
            Schema::create('t_chat_attachment', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('message_id')->index();     // t_chat_room_message.id
                $t->string('file_name', 255);
                $t->string('file_path', 500);
                $t->string('file_type', 50)->nullable();
                $t->unsignedBigInteger('file_size')->default(0);
                $t->string('mime_type', 100)->nullable();
                $t->tinyInteger('is_deleted')->default(0);
            });
        }

        if (!Schema::hasTable('t_chat_message')) {
            Schema::create('t_chat_message', function (Blueprint $t) {
                $t->id();
                $t->string('sender_id', 30);
                $t->string('sender_type', 10);
                $t->string('receiver_id', 30);
                $t->string('receiver_type', 10);
                $t->text('message')->nullable();
                $t->string('attachment', 500)->nullable();         // URL
                $t->string('attachment_name', 255)->nullable();
                $t->string('attachment_type', 100)->nullable();
                $t->dateTime('sent_at')->nullable();
                $t->tinyInteger('is_read')->default(0);
                $t->index(['sender_id', 'sender_type']);
                $t->index(['receiver_id', 'receiver_type']);
            });
        }

        if (!Schema::hasTable('t_chat_group')) {
            Schema::create('t_chat_group', function (Blueprint $t) {
                $t->id();
                $t->string('group_name', 150);
                $t->string('created_by', 30);
                $t->string('created_by_type', 10)->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_chat_group_member')) {
            Schema::create('t_chat_group_member', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('group_id');
                $t->string('user_id', 30);
                $t->string('user_type', 10);
                $t->tinyInteger('is_admin')->default(0);
                $t->string('added_by', 30)->nullable();
                $t->dateTime('joined_at')->nullable();
                $t->unique(['group_id', 'user_id', 'user_type']);
            });
        }

        if (!Schema::hasTable('t_chat_group_message')) {
            Schema::create('t_chat_group_message', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('group_id')->index();
                $t->string('sender_id', 30);
                $t->string('sender_type', 10)->nullable();
                $t->string('sender_name', 200)->nullable();
                $t->text('message')->nullable();
                $t->string('attachment', 500)->nullable();
                $t->string('attachment_name', 255)->nullable();
                $t->string('attachment_type', 100)->nullable();
                $t->dateTime('sent_at')->nullable();
            });
        }

        if (!Schema::hasTable('t_chat_group_read')) {
            Schema::create('t_chat_group_read', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('group_id');
                $t->unsignedBigInteger('message_id');
                $t->string('user_id', 30);
                $t->string('user_type', 10)->nullable();
                $t->dateTime('read_at')->nullable();
                $t->unique(['message_id', 'user_id', 'user_type']);
            });
        }
    }

    public function down(): void
    {
        foreach (['t_chat_group_read', 't_chat_group_message', 't_chat_group_member', 't_chat_group', 't_chat_message', 't_chat_attachment', 't_chat_read_receipt', 't_chat_room_message', 't_chat_room_member', 't_chat_room', 't_collection_deposit_file', 'm_biometric_register'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};