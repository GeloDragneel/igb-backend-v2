<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_sales_invoice_master', function (Blueprint $table) {
            $table->string('invalid_reason', 1000)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('t_sales_invoice_master', function (Blueprint $table) {
            $table->dropColumn('invalid_reason');
        });
    }
};