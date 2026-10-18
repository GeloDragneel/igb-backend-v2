<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR access is given to LOGINS (m_user_staff), not employees — logins are not always linked to an employee.
 *   m_hr_access + user_staff_id · employee_id becomes optional · unique (user_staff_id, role)
 *   existing rows get their login when m_user_staff.employee_id points to the employee
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('m_hr_access', 'user_staff_id')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->unsignedBigInteger('user_staff_id')->nullable()->after('id')->comment('m_user_staff.id (the login)');
            });
        }
        if (Schema::hasIndex('m_hr_access', 'm_hr_access_employee_id_role_unique')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->dropUnique('m_hr_access_employee_id_role_unique');
            });
        }
        Schema::table('m_hr_access', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->nullable()->change();
        });
        if (!Schema::hasIndex('m_hr_access', 'm_hr_access_user_staff_id_role_unique')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->unique(['user_staff_id', 'role']);
            });
        }
        if (Schema::hasColumn('m_user_staff', 'employee_id')) {
            foreach (DB::table('m_hr_access')->whereNull('user_staff_id')->whereNotNull('employee_id')->get() as $r) {
                $login = DB::table('m_user_staff')->where('employee_id', $r->employee_id)->value('id');
                if ($login && !DB::table('m_hr_access')->where('user_staff_id', $login)->where('role', $r->role)->exists()) {
                    DB::table('m_hr_access')->where('id', $r->id)->update(['user_staff_id' => $login]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('m_hr_access', 'm_hr_access_user_staff_id_role_unique')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->dropUnique('m_hr_access_user_staff_id_role_unique');
            });
        }
        if (Schema::hasColumn('m_hr_access', 'user_staff_id')) {
            Schema::table('m_hr_access', function (Blueprint $t) {
                $t->dropColumn('user_staff_id');
            });
        }
    }
};