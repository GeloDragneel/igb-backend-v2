<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BIR Return → FILE & SUBMITTED: which saved eBIRForms file was filed, and its Expenses bill (paid through Pay Bill)
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_bir_return_filing')) return;
        Schema::create('t_bir_return_filing', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('added_by')->index();          // m_user_client.id (the client's books)
            $t->string('filename', 120);                           // eBIRForms save file
            $t->string('form', 20);                                // 2550Qv2024, 0619E …
            $t->string('return_period', 60)->nullable();
            $t->string('period_from', 7)->nullable();              // Y-m
            $t->string('period_to', 7)->nullable();
            $t->string('invoice_no', 40)->nullable()->index();     // t_purchase_expense_master (Expenses)
            $t->decimal('amount', 15, 2)->default(0);              // the bill (tax payable)
            $t->date('filed_date')->nullable();
            $t->unsignedBigInteger('filed_by')->nullable();
            $t->timestamps();
            $t->unique(['added_by', 'filename']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_bir_return_filing');
    }
};