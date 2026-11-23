<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lending — the IGBLending Excel rules + what the Excel import needs
 *   m_lending_member.code             B0001 … (Borrower ID in the Excel)
 *   t_lending_loan.dues               number of dues (the Excel "Terms")      · due_frequency now also 'weekly'
 *   t_lending_loan.co_maker / collection_method
 *   t_lending_loan.interest_type      now also 'flat' = rate per due on the original amount
 *   t_lending_loan_payment.payment_type / principal_paid / interest_paid
 *   m_lending_setting                 "IGB Loan 2.5% Bi-Monthly (Flat)" as the default loan setting
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_lending_member', function (Blueprint $t) {
            if (!Schema::hasColumn('m_lending_member', 'code')) $t->string('code', 10)->nullable()->unique()->after('id')->comment('Borrower ID, e.g. B0001');
        });

        Schema::table('t_lending_loan', function (Blueprint $t) {
            if (!Schema::hasColumn('t_lending_loan', 'dues')) $t->unsignedSmallInteger('dues')->nullable()->after('term_months')->comment('number of dues (flat loans)');
            if (!Schema::hasColumn('t_lending_loan', 'co_maker')) $t->string('co_maker', 150)->nullable()->after('purpose');
            if (!Schema::hasColumn('t_lending_loan', 'collection_method')) $t->string('collection_method', 30)->nullable()->after('co_maker')->comment('Salary Deduction | OTC | Fund Transfer');
        });

        Schema::table('t_lending_loan_payment', function (Blueprint $t) {
            if (!Schema::hasColumn('t_lending_loan_payment', 'payment_type')) $t->string('payment_type', 40)->nullable()->after('source')->comment('Regular Monthly Dues | Interest Only | Advanced Payment');
            if (!Schema::hasColumn('t_lending_loan_payment', 'principal_paid')) $t->decimal('principal_paid', 14, 2)->default(0)->after('amount');
            if (!Schema::hasColumn('t_lending_loan_payment', 'interest_paid')) $t->decimal('interest_paid', 14, 2)->default(0)->after('principal_paid');
        });

        // the loan setting the Excel uses for almost every loan (2.5% per due, every cut-off, 5% a month penalty)
        if (!DB::table('m_lending_setting')->where('name', 'IGB Loan 2.5% Bi-Monthly (Flat)')->exists()) {
            $default = !DB::table('m_lending_setting')->where('applies_to', 'loan')->where('is_default', 1)->exists();
            DB::table('m_lending_setting')->insert([
                'name' => 'IGB Loan 2.5% Bi-Monthly (Flat)', 'applies_to' => 'loan', 'interest_type' => 'flat',
                'interest_rate' => 0.025, 'fixed_interest' => 0, 'min_amount' => 0, 'max_amount' => null, 'max_term_months' => 24,
                'penalty_rate_per_day' => 0.001667, 'lock_in_days' => 0, 'payroll_deductible' => 1,
                'is_default' => $default ? 1 : 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('m_lending_setting')->where('name', 'IGB Loan 2.5% Bi-Monthly (Flat)')->delete();
        Schema::table('t_lending_loan_payment', function (Blueprint $t) {
            foreach (['payment_type', 'principal_paid', 'interest_paid'] as $c) if (Schema::hasColumn('t_lending_loan_payment', $c)) $t->dropColumn($c);
        });
        Schema::table('t_lending_loan', function (Blueprint $t) {
            foreach (['dues', 'co_maker', 'collection_method'] as $c) if (Schema::hasColumn('t_lending_loan', $c)) $t->dropColumn($c);
        });
        Schema::table('m_lending_member', function (Blueprint $t) {
            if (Schema::hasColumn('m_lending_member', 'code')) { $t->dropUnique(['code']); $t->dropColumn('code'); }
        });
    }
};
