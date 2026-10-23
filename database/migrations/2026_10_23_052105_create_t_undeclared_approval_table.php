<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_undeclared_approval')) return;
        Schema::create('t_undeclared_approval', function (Blueprint $t) {
            $t->id();
            $t->string('approval_no', 30)->unique();
            $t->unsignedBigInteger('client_id')->index();
            $t->string('period_from', 7);
            $t->string('period_to', 7);
            $t->longText('old_data');
            $t->longText('new_data');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_undeclared_approval');
    }
};