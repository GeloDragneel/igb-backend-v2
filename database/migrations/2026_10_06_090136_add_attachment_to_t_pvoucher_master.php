<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pay Bill attachment (file is in S3 folder Pay_Bill/{pvoucher_no}-{name}) */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('t_pvoucher_master', 'attachment')) {
            Schema::table('t_pvoucher_master', fn (Blueprint $t) => $t->string('attachment', 255)->nullable()->after('document_no'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('t_pvoucher_master', 'attachment')) {
            Schema::table('t_pvoucher_master', fn (Blueprint $t) => $t->dropColumn('attachment'));
        }
    }
};