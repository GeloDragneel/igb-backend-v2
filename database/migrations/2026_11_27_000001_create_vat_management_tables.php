<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Monitoring → VAT Management Dashboard: adjustment logs + status per client and declared month
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_vat_management_logs')) {
            Schema::create('t_vat_management_logs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('client_id')->index();
                $t->unsignedBigInteger('employee_id')->default(0);
                $t->char('declared_month', 7)->index();                     // YYYY-MM
                $t->string('trans_type', 20);                               // Sales | Purchase
                $t->decimal('actual_net_vat_payable', 15, 2)->default(0);
                $t->decimal('adjusted_net_vat_payable', 15, 2)->default(0);
                $t->decimal('amount_to_adjust', 15, 2)->default(0);
                $t->text('remarks')->nullable();
                $t->timestamp('timestamp')->useCurrent();
                $t->index(['client_id', 'declared_month']);
            });
        }
        if (!Schema::hasTable('t_vat_management_status')) {
            Schema::create('t_vat_management_status', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('client_id');
                $t->unsignedBigInteger('employee_id')->default(0);
                $t->char('declared_month', 7);
                $t->string('status', 20)->default('Pending');               // Pending | Resolved | Declined
                $t->timestamp('timestamp')->useCurrent();
                $t->unique(['client_id', 'declared_month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_vat_management_status');
        Schema::dropIfExists('t_vat_management_logs');
    }
};
