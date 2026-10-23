<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): ?string
    {
        foreach (['t_undeclared_trans', 'tbl2_undeclared_trans'] as $t) if (Schema::hasTable($t)) return $t;
        return null;
    }

    public function up(): void
    {
        if (!($t = $this->table())) return;
        Schema::table($t, function (Blueprint $b) use ($t) {
            if (!Schema::hasColumn($t, 'undeclared_by')) $b->unsignedBigInteger('undeclared_by')->nullable();
            if (!Schema::hasColumn($t, 'approved_by'))   $b->unsignedBigInteger('approved_by')->nullable();
            if (!Schema::hasColumn($t, 'approved_at'))   $b->timestamp('approved_at')->nullable();
            if (!Schema::hasColumn($t, 'approval_no'))   $b->string('approval_no', 30)->nullable()->index('undecl_approval_no_idx');
        });
    }

    public function down(): void
    {
        if (!($t = $this->table())) return;
        Schema::table($t, function (Blueprint $b) use ($t) {
            if (Schema::hasColumn($t, 'approval_no')) $b->dropIndex('undecl_approval_no_idx');
            foreach (['undeclared_by', 'approved_by', 'approved_at', 'approval_no'] as $c) if (Schema::hasColumn($t, $c)) $b->dropColumn($c);
        });
    }
};