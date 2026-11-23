<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lending → one-value settings (name → value)
 *   retain_interest_pct   % of the interest kept in the fund on a cash-out when "Retain interest" is checked (default 30)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_lending_config')) {
            Schema::create('m_lending_config', function (Blueprint $t) {
                $t->id();
                $t->string('name', 60)->unique();
                $t->string('value', 255)->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        DB::table('m_lending_config')->insertOrIgnore([
            ['name' => 'retain_interest_pct', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_lending_config');
    }
};
