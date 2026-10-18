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
        Schema::create('m_accounting_bank_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abaid')->nullable()->constrained('m_accounting_bank_name')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('com_id')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('account_name', 50);
            $table->string('account_number', 50);
            $table->string('contact_num', 50);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_accounting_bank_account');
    }
};
