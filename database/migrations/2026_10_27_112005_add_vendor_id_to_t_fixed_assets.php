<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fixed Asset Management: the vendor (supplier) the asset was bought from – m_vendor_customer.id */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_fixed_assets') && !Schema::hasColumn('t_fixed_assets', 'vendor_id')) {
            Schema::table('t_fixed_assets', function (Blueprint $t) {
                $t->unsignedBigInteger('vendor_id')->nullable()->after('serial_no')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_fixed_assets', 'vendor_id')) {
            Schema::table('t_fixed_assets', fn (Blueprint $t) => $t->dropColumn('vendor_id'));
        }
    }
};