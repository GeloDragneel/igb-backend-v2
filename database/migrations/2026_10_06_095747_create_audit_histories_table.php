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
        Schema::create('t_audit_history', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 50);
            $table->foreignId('auditor_id')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('audit_status_id')->nullable()->constrained('m_audit_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('remarks', 255);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_audit_history');
    }
};
