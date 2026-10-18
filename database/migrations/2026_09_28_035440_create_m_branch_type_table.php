<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_branch_type', function (Blueprint $table) {
            $table->id(); // old BTID
            $table->string('name', 50); // old BranchType
            $table->string('branch_code', 10)->nullable(); // old BranchCode
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_branch_type');
    }
};