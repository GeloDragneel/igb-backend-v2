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
        Schema::create('t_general_journal', function (Blueprint $table) {
            $table->id();
            $table->string('jv_no', 60);
            $table->string('account_code', 15)->nullable();
            $table->integer('subsidiary')->nullable();
            $table->decimal('debit', 10, 2)->default(0.00);
            $table->decimal('credit', 10, 2)->default(0.00);
            $table->string('remarks', 255)->nullable();
            $table->date('transaction_date');
            $table->char('declared_month', 7);
            $table->foreignId('added_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_general_journal');
    }
};
