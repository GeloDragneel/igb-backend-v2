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
        Schema::create('t_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ca_id')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_col_type_id')->nullable()->constrained('m_payment_col_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('receipt_type_id')->nullable()->constrained('m_payment_receipt_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_type_id')->nullable()->constrained('m_payment_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('cheque_type_id')->nullable()->constrained('m_payment_cheque_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('aba_id')->nullable()->constrained('m_accounting_bank_name')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_bank')->nullable()->constrained('m_accounting_bank_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('prepared_by_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('deposited_by_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('t_service')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('deposited_date')->nullable();
            $table->date('prepared_date')->nullable();
            $table->date('assigned_bank_date')->nullable();
            $table->date('cheque_type_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->integer('dep_slip_number')->nullable();
            $table->string('ar_number', 50)->nullable();
            $table->string('ap_number', 50)->nullable();
            $table->string('or_number', 50)->nullable();
            $table->string('service_period', 15)->nullable();
            $table->string('trans_number', 25)->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->decimal('co_amount', 10, 2)->default(0.00);
            $table->decimal('total_gross_amount_cal', 10, 2)->default(0.00);
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->string('details1', 255)->nullable();
            $table->string('details2', 255)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->tinyInteger('is_prepared')->default(0);
            $table->tinyInteger('is_deposited')->default(0);
            $table->integer('payment_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_payment');
    }
};
