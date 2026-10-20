<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messenger: shared GIF library (links pasted by employees) for the GIF tab.
 * Only creates a new table – no existing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_msg_gif')) return;
        Schema::create('t_msg_gif', function (Blueprint $t) {
            $t->id();
            $t->string('url', 500)->unique();
            $t->string('title', 80)->nullable();          // for searching ("congrats", "thank you" …)
            $t->unsignedBigInteger('added_by')->nullable(); // m_employee.id
            $t->unsignedInteger('uses')->default(0);       // most used first
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_msg_gif');
    }
};