<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_ticket_data', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 40)->index();
            $table->json('data_master');
            $table->json('data_detail');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_ticket_data');
    }
};