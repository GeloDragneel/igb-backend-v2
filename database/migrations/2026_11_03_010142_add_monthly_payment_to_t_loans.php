<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_loans', 'monthly_payment')) {
            Schema::table('t_loans', function (Blueprint $t) {
                $t->decimal('monthly_payment', 15, 2)->nullable()->after('method');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_loans', 'monthly_payment')) {
            Schema::table('t_loans', fn (Blueprint $t) => $t->dropColumn('monthly_payment'));
        }
    }
};