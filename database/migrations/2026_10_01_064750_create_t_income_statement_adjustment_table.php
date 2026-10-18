<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_income_statement_adjustment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caid'); // client account
            $table->char('declared_month_from', 7);
            $table->char('declared_month_to', 7);
            $table->unsignedBigInteger('user_id');
            $table->string('user_name', 150)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->longText('data');                        // the whole adjusted statement (JSON)
            $table->timestamps();

            $table->index(['caid', 'declared_month_from', 'declared_month_to'], 'isa_caid_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_income_statement_adjustment');
    }
};