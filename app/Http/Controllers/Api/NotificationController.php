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
 *          (read = t_notification_seen: unread again when the count goes up):
 *            DA (3)                      pending scans · reverted sales / purchases / expenses   (assigned_daid = me)
 *            Auditor (5) / Supervisor (10) sales / purchases / expenses for audit              (assigned_auid = me)
 *            CEO / Supervisor / IT / CFO   leave and overtime requests waiting for approval
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
        if ($rows) DB::table('t_notification')->insert($rows);
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
            'items'   => array_slice(array_merge($live, $saved), 0, self::MAX),
            'unread'  => collect($live)->where('unread', true)->count() + collect($saved)->where('unread', true)->count(),
        ]);
    }

    /* PUT /notifications/read  { key }   one item: saved "n<id>" → t_notification.is_read, live → t_notification_seen */
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
        return response()->json(['message' => 'All marked as read.']);
    }

    /* ---------------- read state of live items (t_notification_seen) ---------------- */

    /** remember the count I have seen for each item */
    private function markSeen(int $emp, array $items): void
    {
        if (!Schema::hasTable('t_notification_seen')) return;
        foreach ($items as $it) {
            DB::table('t_notification_seen')->updateOrInsert(
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
        if (!Schema::hasTable('t_notification_seen')) return $items;
        $seen = DB::table('t_notification_seen')->where('employee_id', $emp)->pluck('seen_count', 'item_key')->map(fn ($v) => (int) $v)->all();
        $now = collect($items)->pluck('n', 'key')->all();

        foreach ($seen as $key => $count) {
            if (!isset($now[$key])) {
                DB::table('t_notification_seen')->where('employee_id', $emp)->where('item_key', $key)->delete();
            } elseif ($now[$key] < $count) {
                DB::table('t_notification_seen')->where('employee_id', $emp)->where('item_key', $key)->update(['seen_count' => $now[$key], 'updated_at' => now()]);
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
                'time'   => (string) $n->created_at,
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
                "/task/{$r->type}/scanned-receipts");
        }
        // DA: reverted by the auditor → fix
        foreach ($this->invoiceCounts($daIds, 'reverted') as $r) {
            $items[] = $this->item("rev{$r->ca}{$r->type}", 'red',
                "{$r->n} reverted " . $this->plural($r->type, $r->n) . ' to fix', $branches[$r->ca] ?? '', $r->ca, "/task/{$r->type}");
        }
        // Auditor: for audit
        foreach ($this->invoiceCounts($auIds, 'pending') as $r) {
            $items[] = $this->item("aud{$r->ca}{$r->type}", 'blue',
                "{$r->n} " . $this->plural($r->type, $r->n) . ' for audit', $branches[$r->ca] ?? '', $r->ca, "/task/{$r->type}");
        }
        // HR approvers (Staff logins only)
        if ($caid === null && in_array($designation, self::HR_APPROVERS, true)) {
            foreach (['t_leave_request' => ['leave', '/hr/leaves'], 't_overtime' => ['overtime', '/hr/overtime']] as $t => [$what, $to]) {
                if (!in_array('status', $this->cols($t), true)) continue;
                $n = DB::table($t)->where('status', 'pending')->count();
                if ($n) $items[] = $this->item("hr{$what}", 'orange', "{$n} {$what} request" . ($n > 1 ? 's' : '') . ' waiting for approval', 'HR', null, $to);
            }
        }

        usort($items, fn ($a, $b) => [self::TONE_ORDER[$a['tone']], -$a['n']] <=> [self::TONE_ORDER[$b['tone']], -$b['n']]);
        return $items;
    }

    private const TONE_ORDER = ['red' => 0, 'orange' => 1, 'blue' => 2, 'gray' => 3];

    private function item(string $key, string $tone, string $text, string $sub, ?int $caId, string $to): array
    {
        return ['key' => $key, 'id' => null, 'kind' => 'live', 'module' => 'live', 'tone' => $tone, 'text' => $text,
            'sub' => $sub, 'ca_id' => $caId, 'to' => $to, 'time' => null, 'unread' => true,
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
            ->get([DB::raw("U.{$uca} AS ca"), DB::raw('LOWER(TRIM(A.trans_type)) AS type'), DB::raw('COUNT(*) AS n')])
            ->map(fn ($r) => (object) ['ca' => (int) $r->ca, 'type' => $r->type, 'n' => (int) $r->n])->all();
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
            foreach ($q->groupBy(...$group)->get([DB::raw("U.{$uca} AS ca"), DB::raw("{$type} AS type"), DB::raw('COUNT(*) AS n')]) as $r) {
                if (!isset(self::TYPES[$r->type])) continue;
                $out[] = (object) ['ca' => (int) $r->ca, 'type' => $r->type, 'n' => (int) $r->n];
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
}