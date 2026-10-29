<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('t_income_statement_adjustment', 'user_type')) {
            Schema::table('t_income_statement_adjustment', function (Blueprint $t) {
                $t->string('user_type', 30)->nullable()->after('user_id');   // UserStaff | UserClient | UserClientStaff
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_income_statement_adjustment', 'user_type')) {
            Schema::table('t_income_statement_adjustment', fn (Blueprint $t) => $t->dropColumn('user_type'));
        }
    }
};