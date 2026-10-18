<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual Beg. Balance / Adjusting Entries typed on the Working Trial Balance
        Schema::create('t_wtb_manual', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caid');              // branch (m_client_account.id)
            $table->string('period', 4);                     // year, e.g. 2026
            $table->string('account_code', 20);
            $table->decimal('beg_debit', 18, 4)->nullable(); // null = use t_beg_balance_accounts
            $table->decimal('beg_credit', 18, 4)->nullable();
            $table->decimal('adj_debit', 18, 4)->default(0);
            $table->decimal('adj_credit', 18, 4)->default(0);
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();

            $table->unique(['caid', 'period', 'account_code']);
        });

        // Beginning balances per account (old tbl2_beg_balances_accounts)
        Schema::create('t_beg_balance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 20);
            $table->string('declared_month', 7);                       // YYYY-MM
            $table->decimal('assets_expenses', 18, 4)->default(0);       // debit side
            $table->decimal('liability_equity_income', 18, 4)->default(0); // credit side
            $table->unsignedBigInteger('added_by');
            $table->timestamps();

            $table->index(['added_by', 'declared_month']);
            $table->index('account_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_beg_balance_accounts');
        Schema::dropIfExists('t_wtb_manual');
    }
};