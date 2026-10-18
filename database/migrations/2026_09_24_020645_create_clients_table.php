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
        Schema::create('m_client', function (Blueprint $table) {
            $table->id();
            $table->string('client_num', 30)->nullable();
            $table->integer('max_num')->nullable();
            $table->string('last_name', 60)->nullable();
            $table->string('first_name', 60)->nullable();
            $table->string('middle_name', 60)->nullable();
            $table->string('suffix', 10)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birthdate')->nullable();
            $table->foreignId('civ_stat_id')->nullable()->constrained('m_civil_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('phone_num', 15)->nullable();
            $table->string('tell_num', 15)->nullable();
            $table->string('email_address', 50)->nullable();
            $table->string('street', 255)->nullable();
            $table->foreignId('brgy_id')->nullable()->constrained('m_barangay')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('m_city_mun')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('province_id')->nullable()->constrained('m_province')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('m_client_account_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('business_ownership_type_id')->nullable()->constrained('m_business_ownership_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('business_ownership_type_detail_id')->nullable()->constrained('m_business_ownership_type_detail')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('classification_id')->nullable()->constrained('m_classification')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('industries_id')->nullable()->constrained('m_industries')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('compliance_ids', 50)->nullable();
            $table->date('date_of_engagement')->nullable();
            $table->date('date_of_disengagement')->nullable();
            $table->date('date_started')->nullable();
            $table->date('date_registered')->nullable();
            $table->foreignId('added_by_caid')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('return_type', 50)->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_client');
    }
};