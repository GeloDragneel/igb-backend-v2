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
        Schema::create('m_delared_month_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ca_id')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->char('declared_month', 7);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_delared_month_settings');
    }
};
