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
        Schema::create('t_mobile_transaction', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 40);
            $table->string('trans_type', 15);
            $table->string('account_code', 15);
            $table->string('reference_no', 40);
            $table->tinyInteger('trans_status')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_daid')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_auid')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_mobile_transaction');
    }
};
