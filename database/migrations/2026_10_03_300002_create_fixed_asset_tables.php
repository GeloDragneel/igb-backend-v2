<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task → Fixed Asset Management
 *   t_fixed_assets              asset register (straight-line depreciation)
 *   t_fixed_asset_depreciation  one row per asset per posted month (links to the General Journal entry)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_fixed_assets')) {
            Schema::create('t_fixed_assets', function (Blueprint $t) {
                $t->id();
                $t->string('asset_no', 30)->unique();
                $t->string('name', 150);
                $t->string('category', 100)->nullable();             // e.g. Office Equipment, Vehicle
                $t->string('description', 255)->nullable();
                $t->string('location', 150)->nullable();
                $t->string('serial_no', 100)->nullable();
                $t->date('acquisition_date');
                $t->string('depreciation_start', 7);                  // Y-m, first month depreciated
                $t->decimal('cost', 15, 2)->default(0);
                $t->decimal('salvage_value', 15, 2)->default(0);
                $t->unsignedInteger('useful_life_months')->default(60);
                $t->string('method', 20)->default('straight_line');
                $t->string('asset_account', 20)->nullable();          // e.g. 15xxx Property & Equipment
                $t->string('accum_dep_account', 20)->nullable();      // Accumulated Depreciation
                $t->string('dep_expense_account', 20)->nullable();    // Depreciation Expense
                $t->string('status', 20)->default('active');          // active | disposed
                $t->date('disposed_date')->nullable();
                $t->decimal('disposal_amount', 15, 2)->default(0);
                $t->string('remarks', 255)->nullable();
                $t->unsignedBigInteger('added_by')->index();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_fixed_asset_depreciation')) {
            Schema::create('t_fixed_asset_depreciation', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('asset_id');
                $t->string('period', 7);                              // Y-m
                $t->decimal('amount', 15, 2)->default(0);
                $t->string('jv_no', 60)->nullable();                  // t_general_journal.jv_no
                $t->unsignedBigInteger('added_by')->index();
                $t->timestamps();
                $t->unique(['asset_id', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_fixed_asset_depreciation');
        Schema::dropIfExists('t_fixed_assets');
    }
};
