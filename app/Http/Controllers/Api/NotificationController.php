<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserClientStaff;
use App\Models\UserStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Header bell – only for UserStaff (m_user_staff) and UserClientStaff (m_user_client_staff).
 * Client logins (m_user_client) get an empty list.
 *
 * Two kinds of items:
 *   saved  t_notification rows sent to my employee (to_emp_id) – can be marked read
 *          (scan uploads from the app, assigned clients, leave / OT decisions …)
 *   live   counted from the data every time, gone when the work is done
 *          (read = t_notification_live_read: unread again when the count goes up):
 *            DA (3)                      pending scans · reverted sales / purchases / expenses   (assigned_daid = me)
 *            Auditor (5) / Supervisor (10) sales / purchases / expenses for audit              (assigned_auid = me)
 *            CEO / Supervisor / IT / CFO   leave and overtime requests waiting for approval
 *            IT Officer / CEO / Supervisor / Audit Supervisor (management):
 *              · tickets waiting for approval – Sales / Purchases / Expenses        → Administration → Ticket
 *              · reverted invoices (all clients) – Sales / Purchases / Expenses   → Monitoring → Efficiency (Reverted)
 *              · unpaid Billing EO (non-recurring) bills                          → Billing → Dashboard
 *
 * Employee of the login:  UserStaff → m_user_staff.employee_id, else m_employee.com_id = m_user_staff.comid
 *                         UserClientStaff → m_user_client_staff.employee_id (and only its own caid)
 */
class NotificationController extends Controller
{
    private const DA = 3, AUDITOR = 5, AUDIT_SUPERVISOR = 10;
    private const HR_APPROVERS = [1, 2, 4, 8];          // m_user_type: IT OFFICER, CEO, SUPERVISOR, CFO
    private const AUDIT_PENDING = 1, AUDIT_REVERTED = 3, INVOICE_VOID = 5;
    private const TYPES = ['sales' => 'sales', 'purchases' => 'purchases', 'expenses' => 'expenses'];
    private const MAX = 50;
    private const MANAGEMENT = [1, 2, 4, 10];           // m_user_type: IT OFFICER, CEO, SUPERVISOR, AUDIT SUPERVISOR
    private const EO_SER_TYPE = 2;                      // m_service_list.ser_type_id of EO / non-recurring services
    private const SVC_UNPAID = 2, SVC_PARTIAL = 3;      // t_service.invoice_status_id

    /* ---------------- who is asking ---------------- */

    /** [employee_id, designation_id, caid (UserClientStaff only, else null)] or null for other logins */
    private function who(Request $request): ?array
    {
        $u = $request->user();
        if ($u instanceof UserStaff) {
            $emp = (int) ($u->employee_id ?? 0);
            if ($emp <= 0 && !empty($u->comid)) $emp = (int) DB::table('m_employee')->where('com_id', $u->comid)->value('id');
            $caid = null;
        } elseif ($u instanceof UserClientStaff) {
            $emp = (int) ($u->employee_id ?? 0);
            $caid = (int) ($u->caid ?? 0) ?: null;
        } else {
            return null;
        }
        if ($emp <= 0) return null;
        $designation = (int) DB::table('m_employee')->where('id', $emp)->value('designation_id');
        return [$emp, $designation, $caid];
    }

    /** DB time (saved in the app timezone) → ISO 8601 with offset, so the browser shows the right local / PH time */
    private function iso($v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            return \Illuminate\Support\Carbon::parse($v, config('app.timezone'))->toIso8601String();
        } catch (\Throwable) {
            return (string) $v;
        }
    }

    private function cols(string $table): array
    {
        static $cache = [];
        return $cache[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }

    private function pick(string $table, array $names): ?string
    {
        return collect($names)->first(fn ($c) => in_array($c, $this->cols($table), true));
    }

    /* ---------------- send (call from any controller) ---------------- */

    /**
     * NotificationController::send($employeeIds, 'message', 'Module', $caId, $fromEmployeeId, $reference);
     * Modules with a link: Scan, Assigned, Leave, Overtime (see link()).
     */
    public static function send(int|array|null $to, string $message, string $module, ?int $caId = null, ?int $from = null, ?string $reference = null): void
    {
        if (!Schema::hasTable('t_notification')) return;
        $now = now();
        $rows = [];
        foreach (array_unique(array_filter(array_map('intval', (array) $to))) as $emp) {
            $rows[] = ['ca_id' => $caId ?: null, 'from_emp_id' => $from ?: null, 'to_emp_id' => $emp,
                'message' => mb_substr($message, 0, 255), 'is_read' => 0, 'module' => $module,
                'reference' => $reference, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows) {
            DB::table('t_notification')->insert($rows);
            // real time: tell each receiver's browser right away (Pusher private-emp.<id>, same channel as the Messenger)
            self::push(array_column($rows, 'to_emp_id'), 'notification', ['message' => mb_substr($message, 0, 255), 'module' => $module]);
        }
    }

    /**
     * Real time without a hook: something changed for these employees (or for management) → their bell reloads now.
     *   NotificationController::changed();             // every management login (IT, CEO, Supervisor, Audit Supervisor)
     *   NotificationController::changed([12, 15]);     // these employees
     * (The bell also checks /notifications/pulse every 15 s, so counts that change elsewhere show up within seconds.)
     */
    public static function changed(?array $employeeIds = null, string $what = ''): void
    {
        $ids = $employeeIds ?? DB::table('m_employee')->whereIn('designation_id', self::MANAGEMENT)
            ->where(fn ($w) => $w->whereNull('is_active')->orWhere('is_active', 1))->pluck('id')->all();
        self::push($ids, 'notification', ['message' => '', 'module' => $what, 'refresh' => true]);
    }

    /** Pusher event to private-emp.<id> channels – never breaks the request (3 s timeout, errors only logged) */
    private static function push(array $employeeIds, string $event, array $data): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (!$ids) return;
        $c = config('broadcasting.connections.pusher') ?: [];
        $sv = config('services.pusher') ?: [];
        $key = $c['key'] ?? $sv['key'] ?? null;
        $secret = $c['secret'] ?? $sv['secret'] ?? null;
        $app = $c['app_id'] ?? $sv['app_id'] ?? null;
        $cluster = $c['options']['cluster'] ?? $sv['cluster'] ?? null;
        if (!$key || !$secret || !$app || !$cluster || !class_exists(\Pusher\Pusher::class)) return;
        try {
            $opts = ['cluster' => $cluster, 'useTLS' => true, 'timeout' => 3];
            $http = class_exists(\GuzzleHttp\Client::class) ? new \GuzzleHttp\Client(['timeout' => 3, 'connect_timeout' => 2]) : null;
            $p = $http ? new \Pusher\Pusher($key, $secret, $app, $opts, $http) : new \Pusher\Pusher($key, $secret, $app, $opts);
            foreach (array_chunk($ids, 100) as $chunk) {
                $p->trigger(array_map(fn ($e) => "private-emp.{$e}", $chunk), $event, $data);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** page to open for a saved notification */
    private function link(?string $module, string $message, string $reference): string
    {
        $type = preg_match('/\((SALES|PURCHASES|EXPENSES)\)/i', $message, $m) ? strtolower($m[1]) : null;
        return match ($module) {
            'Scan'     => $type ? "/task/{$type}/scanned-receipts" : '/task/sales/scanned-receipts',
            'Leave', 'Overtime' => '/profile',
            'LeaveApproval' => '/hr/leaves',
            'OvertimeApproval' => '/hr/overtime',
            'Reverted' => $type ? "/task/{$type}" : '/task/sales',
            default    => $reference !== '' && str_starts_with($reference, '/') ? $reference : '',
        };
    }

    /* ---------------- GET /notifications ---------------- */

    public function index(Request $request)
    {
        $who = $this->who($request);
        if (!$who) return response()->json(['items' => [], 'unread' => 0, 'enabled' => false]);
        [$emp, $designation, $caid] = $who;

        $branches = $this->branchNames();
        $live = $this->withSeen($emp, $this->live($emp, $designation, $caid, $branches));
        $saved = $this->saved($emp, $caid, $branches);

        return response()->json([
            'enabled' => true,
            'items'   => array_slice($this->byLatest(array_merge($live, $saved)), 0, self::MAX),   // newest first
            'unread'  => collect($live)->where('unread', true)->count() + collect($saved)->where('unread', true)->count(),
        ]);
    }

    /**
     * GET /notifications/all?show=all|unread|work|messages&module=&q=&page=   the Notifications page
     *   work      = live items (counted from the data, gone when done)
     *   messages  = every saved notification of mine (no 7-day limit), 30 per page, newest first
     */
    public function all(Request $request)
    {
        $who = $this->who($request);
        if (!$who) return response()->json(['enabled' => false, 'work' => [], 'messages' => [], 'counts' => [], 'modules' => [], 'page' => 1, 'pages' => 1]);
        [$emp, $designation, $caid] = $who;
        $d = $request->validate([
            'show'   => ['nullable', 'in:all,unread,work,messages'],
            'module' => ['nullable', 'string', 'max:40'],
            'q'      => ['nullable', 'string', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ]);
        $show = $d['show'] ?? 'all';
        $term = mb_strtolower(trim($d['q'] ?? ''));
        $per = 30;
        $branches = $this->branchNames();

        // work to do (live)
        $work = $this->withSeen($emp, $this->live($emp, $designation, $caid, $branches));
        $workUnread = collect($work)->where('unread', true)->count();
        $workAll = count($work);
        $work = collect($work)
            ->when($show === 'unread', fn ($c) => $c->where('unread', true))
            ->when($term !== '', fn ($c) => $c->filter(fn ($i) => str_contains(mb_strtolower($i['text'] . ' ' . $i['sub']), $term)))
            ->values()->all();
        if ($show === 'messages' || ($d['module'] ?? '') !== '') $work = [];

        // saved messages
        $messages = [];
        $total = 0;
        $msgUnread = 0;
        $modules = [];
        if (Schema::hasTable('t_notification')) {
            $base = DB::table('t_notification')->where('to_emp_id', $emp)
                ->when($caid, fn ($q) => $q->where(fn ($w) => $w->whereNull('ca_id')->orWhere('ca_id', 0)->orWhere('ca_id', $caid)));
            $msgUnread = (clone $base)->where('is_read', 0)->count();
            $modules = (clone $base)->whereNotNull('module')->distinct()->orderBy('module')->pluck('module')->all();
            $q = (clone $base)
                ->when($show === 'unread', fn ($q) => $q->where('is_read', 0))
                ->when(($d['module'] ?? '') !== '', fn ($q) => $q->where('module', $d['module']))
                ->when($term !== '', fn ($q) => $q->where('message', 'like', '%' . addcslashes($term, '%_\\') . '%'));
            if ($show !== 'work') {
                $total = (clone $q)->count();
                $messages = $q->orderByDesc('id')->forPage((int) ($d['page'] ?? 1), $per)->get()
                    ->map(fn ($n) => [
                        'key'    => 'n' . $n->id,
                        'id'     => (int) $n->id,
                        'kind'   => 'saved',
                        'module' => (string) $n->module,
                        'tone'   => (int) $n->is_read ? 'gray' : 'blue',
                        'text'   => (string) $n->message,
                        'sub'    => $n->ca_id ? ($branches[(int) $n->ca_id] ?? '') : '',
                        'ca_id'  => ((int) $n->ca_id) ?: null,
                        'to'     => $this->link($n->module, (string) $n->message, (string) $n->reference),
                        'time'   => $this->iso($n->created_at),
                        'unread' => !(int) $n->is_read,
                    ])->all();
            }
        }

        return response()->json([
            'enabled'  => true,
            'work'     => $work,
            'messages' => $messages,
            'modules'  => $modules,
            'counts'   => ['work' => $workAll, 'work_unread' => $workUnread,
                'messages_unread' => $msgUnread, 'unread' => $workUnread + $msgUnread],
            'page'     => (int) ($d['page'] ?? 1),
            'pages'    => max(1, (int) ceil($total / $per)),
            'total'    => $total,
        ]);
    }

    /**
     * GET /notifications/pulse   tiny check every 15 s: { sig, unread }
     * sig changes whenever a count or a saved notification changes → the bell reloads (and shows a toast for new ones).
     * Cached 10 s per employee so many open browsers don't load the database.
     */
    public function pulse(Request $request)
    {
        $who = $this->who($request);
        if (!$who) return response()->json(['sig' => '', 'unread' => 0]);
        [$emp, $designation, $caid] = $who;
        return response()->json(\Illuminate\Support\Facades\Cache::remember("ntf:pulse:{$emp}:" . ($caid ?? 0), 10, function () use ($emp, $designation, $caid) {
            $live = $this->withSeen($emp, $this->live($emp, $designation, $caid, []));
            $savedUnread = 0;
            $lastId = 0;
            if (Schema::hasTable('t_notification')) {
                $base = DB::table('t_notification')->where('to_emp_id', $emp)
                    ->when($caid, fn ($q) => $q->where(fn ($w) => $w->whereNull('ca_id')->orWhere('ca_id', 0)->orWhere('ca_id', $caid)));
                $savedUnread = (clone $base)->where('is_read', 0)->count();
                $lastId = (int) (clone $base)->max('id');
            }
            $counts = collect($live)->mapWithKeys(fn ($i) => [$i['key'] => $i['n']])->sortKeys()->all();
            return [
                'sig'    => md5(json_encode([$counts, $lastId, $savedUnread])),
                'unread' => collect($live)->where('unread', true)->count() + $savedUnread,
            ];
        }));
    }

    /* PUT /notifications/read  { key }   one item: saved "n<id>" → t_notification.is_read, live → t_notification_live_read */
    public function read(Request $request)
    {
        [$emp, $designation, $caid] = $this->who($request) ?? abort(403, 'Not available for this login.');
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:60']])['key'];

        if (preg_match('/^n(\d+)$/', $key, $m)) {
            DB::table('t_notification')->where('id', (int) $m[1])->where('to_emp_id', $emp)->update(['is_read' => 1, 'updated_at' => now()]);
        } else {
            $item = collect($this->live($emp, $designation, $caid, []))->firstWhere('key', $key);
            if ($item) $this->markSeen($emp, [$item]);
        }
        \Illuminate\Support\Facades\Cache::forget("ntf:pulse:{$emp}:" . ($caid ?? 0));
        return response()->json(['message' => 'Marked as read.']);
    }

    /* PUT /notifications/read-all */
    public function readAll(Request $request)
    {
        [$emp, $designation, $caid] = $this->who($request) ?? abort(403, 'Not available for this login.');
        if (Schema::hasTable('t_notification')) {
            DB::table('t_notification')->where('to_emp_id', $emp)->where('is_read', 0)
                ->when($caid, fn ($q) => $q->where(fn ($w) => $w->whereNull('ca_id')->orWhere('ca_id', 0)->orWhere('ca_id', $caid)))
                ->update(['is_read' => 1, 'updated_at' => now()]);
        }
        $this->markSeen($emp, $this->live($emp, $designation, $caid, []));
        \Illuminate\Support\Facades\Cache::forget("ntf:pulse:{$emp}:" . ($caid ?? 0));
        return response()->json(['message' => 'All marked as read.']);
    }

    /* ---------------- read state of live items (t_notification_live_read) ---------------- */

    /** remember the count I have seen for each item */
    private function markSeen(int $emp, array $items): void
    {
        if (!Schema::hasTable('t_notification_live_read')) return;
        foreach ($items as $it) {
            DB::table('t_notification_live_read')->updateOrInsert(
                ['employee_id' => $emp, 'item_key' => $it['key']],
                ['seen_count' => $it['n'], 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    /**
     * unread = the count is higher than what I saw.
     * Done work lowers the saved count (or removes it), so new work shows as unread again.
     */
    private function withSeen(int $emp, array $items): array
    {
        if (!Schema::hasTable('t_notification_live_read')) return $items;
        $seen = DB::table('t_notification_live_read')->where('employee_id', $emp)->pluck('seen_count', 'item_key')->map(fn ($v) => (int) $v)->all();
        $now = collect($items)->pluck('n', 'key')->all();

        foreach ($seen as $key => $count) {
            if (!isset($now[$key])) {
                DB::table('t_notification_live_read')->where('employee_id', $emp)->where('item_key', $key)->delete();
            } elseif ($now[$key] < $count) {
                DB::table('t_notification_live_read')->where('employee_id', $emp)->where('item_key', $key)->update(['seen_count' => $now[$key], 'updated_at' => now()]);
                $seen[$key] = $now[$key];
            }
        }
        return array_map(fn ($it) => ['unread' => $it['n'] > ($seen[$it['key']] ?? 0)] + $it, $items);
    }

    /* ---------------- saved (t_notification) ---------------- */

    private function saved(int $emp, ?int $caid, array $branches): array
    {
        if (!Schema::hasTable('t_notification')) return [];
        return DB::table('t_notification')->where('to_emp_id', $emp)
            ->when($caid, fn ($q) => $q->where(fn ($w) => $w->whereNull('ca_id')->orWhere('ca_id', 0)->orWhere('ca_id', $caid)))
            ->where(fn ($w) => $w->where('is_read', 0)->orWhere('created_at', '>=', now()->subDays(7)))   // unread + last 7 days
            ->orderBy('is_read')->orderByDesc('id')->limit(self::MAX)->get()
            ->map(fn ($n) => [
                'key'    => 'n' . $n->id,
                'id'     => (int) $n->id,
                'kind'   => 'saved',
                'module' => (string) $n->module,
                'tone'   => (int) $n->is_read ? 'gray' : 'blue',
                'text'   => (string) $n->message,
                'sub'    => $n->ca_id ? ($branches[(int) $n->ca_id] ?? '') : '',
                'ca_id'  => ((int) $n->ca_id) ?: null,
                'to'     => $this->link($n->module, (string) $n->message, (string) $n->reference),
                'time'   => $this->iso($n->created_at),
                'unread' => !(int) $n->is_read,
            ])->all();
    }

    /* ---------------- live (counted from the data) ---------------- */

    private function live(int $emp, int $designation, ?int $caid, array $branches): array
    {
        $items = [];
        $daIds = $designation === self::DA ? $this->myAccounts($emp, ['assigned_daid'], $caid) : [];
        $auIds = in_array($designation, [self::AUDITOR, self::AUDIT_SUPERVISOR], true)
            ? $this->myAccounts($emp, ['assigned_auid', 'assigned_auditor_id'], $caid) : [];

        // DA: scans waiting (t_mobile_transaction.trans_status 0)
        foreach ($this->pendingScans($daIds) as $r) {
            $items[] = $this->item("scan{$r->ca}{$r->type}", 'orange',
                "{$r->n} pending scanned " . $this->plural($r->type, $r->n), $branches[$r->ca] ?? '', $r->ca,
                "/task/{$r->type}/scanned-receipts", $r->t);
        }
        // DA: reverted by the auditor → fix
        foreach ($this->invoiceCounts($daIds, 'reverted') as $r) {
            $items[] = $this->item("rev{$r->ca}{$r->type}", 'red',
                "{$r->n} reverted " . $this->plural($r->type, $r->n) . ' to fix', $branches[$r->ca] ?? '', $r->ca, "/task/{$r->type}", $r->t);
        }
        // Auditor: for audit
        foreach ($this->invoiceCounts($auIds, 'pending') as $r) {
            $items[] = $this->item("aud{$r->ca}{$r->type}", 'blue',
                "{$r->n} " . $this->plural($r->type, $r->n) . ' for audit', $branches[$r->ca] ?? '', $r->ca, "/task/{$r->type}", $r->t);
        }
        // HR approvers (Staff logins only)
        if ($caid === null && in_array($designation, self::HR_APPROVERS, true)) {
            foreach (['t_leave_request' => ['leave', '/hr/leaves'], 't_overtime' => ['overtime', '/hr/overtime']] as $t => [$what, $to]) {
                if (!in_array('status', $this->cols($t), true)) continue;
                $r = DB::table("{$t} as A")->where('A.status', 'pending')->selectRaw('COUNT(*) AS n, ' . $this->newest($t) . ' AS t')->first();
                $n = (int) ($r->n ?? 0);
                if ($n) $items[] = $this->item("hr{$what}", 'orange', "{$n} {$what} request" . ($n > 1 ? 's' : '') . ' waiting for approval', 'HR', null, $to, $r->t);
            }
        }

        // management: tickets · reverted · Billing EO (Staff logins only)
        if ($caid === null && in_array($designation, self::MANAGEMENT, true)) {
            foreach ($this->pendingTickets() as $type => [$n, $t]) {
                $items[] = $this->item("tkt{$type}", 'orange', "{$n} " . $this->plural($type, $n) . ' ticket' . ($n > 1 ? 's' : '') . ' waiting for approval',
                    'Ticket', null, '/administration/admin-ticket', $t);
            }
            foreach ($this->allReverted() as $type => [$n, $t]) {
                $items[] = $this->item("allrev{$type}", 'red', "{$n} reverted " . $this->plural($type, $n), 'All clients · Reverted', null, '/monitoring/efficiency', $t);
            }
            if ($eo = $this->unpaidEo()) {
                // opens Billing → Dashboard already filled: the account with the biggest unpaid EO balance, Type = EO, Status = Unpaid + partial
                $items[] = $this->item('billeo', 'blue', "{$eo['n']} unpaid EO bill" . ($eo['n'] > 1 ? 's' : '') . ' · ₱' . number_format($eo['amount'], 2),
                    'Billing · EO', null, $this->billingEoLink($eo['top']), $eo['t']);
            }
        }

        return $this->byLatest($items);
    }

    /** "MAX(A.updated_at)" – the newest change of a table's rows (updated_at, else created_at), or NULL */
    private function newest(string $table, string $alias = 'A'): string
    {
        $col = $this->pick($table, ['updated_at', 'created_at', 'date_created', 'date_added']);
        return $col ? "MAX({$alias}.{$col})" : 'NULL';
    }

    /** newest first (items without a time last); same time → red, orange, blue */
    private function byLatest(array $items): array
    {
        usort($items, function ($a, $b) {
            $ta = $a['time'] ? strtotime($a['time']) : 0;
            $tb = $b['time'] ? strtotime($b['time']) : 0;
            return [$tb, self::TONE_ORDER[$a['tone']] ?? 9] <=> [$ta, self::TONE_ORDER[$b['tone']] ?? 9];
        });
        return $items;
    }

    private const TONE_ORDER = ['red' => 0, 'orange' => 1, 'blue' => 2, 'gray' => 3];

    private function item(string $key, string $tone, string $text, string $sub, ?int $caId, string $to, $time = null): array
    {
        return ['key' => $key, 'id' => null, 'kind' => 'live', 'module' => 'live', 'tone' => $tone, 'text' => $text,
            'sub' => $sub, 'ca_id' => $caId, 'to' => $to, 'time' => $this->iso($time), 'unread' => true,
            'n' => (int) preg_replace('/\D.*/', '', $text)];
    }

    private function plural(string $type, int $n): string
    {
        return match ($type) {
            'sales'     => $n > 1 ? 'sales invoices' : 'sales invoice',
            'purchases' => $n > 1 ? 'purchases' : 'purchase',
            'expenses'  => $n > 1 ? 'expenses' : 'expense',
            default     => $type,
        };
    }

    /** m_client_account ids where <col> = me (UserClientStaff: only its own account) */
    private function myAccounts(int $emp, array $cols, ?int $caid): array
    {
        $col = $this->pick('m_client_account', $cols);
        if (!$col) return [];
        return DB::table('m_client_account')->where($col, $emp)
            ->when($caid, fn ($q) => $q->where('id', $caid))
            ->where(fn ($w) => $w->whereNull('is_active')->orWhere('is_active', 1))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** caid column of m_user_client (invoice / scan → account: added_by = m_user_client.id) */
    private function userCa(): string
    {
        return $this->pick('m_user_client', ['caid', 'ca_id', 'client_account_id']) ?? 'caid';
    }

    private function pendingScans(array $caIds): array
    {
        if (!$caIds || !$this->cols('t_mobile_transaction')) return [];
        $uca = $this->userCa();
        return DB::table('t_mobile_transaction as A')
            ->join('m_user_client as U', 'U.id', '=', 'A.added_by')
            ->whereIn("U.{$uca}", $caIds)->where('A.trans_status', 0)
            ->whereIn(DB::raw('LOWER(TRIM(A.trans_type))'), array_keys(self::TYPES))
            ->groupBy("U.{$uca}", DB::raw('LOWER(TRIM(A.trans_type))'))
            ->get([DB::raw("U.{$uca} AS ca"), DB::raw('LOWER(TRIM(A.trans_type)) AS type'), DB::raw('COUNT(*) AS n'), DB::raw($this->newest('t_mobile_transaction') . ' AS t')])
            ->map(fn ($r) => (object) ['ca' => (int) $r->ca, 'type' => $r->type, 'n' => (int) $r->n, 't' => $r->t])->all();
    }

    /** posted, not void invoices per account × type: pending = audit status 1 / empty, reverted = 3 */
    private function invoiceCounts(array $caIds, string $which): array
    {
        if (!$caIds) return [];
        $uca = $this->userCa();
        $out = [];
        foreach (['t_sales_invoice_master' => "'sales'", 't_purchase_expense_master' => 'LOWER(TRIM(A.trans_type))'] as $t => $type) {
            $c = $this->cols($t);
            if (!in_array('audit_status_id', $c, true)) continue;
            $q = DB::table("{$t} as A")->join('m_user_client as U', 'U.id', '=', 'A.added_by')->whereIn("U.{$uca}", $caIds);
            if (in_array('invoice_status_id', $c, true)) $q->whereRaw('COALESCE(A.invoice_status_id, 0) <> ?', [self::INVOICE_VOID]);
            if (in_array('record_status', $c, true)) $q->where('A.record_status', 'posted');
            $which === 'reverted'
                ? $q->where('A.audit_status_id', self::AUDIT_REVERTED)
                : $q->whereRaw('COALESCE(A.audit_status_id, ?) = ?', [self::AUDIT_PENDING, self::AUDIT_PENDING]);
            $group = str_starts_with($type, "'") ? ["U.{$uca}"] : ["U.{$uca}", DB::raw($type)];
            foreach ($q->groupBy(...$group)->get([DB::raw("U.{$uca} AS ca"), DB::raw("{$type} AS type"), DB::raw('COUNT(*) AS n'), DB::raw($this->newest($t) . ' AS t')]) as $r) {
                if (!isset(self::TYPES[$r->type])) continue;
                $out[] = (object) ['ca' => (int) $r->ca, 'type' => $r->type, 'n' => (int) $r->n, 't' => $r->t];
            }
        }
        return $out;
    }

    /** caid → "BRANCHCODE Trade name" */
    private function branchNames(): array
    {
        return DB::table('m_client_account')->get(['id', 'branch_code', 'trade_name'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => trim(trim((string) $a->branch_code) . ' ' . trim((string) $a->trade_name))])->all();
    }

    /* ---------------- management items ---------------- */

    /** pending tickets (t_request_ticket.is_status 0) by type of the invoice: sales / purchases / expenses */
    private function pendingTickets(): array
    {
        if (!$this->cols('t_request_ticket')) return [];
        // a ticket per reference: count the tickets, not the invoices
        $per = DB::table('t_request_ticket as A')->where('A.is_status', 0)->whereNotNull('A.reference_no')
            ->groupBy('A.reference_no')->get([DB::raw('A.reference_no AS ref'), DB::raw('COUNT(*) AS n'), DB::raw($this->newest('t_request_ticket') . ' AS t')]);
        $refs = $per->pluck('ref')->all() ?: [''];
        $out = [];
        $sales = DB::table('t_sales_invoice_master')->whereIn('invoice_no', $refs)->distinct()->pluck('invoice_no')->flip();
        $ap = DB::table('t_purchase_expense_master')->whereIn('invoice_no', $refs)->pluck('trans_type', 'invoice_no');
        foreach ($per as $r) {
            $type = isset($sales[$r->ref]) ? 'sales'
                : (isset($ap[$r->ref]) ? (strtolower(trim((string) $ap[$r->ref])) === 'expenses' ? 'expenses' : 'purchases') : null);
            if (!$type) continue;
            $out[$type] ??= [0, null];
            $out[$type][0] += (int) $r->n;
            if ($r->t && (!$out[$type][1] || $r->t > $out[$type][1])) $out[$type][1] = $r->t;
        }
        return $out;                                         // type => [count, newest time]
    }

    /** reverted (audit status 3), posted, not void invoices of every client, by type */
    private function allReverted(): array
    {
        $out = [];
        foreach (['t_sales_invoice_master' => "'sales'", 't_purchase_expense_master' => 'LOWER(TRIM(A.trans_type))'] as $t => $type) {
            $c = $this->cols($t);
            if (!in_array('audit_status_id', $c, true)) continue;
            $q = DB::table("{$t} as A")->where('A.audit_status_id', self::AUDIT_REVERTED);
            if (in_array('invoice_status_id', $c, true)) $q->whereRaw('COALESCE(A.invoice_status_id, 0) <> ?', [self::INVOICE_VOID]);
            if (in_array('record_status', $c, true)) $q->where('A.record_status', 'posted');
            $sel = [DB::raw("{$type} AS type"), DB::raw('COUNT(*) AS n'), DB::raw($this->newest($t) . ' AS t')];
            $rows = str_starts_with($type, "'") ? collect([$q->first($sel)]) : $q->groupBy(DB::raw($type))->get($sel);
            foreach ($rows as $r) {
                if (!$r || !(int) $r->n || !isset(self::TYPES[$r->type])) continue;
                $out[$r->type] = [(int) $r->n, $r->t];
            }
        }
        return $out;                                         // type => [count, newest time]
    }

    /** EO (non-recurring) bills still unpaid / partly paid: count + balance */
    /** EO (non-recurring) bills still unpaid / partly paid: count + balance */
    private function unpaidEo(): ?array
    {
        if (!$this->cols('t_service') || !$this->cols('m_service_list')) return null;
        $fee = "(COALESCE(S.total_service_fee,0) + COALESCE(S.inherited_vat,0) - COALESCE(S.inherited_wh,0) - COALESCE(S.inherited_discount_amount,0))";
        $paid = DB::table('t_payment')->whereNotNull('service_id')->groupBy('service_id')->selectRaw('service_id, SUM(amount) AS paid');
        $r = DB::table('t_service as S')
            ->join('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoinSub($paid, 'P', 'P.service_id', '=', 'S.id')
            ->where('L.ser_type_id', self::EO_SER_TYPE)
            ->whereIn('S.invoice_status_id', [self::SVC_UNPAID, self::SVC_PARTIAL])
            ->where('S.total_service_fee', '>', 0)
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(GREATEST({$fee} - COALESCE(P.paid, 0), 0)), 0) AS amount, " . $this->newest('t_service', 'S') . ' AS t')
            ->first();
        if (!$r || (int) $r->n <= 0) return null;

        // the account that owes the most EO – the bell opens Billing on it
        $top = DB::table('t_service as S')
            ->join('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoinSub($paid, 'P', 'P.service_id', '=', 'S.id')
            ->where('L.ser_type_id', self::EO_SER_TYPE)
            ->whereIn('S.invoice_status_id', [self::SVC_UNPAID, self::SVC_PARTIAL])
            ->where('S.total_service_fee', '>', 0)
            ->groupBy('S.ca_id')
            ->orderByRaw("SUM(GREATEST({$fee} - COALESCE(P.paid, 0), 0)) DESC")
            ->value('S.ca_id');

        return ['n' => (int) $r->n, 'amount' => round((float) $r->amount, 2), 't' => $r->t, 'top' => $top ? (int) $top : null];
    }

    /** Billing → Dashboard filled in: account, Type = EO, Status = Unpaid + partial */
    private function billingEoLink(?int $caId): string
    {
        return '/billing/dashboard?' . http_build_query(array_filter([
            'ca_id'  => $caId,
            'groups' => 'eo',
            'status' => 'open',
        ]));
    }

    /* ---------------- GET /notifications/detail?key=   the list behind a "work to do" card ---------------- */

    public function detail(Request $request)
    {
        $who = $this->who($request) ?? abort(403, 'Not available for this login.');
        [$emp, $designation, $caid] = $who;
        $key = (string) $request->validate(['key' => ['required', 'string', 'max:60']])['key'];
        $branches = $this->branchNames();
        $mgmt = $caid === null && in_array($designation, self::MANAGEMENT, true);

        // tickets waiting for approval (one type)
        if (preg_match('/^tkt(sales|purchases|expenses)$/', $key, $m) && $mgmt) {
            $type = $m[1];
            $t = DB::table('t_request_ticket')->where('is_status', 0)->orderBy('id')->limit(500)
                ->get(['id', 'ticket_no', 'reference_no', 'action', 'remarks', 'module', 'request_by', 'created_at']);
            $refs = $t->pluck('reference_no')->filter()->unique()->values()->all() ?: [''];
            $sales = DB::table('t_sales_invoice_master')->whereIn('invoice_no', $refs)->pluck('added_by', 'invoice_no');
            $ap = DB::table('t_purchase_expense_master')->whereIn('invoice_no', $refs)->get(['invoice_no', 'trans_type', 'added_by'])->keyBy('invoice_no');
            $uca = $this->userCa();
            $owner = DB::table('m_user_client')->whereIn('id', $sales->values()->merge($ap->pluck('added_by'))->filter()->unique()->all() ?: [0])->pluck($uca, 'id');
            $rows = [];
            foreach ($t as $r) {
                $kind = isset($sales[$r->reference_no]) ? 'sales'
                    : (isset($ap[$r->reference_no]) ? (strtolower(trim((string) $ap[$r->reference_no]->trans_type)) === 'expenses' ? 'expenses' : 'purchases') : null);
                if ($kind !== $type) continue;
                $by = $kind === 'sales' ? $sales[$r->reference_no] : $ap[$r->reference_no]->added_by;
                $ca = (int) ($owner[$by] ?? 0);
                $rows[] = [
                    'title' => trim(strtoupper((string) $r->action) . ' · ' . $r->reference_no),
                    'sub'   => trim(($branches[$ca] ?? '') . ($r->remarks ? ' · ' . $r->remarks : '')),
                    'meta'  => $r->ticket_no,
                    'time'  => $this->iso($r->created_at),
                    'ca_id' => $ca ?: null,
                    'to'    => '/administration/admin-ticket',
                ];
            }
            return response()->json(['rows' => $rows, 'note' => 'Oldest first – approve or decline in Administration → Ticket.']);
        }

        // reverted invoices per client (all clients, or my clients for a DA)
        if (preg_match('/^(allrev|rev\d+)(sales|purchases|expenses)$/', $key, $m)) {
            $type = $m[2];
            $caIds = $m[1] === 'allrev' ? null : [(int) substr($m[1], 3)];
            if ($caIds === null && !$mgmt) abort(403);
            $uca = $this->userCa();
            $t = $type === 'sales' ? 't_sales_invoice_master' : 't_purchase_expense_master';
            $c = $this->cols($t);
            $q = DB::table("{$t} as A")->join('m_user_client as U', 'U.id', '=', 'A.added_by')->where('A.audit_status_id', self::AUDIT_REVERTED);
            if ($type !== 'sales') $q->whereRaw('LOWER(TRIM(A.trans_type)) = ?', [$type]);
            if (in_array('invoice_status_id', $c, true)) $q->whereRaw('COALESCE(A.invoice_status_id, 0) <> ?', [self::INVOICE_VOID]);
            if (in_array('record_status', $c, true)) $q->where('A.record_status', 'posted');
            if ($caIds) $q->whereIn("U.{$uca}", $caIds);
            $at = in_array('updated_at', $c, true) ? 'A.updated_at' : (in_array('created_at', $c, true) ? 'A.created_at' : null);
            $rows = $q->groupBy("U.{$uca}")
                ->get([DB::raw("U.{$uca} AS ca"), DB::raw('COUNT(*) AS n'), DB::raw($at ? "MIN({$at}) AS oldest" : 'NULL AS oldest')])
                ->sortByDesc('n')->values()->take(200)
                ->map(fn ($r) => [
                    'title' => $branches[(int) $r->ca] ?? ('Account #' . $r->ca),
                    'sub'   => $r->oldest ? 'oldest since ' . substr((string) $r->oldest, 0, 10) : '',
                    'meta'  => (int) $r->n,
                    'time'  => null,
                    'ca_id' => (int) $r->ca,
                    'to'    => "/task/{$type}",
                ])->all();
            return response()->json(['rows' => $rows, 'note' => 'Per client account, most first – opening one switches to that branch.']);
        }

        // unpaid EO bills per client
        if ($key === 'billeo' && $mgmt) {
            $fee = "(COALESCE(S.total_service_fee,0) + COALESCE(S.inherited_vat,0) - COALESCE(S.inherited_wh,0) - COALESCE(S.inherited_discount_amount,0))";
            $paid = DB::table('t_payment')->whereNotNull('service_id')->groupBy('service_id')->selectRaw('service_id, SUM(amount) AS paid');
            $rows = DB::table('t_service as S')
                ->join('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
                ->leftJoinSub($paid, 'P', 'P.service_id', '=', 'S.id')
                ->where('L.ser_type_id', self::EO_SER_TYPE)
                ->whereIn('S.invoice_status_id', [self::SVC_UNPAID, self::SVC_PARTIAL])
                ->where('S.total_service_fee', '>', 0)
                ->groupBy('S.ca_id')
                ->get([DB::raw('S.ca_id AS ca'), DB::raw('COUNT(*) AS n'), DB::raw("COALESCE(SUM(GREATEST({$fee} - COALESCE(P.paid, 0), 0)), 0) AS bal")])
                ->sortByDesc('bal')->values()->take(200)
                ->map(fn ($r) => [
                    'title' => $branches[(int) $r->ca] ?? ('Account #' . $r->ca),
                    'sub'   => (int) $r->n . ' unpaid EO bill' . ((int) $r->n > 1 ? 's' : ''),
                    'meta'  => '₱' . number_format((float) $r->bal, 2),
                    'time'  => null,
                    'ca_id' => null,
                    'to'    => $this->billingEoLink((int) $r->ca),
                ])->all();
            return response()->json(['rows' => $rows, 'note' => 'Per client account, biggest balance first.']);
        }

        return response()->json(['rows' => [], 'note' => '']);
    }
}