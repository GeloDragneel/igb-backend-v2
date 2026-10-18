<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('t_purchase_expense_master', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('m_vendor_customer')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('m_user_client')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('transaction_date');
            $table->char('declared_month', 7);
            $table->string('account_code', 15)->nullable();
            $table->string('document_no', 100)->nullable();
            $table->string('invoice_no', 60)->unique();
            $table->string('currency', 10)->default('PHP');
            $table->decimal('ex_rate', 10,4)->default(1.0000);
            $table->decimal('sub_total', 12,4)->default(0.0000);
            $table->decimal('total', 12,4)->default(0.0000);
            $table->decimal('grand_total', 12,4)->default(0.0000);
            $table->decimal('discount_amount', 12,4)->default(0.0000);
            $table->integer('discount_type_id')->nullable();
            $table->foreignId('atc_vt_id')->nullable()->constrained('m_atc')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('atc_it_id')->nullable()->constrained('m_atc')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('2307_vt', 12,4)->default(0.0000);
            $table->decimal('2307_it', 12,4)->default(0.0000);
            $table->char('2307_vt_declared_month', 7)->nullable();
            $table->char('2307_it_declared_month', 7)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->string('attachment', 255)->nullable();
            $table->foreignId('document_type_id')->nullable()->constrained('m_document_type')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('invoice_status_id')->nullable()->constrained('m_invoice_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->tinyInteger('is_pos')->default(0);
            $table->tinyInteger('is_scanned')->default(0);
            $table->foreignId('assigned_daid')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_auid')->nullable()->constrained('m_employee')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('audit_status_id')->nullable()->default(1)->constrained('m_audit_status')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('audit_remarks', 255)->nullable();
            $table->integer('seq_no')->nullable()->default(0);
            $table->string('trans_type', 15);
            $table->string('record_status', 15)->default('posted');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_purchase_expense_master');
    }
};
