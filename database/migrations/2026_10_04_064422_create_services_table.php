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
        Schema::create('t_service', function (Blueprint $table) {
            $table->id();
            $table->string('trans_number', 25)->nullable();
            $table->string('service_period', 25)->nullable();
            $table->foreignId('ser_list_id')->nullable()->constrained('m_service_list')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('ser_fee_id')->nullable()->constrained('m_service_fee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('ca_id')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('qty')->default(1);
            $table->decimal('service_fee', 12,2)->default(0.00);
            $table->decimal('surcharge', 12,2)->default(0.00);
            $table->decimal('less', 12,2)->default(0.00);
            $table->decimal('total_service_fee', 12,2)->default(0.00);
            $table->decimal('inherited_vat', 12,2)->default(0.00);
            $table->decimal('inherited_wh', 12,2)->default(0.00);
            $table->decimal('inherited_discount_amount', 12,2)->default(0.00);
            $table->tinyInteger('d_vat')->default(0);
            $table->tinyInteger('d_witholding')->default(0);
            $table->tinyInteger('d_less_sc')->default(0);
            $table->tinyInteger('d_less_pwd')->default(0);
            $table->tinyInteger('d_less_sp')->default(0);
            $table->tinyInteger('d_less_others')->default(0);
            $table->decimal('less_other_percentage', 12,2)->default(0.00);
            $table->dateTime('prep_target_date')->nullable();
            $table->tinyInteger('is_surcharge')->default(0);
            $table->tinyInteger('is_interest')->default(0);
            $table->tinyInteger('is_paid')->default(0);
            $table->tinyInteger('is_print')->default(0);
            $table->string('note', 255)->nullable();
            $table->integer('payment_id')->nullable();
            $table->foreignId('return_status_id')->nullable()->constrained('m_payment_return_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('invoice_status_id')->nullable()->constrained('m_invoice_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_service');
    }
};
