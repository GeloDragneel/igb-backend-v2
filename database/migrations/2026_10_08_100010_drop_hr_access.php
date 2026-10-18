<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** HR access was replaced by the supervisor override on payroll approval: drop m_hr_access */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('m_hr_access');
    }

    public function down(): void
    {
        // not restored
    }
};