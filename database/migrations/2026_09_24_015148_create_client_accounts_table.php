<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_client_account', function (Blueprint $table) {
            $table->id();
            $table->string('client_num', 60)->nullable();
            $table->string('branch_code', 10)->nullable();
            $table->string('branch_type', 20)->nullable();        // "Head Office" / "Branch"
            $table->string('trade_name', 255)->nullable();
            $table->string('tax_registration', 30)->nullable();   // "Non-VAT Registered"
            $table->foreignId('nob_id')->nullable()->constrained('m_nature_of_business')->cascadeOnUpdate()->restrictOnDelete();

            // Address
            $table->string('street', 255)->nullable();
            $table->foreignId('brgy_id')->nullable()->constrained('m_barangay')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('m_city_mun')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('m_province')->cascadeOnUpdate()->restrictOnDelete();

            // Contact
            $table->string('phone_num', 15)->nullable();
            $table->string('tell_num', 15)->nullable();
            $table->string('email_address', 50)->nullable();
            $table->string('rdo', 5)->nullable();

            $table->foreignId('status_id')->nullable()->constrained('m_client_account_status')->cascadeOnUpdate()->restrictOnDelete();

            // Assigned employees – columns only, foreign keys are added after m_employee exists
            $table->unsignedBigInteger('assigned_daid')->nullable();
            $table->unsignedBigInteger('assigned_auid')->nullable();

            $table->tinyInteger('is_active')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_client_account');
    }
};