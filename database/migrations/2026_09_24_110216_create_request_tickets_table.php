<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('t_request_ticket', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 60);
            $table->string('reference_no', 60);
            $table->string('remarks', 255);
            $table->string('module', 40);
            $table->string('action', 10);
            $table->foreignId('request_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->tinyInteger('is_status')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_request_ticket');
    }
};
