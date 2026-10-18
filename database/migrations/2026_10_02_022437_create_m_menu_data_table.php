<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_menu_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('root_name')->default(0);
            $table->string('module_name', 150);
            $table->string('url', 150);
            $table->text('svg');
            $table->tinyInteger('is_target_blank')->default(0);
            $table->tinyInteger('is_master')->default(0);
            $table->integer('rank')->nullable();
            $table->timestamps();
            $table->index('root_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_menu_data');
    }
};