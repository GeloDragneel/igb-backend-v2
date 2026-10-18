<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_pos_status', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->timestamps();
        });

        // same rows as the old tbl_branch_type
        DB::table('m_branch_type')->insert([
            ['id' => 1, 'name' => 'Open',   'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Partial Received', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Closed', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'VOID', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_pos_status');
    }
};