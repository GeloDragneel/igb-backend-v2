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
        Schema::create('t_recurring_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ca_id')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('ser_list_id')->nullable()->constrained('m_service_list')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('ser_fee_id')->nullable()->constrained('m_service_fee')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('eo_version');
            $table->integer('qty')->default(1);
            $table->decimal('service_fee', 12,2)->default(0.00);
            $table->decimal('surcharge', 12,2)->default(0.00);
            $table->decimal('less', 12,2)->default(0.00);
            $table->decimal('total_service_fee', 12,2)->default(0.00);
            $table->tinyInteger('d_vat')->default(0);
            $table->tinyInteger('d_witholding')->default(0);
            $table->tinyInteger('d_less_sc')->default(0);
            $table->tinyInteger('d_less_pwd')->default(0);
            $table->tinyInteger('d_less_sp')->default(0);
            $table->tinyInteger('d_less_others')->default(0);
            $table->decimal('less_other_percentage', 12,2)->default(0.00);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_recurring_service');
    }
};
