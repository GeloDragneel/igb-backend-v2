<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importation (old tbl2_importation_master / _detail, tbl2_sellers)
 * Exportation (new, same structure: buyers instead of sellers)
 *
 * invoice_status_id: 1 = active, 5 = VOID (same numbers as the old InvoiceStatus).
 * No foreign key on statuses / countries on purpose (old data has 0 values).
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------- parties ---------------- */
        Schema::create('m_sellers', function (Blueprint $t) {
            $t->id();                                             // ID
            $t->string('name', 150);                              // SellersName
            $t->string('address', 255)->nullable();               // Address
            $t->string('tin', 20)->nullable();
            $t->unsignedBigInteger('added_by')->index();          // AddedBy
            $t->timestamps();
        });

        Schema::create('m_buyers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('address', 255)->nullable();
            $t->string('tin', 20)->nullable();
            $t->unsignedBigInteger('added_by')->index();
            $t->timestamps();
        });

        /* ---------------- importation ---------------- */
        Schema::create('t_importation_master', function (Blueprint $t) {
            $t->id();                                                    // ID
            $t->string('importation_no', 40)->unique();                  // ImportationNo
            $t->unsignedBigInteger('seller_id')->default(0);             // SellerID
            $t->date('release_date')->nullable();                        // ReleaseDate
            $t->string('invoice_no', 100)->nullable();                   // InvoiceNo
            $t->string('import_entry_no', 100)->nullable();              // ImportEntryNo
            $t->string('origin_country', 60)->nullable();                // OriginCountry
            $t->string('currency', 10)->default('PHP');                  // Currency
            $t->decimal('ex_rate', 12, 4)->default(1);                   // ExRate
            $t->date('vat_payment_date')->nullable();                    // VATPaymentDate
            $t->string('remarks', 255)->nullable();                      // Remarks
            $t->unsignedBigInteger('document_type_id')->default(0);      // DocumentType
            $t->string('declared_month', 7)->nullable();                 // DeclaredMonth (Y-m)
            $t->string('account_code', 20)->nullable();                  // Accounts
            $t->string('attachment', 255)->nullable();                   // Attachment
            $t->unsignedBigInteger('added_by')->default(0);              // AddedBy
            $t->unsignedTinyInteger('invoice_status_id')->default(1);    // InvoiceStatus
            $t->decimal('total_landed_cost', 15, 4)->default(0);         // TotalLandedCost
            $t->decimal('total_charge_before', 15, 4)->default(0);       // TotalChargeBefore
            $t->decimal('total_dutiable_value', 15, 4)->default(0);      // TotalDutiableValue
            $t->decimal('total_vat', 15, 4)->default(0);                 // TotalVAT
            $t->decimal('total_taxable_imports', 15, 4)->default(0);     // TotalTaxableImports
            $t->decimal('total_exempt_imports', 15, 4)->default(0);      // TotalExemptImports
            $t->timestamps();                                            // DateAdded / DateUpdated
            $t->index(['added_by', 'declared_month']);
            $t->index(['added_by', 'invoice_no']);
        });

        Schema::create('t_importation_detail', function (Blueprint $t) {
            $t->id();                                                    // ID
            $t->unsignedBigInteger('importation_master_id')->default(0)->index();
            $t->string('importation_no', 40)->index();                   // ImportationNo
            $t->unsignedBigInteger('seller_id')->default(0);             // SellerID
            $t->unsignedBigInteger('item_id')->default(0);               // ItemID
            $t->date('release_date')->nullable();                        // ReleaseDate
            $t->string('invoice_no', 100)->nullable();                   // InvoiceNo
            $t->string('import_entry_no', 100)->nullable();              // ImportEntryNo
            $t->string('origin_country', 60)->nullable();                // OriginCountry
            $t->date('vat_payment_date')->nullable();                    // VATPaymentDate
            $t->string('currency', 10)->default('PHP');                  // Currency
            $t->decimal('ex_rate', 12, 4)->default(1);                   // ExRate
            $t->decimal('total_landed_cost', 15, 4)->default(0);         // TotalLandedCost
            $t->decimal('charges_from_custom', 15, 4)->default(0);       // ChargesFromCustom
            $t->decimal('dutiable_value', 15, 4)->default(0);            // DutiableValue
            $t->decimal('amount_of_vat', 15, 4)->default(0);             // AmountOfVAT
            $t->decimal('taxable_imports', 15, 4)->default(0);           // TaxableImports
            $t->decimal('exempt_imports', 15, 4)->default(0);            // ExemptImports
            $t->unsignedBigInteger('document_type_id')->default(0);      // DocumentType
            $t->string('declared_month', 7)->nullable();                 // DeclaredMonth
            $t->string('account_code', 20)->nullable();                  // Accounts
            $t->string('attachment', 255)->nullable();                   // Attachment
            $t->unsignedTinyInteger('invoice_status_id')->default(1);    // InvoiceStatus
            $t->unsignedBigInteger('added_by')->default(0);              // AddedBy
            $t->timestamps();
        });

        /* ---------------- exportation ---------------- */
        Schema::create('t_exportation_master', function (Blueprint $t) {
            $t->id();
            $t->string('exportation_no', 40)->unique();
            $t->unsignedBigInteger('buyer_id')->default(0);
            $t->date('export_date')->nullable();                         // shipment / loading date
            $t->string('invoice_no', 100)->nullable();
            $t->string('export_entry_no', 100)->nullable();              // export declaration no
            $t->string('destination_country', 60)->nullable();
            $t->string('currency', 10)->default('USD');
            $t->decimal('ex_rate', 12, 4)->default(1);
            $t->date('payment_date')->nullable();                        // date the proceeds were received
            $t->string('remarks', 255)->nullable();
            $t->unsignedBigInteger('document_type_id')->default(0);
            $t->string('declared_month', 7)->nullable();
            $t->string('account_code', 20)->nullable();                  // receivable / cash account (debit)
            $t->string('attachment', 255)->nullable();
            $t->unsignedBigInteger('added_by')->default(0);
            $t->unsignedTinyInteger('invoice_status_id')->default(1);
            $t->decimal('total_fob_amount', 15, 4)->default(0);          // in the invoice currency
            $t->decimal('total_peso_amount', 15, 4)->default(0);         // zero-rated sales in PHP
            $t->timestamps();
            $t->index(['added_by', 'declared_month']);
            $t->index(['added_by', 'invoice_no']);
        });

        Schema::create('t_exportation_detail', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('exportation_master_id')->default(0)->index();
            $t->string('exportation_no', 40)->index();
            $t->unsignedBigInteger('buyer_id')->default(0);
            $t->unsignedBigInteger('item_id')->default(0);
            $t->date('export_date')->nullable();
            $t->string('invoice_no', 100)->nullable();
            $t->string('export_entry_no', 100)->nullable();
            $t->string('destination_country', 60)->nullable();
            $t->date('payment_date')->nullable();
            $t->string('currency', 10)->default('USD');
            $t->decimal('ex_rate', 12, 4)->default(1);
            $t->decimal('quantity', 15, 4)->default(0);
            $t->decimal('fob_amount', 15, 4)->default(0);                // in the invoice currency
            $t->decimal('peso_amount', 15, 4)->default(0);               // fob_amount × ex_rate (zero-rated sale)
            $t->unsignedBigInteger('document_type_id')->default(0);
            $t->string('declared_month', 7)->nullable();
            $t->string('account_code', 20)->nullable();                  // sales account (credit)
            $t->string('attachment', 255)->nullable();
            $t->unsignedTinyInteger('invoice_status_id')->default(1);
            $t->unsignedBigInteger('added_by')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_exportation_detail');
        Schema::dropIfExists('t_exportation_master');
        Schema::dropIfExists('t_importation_detail');
        Schema::dropIfExists('t_importation_master');
        Schema::dropIfExists('m_buyers');
        Schema::dropIfExists('m_sellers');
    }
};
