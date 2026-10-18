<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // master: rvoucher_no must be unique to be referenced
        Schema::table('t_rvoucher_master', function (Blueprint $table) {
            $table->dropIndex('idx_rvm_rvno');
            $table->unique('rvoucher_no', 'uq_rvm_rvno');
        });

        // detail → master
        Schema::table('t_rvoucher_detail', function (Blueprint $table) {
            $table->foreign('rvoucher_no', 'fk_rvd_rvoucher_no')
                ->references('rvoucher_no')
                ->on('t_rvoucher_master')
                ->cascadeOnUpdate()   // renumbering a voucher updates its lines
                ->cascadeOnDelete();  // deleting a voucher deletes its lines
        });
    }

    public function down(): void
    {
        Schema::table('t_rvoucher_detail', function (Blueprint $table) {
            $table->dropForeign('fk_rvd_rvoucher_no');
        });

        Schema::table('t_rvoucher_master', function (Blueprint $table) {
            $table->dropUnique('uq_rvm_rvno');
            $table->index('rvoucher_no', 'idx_rvm_rvno');
        });
    }
};