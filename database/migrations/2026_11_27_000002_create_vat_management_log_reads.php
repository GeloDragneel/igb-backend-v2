<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VAT Management Dashboard: who has read which adjustment log (read / unread per user)
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_vat_management_log_reads')) {
            Schema::create('t_vat_management_log_reads', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('log_id');                 // t_vat_management_logs.id
                $t->unsignedBigInteger('employee_id');            // m_employee.id of the reader
                $t->timestamp('read_at')->useCurrent();
                $t->unique(['log_id', 'employee_id']);
                $t->index('employee_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_vat_management_log_reads');
    }
};
