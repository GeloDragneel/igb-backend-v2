<?php

namespace App\Http\Middleware;

use App\Models\UserStaff;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administration → User Logs.
 * Records every INSERT / UPDATE / DELETE that runs during a request made by a STAFF login (UserStaff),
 * whatever table it touches and whether it came from Eloquent or DB::table / DB::statement.
 * The statements are collected while the request runs and saved in ONE insert after the response is sent,
 * so the pages don't get slower and nothing is logged when a client (UserClient) is logged in.
 */
class LogStaffActivity
{
    /** tables never logged (framework / noise / the log itself) */
    private const SKIP = [
        't_user_logs', 'personal_access_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'migrations', 'password_reset_tokens', 'password_resets',
    ];
    /** columns whose values are never written to the log */
    private const SECRET = '/pass(word)?|token|secret|api_?key|pin$/i';
    private const MAX_PER_REQUEST = 2000;   // safety for big imports
    private const MAX_VALUE = 2000;         // longest value kept per column

    private static bool $listening = false;
    private static bool $writing = false;
    private static array $buffer = [];
    private static int $dropped = 0;

    public function handle(Request $request, Closure $next): Response
    {
        self::$buffer = [];
        self::$dropped = 0;

        if (!self::$listening) {
            self::$listening = true;
            DB::listen(function (QueryExecuted $q) {
                if (self::$writing) return;
                $entry = self::describe($q->sql, $q->bindings);
                if (!$entry) return;
                if ($entry['action'] === 'INSERT' && $entry['record_id'] === null && is_array($entry['changes']) && !array_is_list($entry['changes'])) {
                    try { $id = $q->connection->getPdo()->lastInsertId(); } catch (\Throwable) { $id = null; }
                    if ($id && $id !== '0') $entry['record_id'] = (string) $id;
                }
                if (count(self::$buffer) >= self::MAX_PER_REQUEST) { self::$dropped++; return; }
                self::$buffer[] = $entry;
            });
        }

        return $next($request);
    }

    /** after the response: save what this staff user changed */
    public function terminate(Request $request, Response $response): void
    {
        $entries = self::$buffer;
        self::$buffer = [];
        if (!$entries) return;

        $user = $request->user() ?? auth('sanctum')->user();
        if (!$user instanceof UserStaff) return;            // staff only

        try {
            self::$writing = true;
            $name = null;
            if (!empty($user->employee_id)) {
                $name = DB::table('m_employee')->where('id', $user->employee_id)
                    ->selectRaw("TRIM(CONCAT_WS(' ', last_name, first_name, middle_name)) AS n")->value('n');
            }
            $route = $request->route();
            $action = $route ? class_basename((string) $route->getActionName()) : null;
            $base = [
                'request_id'    => (string) Str::uuid(),
                'user_staff_id' => (int) $user->id,
                'username'      => Str::limit((string) ($user->username ?? ''), 100, ''),
                'employee_name' => $name ? Str::limit($name, 150, '') : null,
                'method'        => $request->method(),
                'path'          => Str::limit($request->path(), 255, ''),
                'route_action'  => $action ? Str::limit($action, 191, '') : null,
                'ip'            => $request->ip(),
                'user_agent'    => Str::limit((string) $request->userAgent(), 255, ''),
                'created_at'    => now(),
            ];
            if (self::$dropped) {
                $entries[] = ['action' => 'NOTE', 'table_name' => '-', 'record_id' => null, 'changes' => null,
                    'where_clause' => null, 'sql_text' => self::$dropped . ' more statements were not logged'];
            }
            $rows = array_map(fn ($e) => $base + [
                'action'       => $e['action'],
                'table_name'   => $e['table_name'],
                'record_id'    => $e['record_id'],
                'changes'      => $e['changes'] === null ? null : json_encode($e['changes'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'where_clause' => $e['where_clause'],
                'sql_text'     => $e['sql_text'],
            ], $entries);
            foreach (array_chunk($rows, 200) as $chunk) DB::table('t_user_logs')->insert($chunk);
        } catch (\Throwable $e) {
            report($e);                                     // a log problem must never break the app
        } finally {
            self::$writing = false;
        }
    }

    /* ------------------------------------------------------------------
       SQL → { action, table_name, record_id, changes, where_clause, sql_text }
       Works on what Laravel generates: insert into `t` (`a`,`b`) values (?, ?), …
       update `t` set `a` = ?, … where …   ·   delete from `t` where …
       ------------------------------------------------------------------ */
    public static function describe(string $sql, array $bindings): ?array
    {
        if (!preg_match('/^\s*(insert(?:\s+ignore)?\s+into|replace\s+into|update(?:\s+ignore)?|delete(?:\s+`?\w+`?)?\s+from)\s+`?([\w.]+)`?/i', $sql, $m)) {
            return null;
        }
        $verb = strtolower(strtok($m[1], " \t\n"));
        $action = match ($verb) { 'insert', 'replace' => 'INSERT', 'update' => 'UPDATE', default => 'DELETE' };
        $table = str_contains($m[2], '.') ? substr($m[2], strrpos($m[2], '.') + 1) : $m[2];
        if (in_array(strtolower($table), self::SKIP, true)) return null;

        $bindings = array_map([self::class, 'value'], array_values($bindings));
        $changes = null;
        $where = null;
        $recordId = null;

        if ($action === 'INSERT') {
            if (preg_match('/\(([^)]*)\)\s*values\s*/i', $sql, $cm)) {
                $cols = array_map(fn ($c) => trim($c, " `\t\n"), explode(',', $cm[1]));
                $n = count($cols);
                $rows = $n ? array_chunk($bindings, $n) : [];
                $rows = array_map(fn ($r) => self::mask(array_combine(array_slice($cols, 0, count($r)), $r)), $rows);
                $changes = count($rows) === 1 ? $rows[0] : $rows;
                if (count($rows) === 1 && isset($rows[0]['id'])) $recordId = (string) $rows[0]['id'];
            }
        } else {
            // split at the WHERE that belongs to the statement (the last top-level one is enough for Laravel SQL)
            $pos = self::wherePos($sql);
            $head = $pos === null ? $sql : substr($sql, 0, $pos);
            $tail = $pos === null ? '' : substr($sql, $pos);
            $headCount = substr_count($head, '?');
            if ($action === 'UPDATE' && preg_match('/\bset\b(.*)$/is', $head, $sm)) {
                $changes = [];
                $i = $headCount - substr_count($sm[1], '?');      // bindings used before SET (joins)
                foreach (self::splitTop($sm[1]) as $part) {
                    if (!preg_match('/^\s*`?(?:\w+`?\.`?)?(\w+)`?\s*=\s*(.+)$/s', $part, $pm)) continue;
                    $expr = trim($pm[2]);
                    $q = substr_count($expr, '?');
                    $changes[$pm[1]] = $expr === '?' ? ($bindings[$i] ?? null) : self::fill($expr, array_slice($bindings, $i, $q));
                    $i += $q;
                }
                $changes = self::mask($changes);
            }
            if ($tail !== '') {
                $whereBind = array_slice($bindings, $headCount);
                $where = Str::limit(self::fill(preg_replace('/^\s*where\s+/i', '', $tail), $whereBind), 2000);
                if (preg_match('/^\s*where\s+`?(?:\w+`?\.`?)?id`?\s*=\s*\?/i', $tail) && isset($whereBind[0])) {
                    $recordId = (string) $whereBind[0];
                } elseif (preg_match('/^\s*where\s+`?(?:\w+`?\.`?)?id`?\s+in\s*\(([^)]*)\)/i', $tail, $im)) {
                    $recordId = Str::limit(implode(',', array_slice($whereBind, 0, substr_count($im[1], '?'))), 64, '…');
                }
            }
        }

        return [
            'action'       => $action,
            'table_name'   => Str::limit($table, 64, ''),
            'record_id'    => $recordId === null ? null : Str::limit($recordId, 64, ''),
            'changes'      => $changes,
            'where_clause' => $where,
            'sql_text'     => Str::limit(self::fill($sql, $bindings, true), 4000),
        ];
    }

    private static function value($v)
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        if (is_bool($v)) return (int) $v;
        if (is_string($v) && mb_strlen($v) > self::MAX_VALUE) return mb_substr($v, 0, self::MAX_VALUE) . '…';
        if (is_object($v) && method_exists($v, '__toString')) return (string) $v;
        return $v;
    }

    private static function mask(array $row): array
    {
        foreach ($row as $k => $v) if (preg_match(self::SECRET, (string) $k)) $row[$k] = '••••••';
        return $row;
    }

    /** replace ? with the values (quoted); $hideSecrets hides values of secret-looking columns in the SQL */
    private static function fill(string $sql, array $bindings, bool $hideSecrets = false): string
    {
        $i = 0;
        $out = preg_replace_callback('/\?/', function () use (&$i, $bindings) {
            $v = $bindings[$i++] ?? null;
            if ($v === null) return 'NULL';
            return is_int($v) || is_float($v) ? (string) $v : "'" . str_replace("'", "''", (string) $v) . "'";
        }, $sql);
        if ($hideSecrets) {
            $out = preg_replace("/(`?\w*(?:pass(?:word)?|token|secret|api_?key)\w*`?\s*=\s*)'(?:[^']|'')*'/i", "$1'••••••'", $out);
        }
        return $out;
    }

    /** position of the statement's WHERE (not one inside a sub-select / parentheses) */
    private static function wherePos(string $sql): ?int
    {
        $depth = 0;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === '(') $depth++;
            elseif ($c === ')') $depth--;
            elseif ($depth === 0 && ($c === 'w' || $c === 'W') && strcasecmp(substr($sql, $i, 5), 'where') === 0
                && ($i === 0 || ctype_space($sql[$i - 1])) && ($i + 5 >= $len || ctype_space($sql[$i + 5]))) {
                return $i;
            }
        }
        return null;
    }

    /** split "a = ?, b = (x, y)" on top-level commas */
    private static function splitTop(string $s): array
    {
        $parts = [];
        $depth = 0;
        $cur = '';
        $quote = null;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote) { $cur .= $c; if ($c === $quote) $quote = null; continue; }
            if ($c === "'" || $c === '"') { $quote = $c; $cur .= $c; continue; }
            if ($c === '(') $depth++;
            if ($c === ')') $depth--;
            if ($c === ',' && $depth === 0) { $parts[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        if (trim($cur) !== '') $parts[] = $cur;
        return $parts;
    }
}