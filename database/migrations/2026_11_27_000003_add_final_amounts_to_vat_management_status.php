<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VAT Management Dashboard: on Resolve the auditor picks one adjustment log → its amounts become final for that client + month
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_vat_management_status', function (Blueprint $t) {
            if (!Schema::hasColumn('t_vat_management_status', 'final_log_id')) {
                $t->unsignedBigInteger('final_log_id')->nullable();                 // t_vat_management_logs.id
                $t->string('final_trans_type', 20)->nullable();                     // Sales | Purchase
                $t->decimal('final_actual_net_vat_payable', 15, 2)->nullable();
                $t->decimal('final_adjusted_net_vat_payable', 15, 2)->nullable();
                $t->decimal('final_amount_to_adjust', 15, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_vat_management_status', function (Blueprint $t) {
            $t->dropColumn(['final_log_id', 'final_trans_type', 'final_actual_net_vat_payable', 'final_adjusted_net_vat_payable', 'final_amount_to_adjust']);
        });
    }
};
