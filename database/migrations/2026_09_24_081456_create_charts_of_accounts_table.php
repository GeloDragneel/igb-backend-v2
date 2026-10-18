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
        Schema::create('m_charts_of_account', function (Blueprint $table) {
            $table->id();
            $table->string('root_name', 25)->nullable();
            $table->string('account_code', 60)->nullable();
            $table->string('account_name', 60)->nullable();
            $table->string('account_type', 25)->nullable();
            $table->string('description', 100)->nullable();
            $table->tinyInteger('is_for_paid')->default(0);
            $table->tinyInteger('is_sales')->default(0);
            $table->tinyInteger('is_purchases')->default(0);
            $table->tinyInteger('is_expenses')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('m_charts_of_account');
    }
};
