<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * t_notification_live_read – "read" for the live bell items (pending scans, reverted, for audit, HR approvals).
 * seen_count = the count the employee already saw; a higher count shows the item as unread again.
 * (Own name, so it never touches the existing t_notification_seen table.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_notification_live_read')) return;
        Schema::create('t_notification_live_read', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');            // m_employee.id
            $t->string('item_key', 60);                       // e.g. scan12purchases, rev12sales, aud12expenses, hrleave
            $t->unsignedInteger('seen_count')->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_notification_live_read');
    }
};