<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports → BIR Return Summary
 * one row per client + year: what was typed on the Summary of Returns and Audit tabs
 * (blank cells are not saved – they show the system amount)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_bir_return_summary')) return;
        Schema::create('t_bir_return_summary', function (Blueprint $t) {
            $t->id();
            $t->string('client_num', 60);
            $t->unsignedSmallInteger('year');
            $t->longText('summary')->nullable();     // JSON
            $t->longText('audit')->nullable();       // JSON
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->string('updated_by_name', 120)->nullable();
            $t->timestamps();
            $t->unique(['client_num', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_bir_return_summary');
    }
};
