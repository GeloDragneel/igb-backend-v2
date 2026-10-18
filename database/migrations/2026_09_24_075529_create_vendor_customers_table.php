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
        Schema::create('m_vendor_customer', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('tin', 20)->nullable();
            $table->integer('tax_type')->nullable();
            $table->integer('branch_type')->nullable();
            $table->string('branch_code', 10)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city_country', 255)->nullable();
            $table->foreignId('entry_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('link_by_caid')->nullable()->constrained('m_client_account')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('user_type', 20)->nullable();
            $table->tinyInteger('is_default')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_vendor_customer');
    }
};
