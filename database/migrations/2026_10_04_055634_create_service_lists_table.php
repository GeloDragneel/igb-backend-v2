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
        Schema::create('m_service_list', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->foreignId('item_tax_type_id')->nullable()->constrained('m_item_tax_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('item_tax_desc_id')->nullable()->constrained('m_item_tax_type_desc')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('ser_type_id')->nullable()->constrained('m_service_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('rep_type_id')->nullable()->constrained('m_reporting_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('m_submission')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('m_institution')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('trans_type_id')->nullable()->constrained('m_transaction_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_service_list');
    }
};
