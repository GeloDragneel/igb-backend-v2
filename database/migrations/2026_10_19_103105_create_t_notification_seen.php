<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Replaced by 2026_10_20_140001_create_t_notification_live_read.
 * t_notification_seen already existed in the database (another feature), so this does nothing –
 * and its rollback does nothing too, so that table is never dropped.
 */
return new class extends Migration
{
    public function up(): void {}
    public function down(): void {}
};