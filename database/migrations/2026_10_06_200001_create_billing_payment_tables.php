<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing → SOA dashboard (old Billing/Dashboard.php)
 *   t_payment                 payments on an SOA (old tbl_payment)
 *   t_service_override_log    supervisor overrides on a service (old tbl_service_override_log)
 *   m_payment_type / m_payment_cheque_type / m_payment_receipt_type / m_payment_col_type / m_payment_return_status
 * Each table is only created when it doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lookup = function (string $table, array $rows, bool $active = false) {
            if (Schema::hasTable($table)) return;
            Schema::create($table, function (Blueprint $t) use ($active) {
                $t->id();
                $t->string('name', 100);
                if ($active) $t->tinyInteger('is_active')->default(1);
                $t->timestamps();
            });
            foreach ($rows as $id => $name) {
                DB::table($table)->insert(['id' => $id, 'name' => $name] + ($active ? ['is_active' => 1] : []) + ['created_at' => now(), 'updated_at' => now()]);
            }
        };
        // defaults = the usual old values; the copy SQL replaces them with your real lists
        $lookup('m_payment_type', [1 => 'Cash', 2 => 'Cheque', 3 => 'Bank Transfer', 4 => 'E-Wallet', 5 => 'Online']);
        $lookup('m_payment_cheque_type', [1 => 'On-date', 2 => 'Post-dated']);
        $lookup('m_payment_receipt_type', [1 => 'Acknowledgement Receipt', 2 => 'Acknowledgement Payment', 3 => 'Official Receipt', 4 => 'Collection Receipt']);
        $lookup('m_payment_col_type', [1 => 'PF & Other Charges', 2 => 'Returns', 4 => 'Non-recurring / EO'], true);
        $lookup('m_payment_return_status', [1 => 'Pending', 2 => 'Filed', 3 => 'Paid']);

        if (!Schema::hasTable('t_payment')) {
            Schema::create('t_payment', function (Blueprint $t) {
                $t->id();                                                    // PaymentID
                $t->string('trans_number', 60)->nullable()->index();         // SOA #
                $t->string('service_period', 60)->nullable();
                $t->unsignedBigInteger('service_id')->nullable()->index();   // t_service.id
                $t->unsignedBigInteger('ca_id')->nullable()->index();        // m_client_account.id
                $t->string('client_num', 30)->nullable();
                $t->dateTime('payment_date')->nullable();
                $t->unsignedBigInteger('payment_type_id')->nullable();       // m_payment_type
                $t->unsignedBigInteger('cheque_type_id')->nullable();        // m_payment_cheque_type
                $t->date('cheque_type_date')->nullable();
                $t->string('details1', 150)->nullable();                     // bank / wallet
                $t->string('details2', 150)->nullable();                     // reference / cheque #
                $t->unsignedBigInteger('payment_col_type_id')->nullable();   // m_payment_col_type
                $t->unsignedBigInteger('receipt_type_id')->nullable();       // m_payment_receipt_type
                $t->string('ar_number', 60)->nullable();
                $t->string('ap_number', 60)->nullable();
                $t->string('or_number', 60)->nullable();
                $t->decimal('amount', 15, 2)->default(0);
                $t->decimal('co_amount', 15, 2)->default(0);
                $t->decimal('total_gross_amount_cal', 15, 2)->default(0);
                $t->decimal('total_amount', 15, 2)->default(0);
                $t->integer('qty')->default(1);
                $t->date('due_date')->nullable();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();               // employee who encoded
                // deposit tracking
                $t->integer('assigned_bank')->default(0);
                $t->unsignedBigInteger('assigned_bank_by')->nullable();
                $t->date('assigned_bank_date')->nullable();
                $t->string('dep_slip_number', 60)->nullable();
                $t->unsignedBigInteger('prepared_by_id')->nullable();
                $t->date('prepared_date')->nullable();
                $t->tinyInteger('prepared')->default(0);
                $t->tinyInteger('deposited')->default(0);
                $t->unsignedBigInteger('deposited_by_id')->nullable();
                $t->date('date_deposited')->nullable();
                $t->unsignedBigInteger('payment_status_id')->nullable();
                $t->string('remarks', 500)->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_service_override_log')) {
            Schema::create('t_service_override_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('service_id')->index();               // t_service.id
                $t->string('action', 30)->nullable();                        // VOID | CANCEL | PRICE
                $t->text('remarks')->nullable();
                $t->unsignedBigInteger('override_by')->nullable();           // m_employee.id (who asked)
                $t->unsignedBigInteger('approved_by')->nullable();           // m_user_staff.id (supervisor)
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['t_service_override_log', 't_payment', 'm_payment_return_status', 'm_payment_col_type', 'm_payment_receipt_type', 'm_payment_cheque_type', 'm_payment_type'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
