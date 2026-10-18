<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task → Banking → Petty Cash (imprest system)
 *   t_petty_cash_fund          one row per fund (name, custodian, petty cash GL account, fund amount)
 *   t_petty_cash_voucher       disbursements paid out of the fund (PCV)
 *   t_petty_cash_replenishment fund set-up / increase / decrease and replenishments, each linked to a General Journal entry
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_petty_cash_fund')) {
            Schema::create('t_petty_cash_fund', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);                               // e.g. Main Office Petty Cash
                $t->string('custodian', 120)->nullable();
                $t->string('account_code', 20);                        // Petty Cash Fund GL account
                $t->decimal('fund_amount', 15, 2)->default(0);         // imprest amount
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('added_by')->index();           // user client id
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_petty_cash_voucher')) {
            Schema::create('t_petty_cash_voucher', function (Blueprint $t) {
                $t->id();
                $t->string('pcv_no', 30)->unique();                    // PCV-261004-001
                $t->unsignedBigInteger('fund_id')->index();
                $t->date('pcv_date');
                $t->string('declared_month', 7)->nullable();           // Y-m
                $t->string('payee', 150);
                $t->string('particulars', 255)->nullable();
                $t->string('reference', 100)->nullable();              // OR / receipt no.
                $t->string('expense_account', 20);                     // GL account to debit on replenishment
                $t->decimal('amount', 15, 2)->default(0);
                $t->string('status', 15)->default('open');             // open | replenished | void
                $t->string('replenish_no', 30)->nullable()->index();   // t_petty_cash_replenishment.replenish_no
                $t->unsignedBigInteger('added_by')->index();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->timestamps();
                $t->index(['added_by', 'fund_id', 'status']);
            });
        }

        if (!Schema::hasTable('t_petty_cash_replenishment')) {
            Schema::create('t_petty_cash_replenishment', function (Blueprint $t) {
                $t->id();
                $t->string('replenish_no', 30)->unique();              // PCR-… / PCF-…
                $t->unsignedBigInteger('fund_id')->index();
                $t->string('type', 15)->default('replenish');          // fund (set-up / change) | replenish
                $t->date('txn_date');
                $t->string('declared_month', 7)->nullable();
                $t->decimal('amount', 15, 2)->default(0);              // + into the fund, − out of the fund (fund decrease)
                $t->string('bank_account', 20)->nullable();            // cash / bank GL account
                $t->string('reference', 100)->nullable();              // check no., etc.
                $t->string('remarks', 255)->nullable();
                $t->string('jv_no', 60)->nullable();                   // t_general_journal.jv_no
                $t->unsignedBigInteger('added_by')->index();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_petty_cash_replenishment');
        Schema::dropIfExists('t_petty_cash_voucher');
        Schema::dropIfExists('t_petty_cash_fund');
    }
};
