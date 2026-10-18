<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** employee profile photo: m_employee.photo_path = S3 key (Employee_Profile/{id}-xxxx.jpg) */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('m_employee', 'photo_path')) {
            Schema::table('m_employee', function (Blueprint $t) {
                $t->string('photo_path', 255)->nullable()->comment('S3 key: Employee_Profile/…');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_employee', 'photo_path')) {
            Schema::table('m_employee', function (Blueprint $t) {
                $t->dropColumn('photo_path');
            });
        }
    }
};