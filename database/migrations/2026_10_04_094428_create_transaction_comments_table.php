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
        Schema::create('t_transaction_comment', function (Blueprint $table) {
            $table->id();
            $table->string('comments', 255);
            $table->string('reference_no', 40);
            $table->foreignId('emp_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_transaction_comment');
    }
};
