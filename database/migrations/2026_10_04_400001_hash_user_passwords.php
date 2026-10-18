<?php

use App\Support\Passwords;
use Illuminate\Database\Migrations\Migration;

/**
 * Hashes every plain-text password in m_user_client, m_user_client_staff and m_user_staff (bcrypt).
 * Already-hashed passwords are skipped. This cannot be undone – back up the 3 tables first.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Passwords::TABLES as $table) {
            [$hashed, $skipped, $empty] = Passwords::hashTable($table);
            echo "  {$table}: {$hashed} hashed, {$skipped} already hashed, {$empty} empty\n";
        }
    }

    public function down(): void
    {
        // hashes can't be turned back into passwords
    }
};
