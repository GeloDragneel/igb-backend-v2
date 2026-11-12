<?php
// php artisan make:migration create_pos_tables
// POS: settings per client, cashier shifts, and the POS receipt of each sale
// (every sale is also a Sales Invoice + Collection; stock goes down through the invoice lines)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_pos_settings')) {
            Schema::create('t_pos_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('added_by')->unique();      // client (same as t_sales_invoice_master.added_by)
                $table->string('store_name', 255)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('tin', 50)->nullable();
                $table->string('footer', 500)->nullable();             // receipt footer
                $table->unsignedSmallInteger('paper')->default(80);    // 58 / 80 mm
                $table->string('ar_account', 15)->nullable();          // invoice debit (Accounts Receivable)
                $table->string('cash_account', 15)->nullable();        // collection: Cash
                $table->string('gcash_account', 15)->nullable();       // collection: GCash
                $table->string('card_account', 15)->nullable();        // collection: Card
                $table->boolean('allow_negative')->default(false);     // sell even with no stock
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('t_pos_shift')) {
            Schema::create('t_pos_shift', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('added_by')->index();
                $table->string('shift_no', 40)->unique();
                $table->unsignedBigInteger('cashier_id')->nullable()->index();
                $table->string('cashier_name', 150)->nullable();
                $table->decimal('opening_cash', 15, 2)->default(0);
                $table->dateTime('opened_at');
                $table->dateTime('closed_at')->nullable();
                $table->decimal('expected_cash', 15, 2)->nullable();   // opening + cash sales
                $table->decimal('counted_cash', 15, 2)->nullable();    // cashier's count at closing
                $table->decimal('over_short', 15, 2)->nullable();      // counted − expected
                $table->text('notes')->nullable();
                $table->string('status', 10)->default('OPEN')->index(); // OPEN · CLOSED
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('t_pos_sale')) {
            Schema::create('t_pos_sale', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('added_by')->index();
                $table->unsignedBigInteger('shift_id')->nullable()->index();
                $table->string('receipt_no', 40)->unique();            // POS-…  (= invoice document_no)
                $table->string('invoice_no', 60)->index();             // t_sales_invoice_master.invoice_no
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedInteger('items')->default(0);
                $table->decimal('sub_total', 15, 2)->default(0);       // before discount
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->decimal('vat', 15, 2)->default(0);
                $table->decimal('tendered', 15, 2)->default(0);
                $table->decimal('change_due', 15, 2)->default(0);
                $table->text('payments');                              // [{method, amount, reference, rvoucher_no}]
                $table->text('lines');                                 // receipt lines (for reprint)
                $table->string('status', 10)->default('PAID')->index(); // PAID · VOID
                $table->string('void_reason', 255)->nullable();
                $table->unsignedBigInteger('cashier_id')->nullable();
                $table->string('cashier_name', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_pos_sale');
        Schema::dropIfExists('t_pos_shift');
        Schema::dropIfExists('t_pos_settings');
    }
};