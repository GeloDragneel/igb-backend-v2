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
        Schema::create('t_transaction', function (Blueprint $table) {
            $table->id();
            $table->string('trans_number', 25)->nullable();
            $table->integer('eo_version')->nullable();
            $table->foreignId('trans_type_id')->nullable()->constrained('m_transaction_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_period', 25)->nullable();
            $table->foreignId('ca_id')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('ass_to_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('prepared_by_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('date_start')->nullable();
            $table->tinyInteger('has_prepared')->default(0);
            $table->tinyInteger('has_printed')->default(0);
            $table->tinyInteger('is_lock')->default(0);
            $table->tinyInteger('is_paid')->default(0);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_transaction');
    }
};
