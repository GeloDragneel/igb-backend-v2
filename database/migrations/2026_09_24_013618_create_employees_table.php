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
        Schema::create('m_employee', function (Blueprint $table) {
            $table->id();

            // Company (required)
            $table->unsignedBigInteger('com_id');

            // Personal info
            $table->string('last_name', 60)->nullable();
            $table->string('first_name', 60)->nullable();
            $table->string('middle_name', 60)->nullable();
            $table->string('suffix', 60)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('gender', 15)->nullable();
            $table->string('phone_num', 15)->nullable();
            $table->string('tell_num', 15)->nullable();
            $table->string('email_address', 50)->nullable();

            // Address
            $table->string('street', 255)->nullable();
            $table->foreignId('birth_place_id')->nullable()->constrained('m_city_mun')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('brgy_id')->nullable()->constrained('m_barangay')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('m_city_mun')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('m_province')->cascadeOnUpdate()->restrictOnDelete();

            // Other details
            $table->foreignId('civ_stat_id')->nullable()->constrained('m_civil_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('religion_id')->nullable()->constrained('m_religion')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('citizenship_id')->nullable()->constrained('m_citizenship')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('m_user_type')->cascadeOnUpdate()->restrictOnDelete();

            // Employment
            $table->string('biometric_num', 50)->nullable();
            $table->date('date_contact')->nullable();
            $table->date('date_contact_exp')->nullable();
            $table->foreignId('employment_stat_id')->nullable()->constrained('m_employment_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('company_caid')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('work_id')->nullable();
            $table->string('remarks', 255)->nullable();

            // Government IDs
            $table->string('phic_no', 24)->nullable();
            $table->string('hdmf_no', 24)->nullable();
            $table->string('sss_no', 24)->nullable();
            $table->string('tin', 24)->nullable();

            // Pay
            $table->decimal('salary_month', 10, 2)->default(0.00);
            $table->decimal('salary_day', 10, 2)->default(0.00);
            $table->decimal('allowance', 10, 2)->default(0.00);
            $table->string('mccb_acc', 24)->nullable();
            $table->string('shedule', 50)->nullable();

            $table->tinyInteger('is_active')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_employee');
    }
};