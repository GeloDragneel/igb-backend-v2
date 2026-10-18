<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring → Reconciliation
 *   t_reconciliation_monitoring  (old tbl2_reconciliation_monitoring)  one row per client account + declared month
 *   t_recon_compliance           (old tbl2_recon_compliance)           compliances ticked for that client account + month
 * Each table is only created when it doesn't exist yet; foreign keys are added only when the parent table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_reconciliation_monitoring')) {
            Schema::create('t_reconciliation_monitoring', function (Blueprint $t) {
                $t->id(); // ID
                $t->unsignedBigInteger('client_id'); // ClientID → m_client_account.id
                $t->string('declared_month', 7); // DeclaredMonth  YYYY-MM
                $t->boolean('is_lock')->default(false); // IsLock
                $t->boolean('is_printed')->default(false); // IsPrinted
                $t->unsignedBigInteger('color_code_id')->nullable(); // ColorCodeID
                $t->timestamps(); // DateAdded / DateUpdated

                $t->unique(['client_id', 'declared_month'], 'recon_mon_ca_month_unique');
                $t->index('declared_month');
                if (Schema::hasTable('m_client_account')) {
                    $t->foreign('client_id')->references('id')->on('m_client')->cascadeOnUpdate()->restrictOnDelete();
                }
            });
        }

        if (!Schema::hasTable('t_recon_compliance')) {
            Schema::create('t_recon_compliance', function (Blueprint $t) {
                $t->id(); // ID
                $t->unsignedBigInteger('compliance_id'); // ComplianceID → m_compliance.id
                $t->unsignedBigInteger('client_id'); // ClientID → m_client_account.id
                $t->string('declared_month', 7); // DeclaredMonth  YYYY-MM
                $t->unsignedBigInteger('emp_id')->nullable(); // EmpID → m_employee.id (who ticked it)
                $t->timestamps(); // DateAdded / DateUpdate

                $t->index('declared_month');
                if (Schema::hasTable('m_client_account')) {
                    $t->foreign('client_id')->references('id')->on('m_client')->cascadeOnUpdate()->restrictOnDelete();
                }
                if (Schema::hasTable('m_compliance')) {
                    $t->foreign('compliance_id')->references('id')->on('m_compliance')->cascadeOnUpdate()->restrictOnDelete();
                }
                if (Schema::hasTable('m_employee')) {
                    $t->foreign('emp_id')->references('id')->on('m_employee')->cascadeOnUpdate()->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_recon_compliance');
        Schema::dropIfExists('t_reconciliation_monitoring');
    }
};