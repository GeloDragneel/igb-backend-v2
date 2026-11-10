<?php
// php artisan make:migration add_client_num_to_m_social_party
// m_social_party is shared: vendor rows use vendor_id, client rows use client_num (m_client.client_num)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_social_party', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_id')->nullable()->change();
            if (!Schema::hasColumn('m_social_party', 'client_num')) {
                $table->string('client_num', 50)->nullable()->after('vendor_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('m_social_party', function (Blueprint $table) {
            if (Schema::hasColumn('m_social_party', 'client_num')) {
                $table->dropIndex(['client_num']);
                $table->dropColumn('client_num');
            }
        });
    }
};