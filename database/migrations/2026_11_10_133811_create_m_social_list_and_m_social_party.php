<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the apps a vendor can be reached on
        if (!Schema::hasTable('m_social_list')) {
            Schema::create('m_social_list', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50)->unique();
                $table->timestamps();
            });
        }

        $now = now();
        foreach (['Email', 'Viber', 'Messenger', 'WeChat', 'WhatsApp', 'Telegram'] as $name) {
            if (!DB::table('m_social_list')->where('name', $name)->exists()) {
                DB::table('m_social_list')->insert(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // a vendor's address / number / username per app (several per app allowed)
        if (!Schema::hasTable('m_social_party')) {
            Schema::create('m_social_party', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('vendor_id')->index();        // m_vendor_customer.id
                $table->unsignedBigInteger('social_list_id')->index();   // m_social_list.id
                $table->string('value', 255);
                $table->timestamps();
                $table->index(['vendor_id', 'social_list_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m_social_party');
        Schema::dropIfExists('m_social_list');
    }
};