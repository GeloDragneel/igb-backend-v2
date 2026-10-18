<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * One place for password rules.
 *   Passwords::check($plain, $stored)  → true only for a correct HASHED password
 *   Passwords::hashTable('m_user_client') → hashes every plain-text password in that table
 */
class Passwords
{
    /** tables that hold login passwords */
    public const TABLES = ['m_user_client', 'm_user_client_staff', 'm_user_staff'];

    public static function isHashed(?string $value): bool
    {
        $v = (string) $value;
        return $v !== '' && (Hash::isHashed($v) || preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $v) === 1);
    }

    /** hashed passwords only – plain text never matches */
    public static function check(string $plain, ?string $stored): bool
    {
        $stored = (string) $stored;
        if ($plain === '' || !self::isHashed($stored)) return false;
        try {
            return Hash::check($plain, $stored);
        } catch (\Throwable $e) {
            return false;   // unknown hash format
        }
    }

    /** the password column of a table (any letter case), or null */
    public static function passwordColumn(string $table): ?string
    {
        $cols = Schema::getColumnListing($table);
        foreach (['password', 'user_password', 'passwd', 'pass'] as $want) {
            foreach ($cols as $c) if (strcasecmp($c, $want) === 0) return $c;
        }
        return null;
    }

    /** the primary key column of a table (falls back to id), or null */
    public static function keyColumn(string $table): ?string
    {
        $pk = DB::selectOne(
            "SELECT COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION LIMIT 1",
            [$table]
        );
        if ($pk) return $pk->c;
        foreach (Schema::getColumnListing($table) as $c) if (strcasecmp($c, 'id') === 0) return $c;
        return null;
    }

    /**
     * Makes sure the password column can hold a hash (60+ chars), then hashes every
     * plain-text password in $table. Already-hashed rows are left alone, so it is safe to run again.
     * Returns [hashed, already_hashed, empty, note].
     */
    public static function hashTable(string $table): array
    {
        if (!Schema::hasTable($table)) return [0, 0, 0, 'table not found'];
        $column = self::passwordColumn($table);
        if (!$column) return [0, 0, 0, 'no password column (columns: ' . implode(', ', Schema::getColumnListing($table)) . ')'];
        $key = self::keyColumn($table);
        if (!$key) return [0, 0, 0, 'no primary key / id column'];

        self::widenColumn($table, $column);

        $hashed = $skipped = $empty = 0;
        DB::table($table)->select([$key, $column])->orderBy($key)->chunkById(500, function ($rows) use ($table, $column, $key, &$hashed, &$skipped, &$empty) {
            foreach ($rows as $r) {
                $v = (string) ($r->$column ?? '');
                if ($v === '') { $empty++; continue; }
                if (self::isHashed($v)) { $skipped++; continue; }
                DB::table($table)->where($key, $r->$key)->update([$column => Hash::make($v)]);
                $hashed++;
            }
        }, $key);

        return [$hashed, $skipped, $empty, "column `{$column}`, key `{$key}`"];
    }

    /** bcrypt needs 60 characters; widen VARCHAR columns that are shorter (keeps NULL / NOT NULL) */
    private static function widenColumn(string $table, string $column): void
    {
        $info = DB::selectOne(
            'SELECT DATA_TYPE AS t, CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS n
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if (!$info) return;
        if (in_array(strtolower($info->t), ['varchar', 'char'], true) && (int) $info->len < 255) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` VARCHAR(255) %s', $table, $column, $info->n === 'YES' ? 'NULL' : 'NOT NULL'));
        }
    }
}