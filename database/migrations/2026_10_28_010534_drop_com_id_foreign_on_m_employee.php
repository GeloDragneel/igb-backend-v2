<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * m_employee.com_id had a foreign key to m_client_account.id, so adding an employee had to make a client account.
 * com_id is only the employee's link to the staff login (m_user_staff.comid) – the foreign key is dropped,
 * existing values stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_employee')) return;
        foreach (Schema::getForeignKeys('m_employee') as $fk) {
            if ($fk['columns'] === ['com_id']) {
                Schema::table('m_employee', fn (Blueprint $t) => $t->dropForeign($fk['name']));
            }
        }
    }

    public function down(): void
    {
        // not put back: new employees' com_id numbers have no m_client_account row
    }
};