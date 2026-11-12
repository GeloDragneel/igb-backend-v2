<?php
// php artisan make:migration alter_t_pos_sale_pending
// POS sales stay in t_pos_sale as PENDING (not in Sales / Collection yet)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_pos_sale', function (Blueprint $table) {
            $table->string('invoice_no', 60)->nullable()->change();               // filled once posted to Sales
            if (!Schema::hasColumn('t_pos_sale', 'post_status')) {
                $table->string('post_status', 10)->default('PENDING')->after('status')->index();   // PENDING · POSTED
            }
            if (!Schema::hasColumn('t_pos_sale', 'posted_at')) {
                $table->dateTime('posted_at')->nullable()->after('post_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_pos_sale', function (Blueprint $table) {
            if (Schema::hasColumn('t_pos_sale', 'posted_at')) $table->dropColumn('posted_at');
            if (Schema::hasColumn('t_pos_sale', 'post_status')) { $table->dropIndex(['post_status']); $table->dropColumn('post_status'); }
        });
    }
};