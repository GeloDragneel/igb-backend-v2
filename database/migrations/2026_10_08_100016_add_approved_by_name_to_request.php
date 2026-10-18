<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** who approved / rejected / cancelled an overtime or leave (the login name, also when the login is not linked to an employee) */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['t_overtime', 't_leave_request'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'approved_by_name')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('approved_by_name', 150)->nullable()->after('approved_by')->comment('login name of who decided');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['t_overtime', 't_leave_request'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'approved_by_name')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('approved_by_name'));
            }
        }
    }
};