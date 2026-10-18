<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_client_valid_id', function (Blueprint $table) {   // (table name)
            $table->id();                                        // old CVID
            $table->string('client_num', 60);                    // → m_client_account.client_num
            $table->string('client_num_type', 30)->nullable();
            $table->string('institution', 60);                   // BIR, SSS, PHILHEALTH …
            $table->string('id_num', 60)->nullable();            // TIN when institution = BIR
            $table->timestamps();                                // created_at = old AutoD

            $table->index(['client_num', 'institution']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_client_valid_id');               // (table name)
    }
};