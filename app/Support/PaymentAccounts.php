<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account rules used by Sales / Purchases / Expenses:
 *   isCash()       → the invoice is paid on save (PAID)
 *   isReceivable() → Sales shows RECEIVE MONEY
 *   isPayable()    → Purchases / Expenses show PAY BILL
 * plus invoice status helpers (PAID / PARTIAL / UNPAID) used after a collection or payment.
 */
class PaymentAccounts
{
    private static array $cache = [];

    private static function row(int $by, ?string $code): ?object
    {
        if (!$code) return null;
        $k = "{$by}|{$code}";
        if (!array_key_exists($k, self::$cache)) {
            self::$cache[$k] = DB::table('m_charts_of_account')->where('added_by', $by)->where('account_code', $code)->first();
        }
        return self::$cache[$k];
    }

    /** Cash, Cash on Hand, Cash in Bank, Petty Cash … or any account ticked "for paid" */
    public static function isCash(int $by, ?string $code): bool
    {
        $a = self::row($by, $code);
        if (!$a) return false;
        if ((int) ($a->is_for_paid ?? 0) === 1) return true;
        $name = strtolower((string) $a->account_name);
        return str_contains($name, 'cash') && !str_contains($name, 'receivable') && !str_contains($name, 'payable');
    }

    public static function isReceivable(int $by, ?string $code): bool
    {
        $a = self::row($by, $code);
        return $a && str_contains(strtolower((string) $a->account_name), 'receivable');
    }

    public static function isPayable(int $by, ?string $code): bool
    {
        $a = self::row($by, $code);
        return $a && str_contains(strtolower((string) $a->account_name), 'payable');
    }

    /** m_invoice_status id by name, with the usual ids as fallback (PAID 1, UNPAID 2, PARTIAL 3) */
    public static function statusId(string $name): int
    {
        $fallback = ['PAID' => 1, 'UNPAID' => 2, 'PARTIAL' => 3][$name] ?? 2;
        $q = DB::table('m_invoice_status');
        $id = $name === 'PARTIAL'
            ? $q->whereRaw("UPPER(name) LIKE 'PARTIAL%'")->value('id')
            : $q->whereRaw('UPPER(TRIM(name)) = ?', [$name])->value('id');
        return (int) ($id ?: $fallback);
    }

    /** status for an invoice total vs. what has been collected / paid so far */
    public static function statusFor(float $total, float $settled): int
    {
        if ($settled <= 0.009) return self::statusId('UNPAID');
        if ($settled + 0.009 >= $total) return self::statusId('PAID');
        return self::statusId('PARTIAL');
    }

    /** keep only the columns that exist in $table */
    public static function only(string $table, array $row): array
    {
        static $cols = [];
        $cols[$table] ??= array_flip(Schema::getColumnListing($table));
        return array_intersect_key($row, $cols[$table]);
    }
}
