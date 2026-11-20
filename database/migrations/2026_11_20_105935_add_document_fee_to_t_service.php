<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_service', function (Blueprint $t) {
            if (!Schema::hasColumn('t_service', 'document_fee')) $t->decimal('document_fee', 15, 2)->default(0);
        });
    }
    public function down(): void
    {
        Schema::table('t_service', fn (Blueprint $t) => $t->dropColumn('document_fee'));
    }
};