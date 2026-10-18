<?php

namespace App\Http\Controllers\Api;

use App\Services\Bir2307;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row menu extras on the Sales / Purchases / Expenses lists:
 *   GET /api/{sales|purchases|expenses}/invoice/{invoiceNo}/2307   → BIR 2307 PDF (old merge.php)
 *   GET /api/{sales|purchases|expenses}/invoice/{invoiceNo}/logs   → what happened to the invoice
 *     (incl. audit history and comments: t_audit_history, t_transaction_comment)
 * Extends ReportController for journalScope() (branch) and companyHeader() (name, address, TIN).
 */
class InvoiceExtrasController extends ReportController
{
    private function master(string $type): string
    {
        return $type === 'sales' ? 't_sales_invoice_master' : 't_purchase_expense_master';
    }

    private function findInvoice(Request $request, string $type, string $invoiceNo): array
    {
        [$ids, $client] = $this->journalScope($request, 'current');
        $q = DB::table($this->master($type))->whereIn('added_by', $ids ?: [0])->where('invoice_no', $invoiceNo);
        if ($type !== 'sales') $q->whereRaw('LOWER(trans_type) = ?', [$type]);
        return [$q->first(), $client];
    }

    private function partyOf(string $type, object $inv): ?object
    {
        $col = $type === 'sales' ? 'customer_id' : (property_exists($inv, 'vendor_id') ? 'vendor_id' : 'customer_id');
        $id = $inv->$col ?? null;
        return $id ? DB::table('m_vendor_customer')->where('id', $id)->first() : null;
    }

    private function atc(?int $id): ?object
    {
        return $id ? DB::table('m_atc')->where('id', $id)->first() : null;
    }

    private static function rate($r): float
    {
        $r = (float) $r;
        return $r > 1 ? $r / 100 : $r;      // 1 or 0.01 both mean 1%
    }

    /* =====================================================================
       GET /api/{type}/invoice/{invoiceNo}/2307
       Sales:              payee = your company, payor = the customer (the customer withholds)
       Purchases/Expenses: payee = the vendor,   payor = your company
       ===================================================================== */
    public function form2307(Request $request, string $type, string $invoiceNo)
    {
        [$inv, $client] = $this->findInvoice($request, $type, $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $it = $this->atc($inv->atc_it_id ?? null);
        $vt = $this->atc($inv->atc_vt_id ?? null);
        if (!$it && !$vt) return response()->json(['message' => 'This invoice has no withholding tax (ATC), so there is no 2307.'], 422);

        $company = $this->companyHeader($client);
        $party = $this->partyOf($type, $inv);
        $me = ['tin' => $company['tin'] ?? '', 'name' => $company['name'] ?? ($company['trade_name'] ?? ''), 'address' => $company['address'] ?? ''];
        $them = [
            'tin' => trim(($party->tin ?? '') . (!empty($party->branch_code) ? '-' . $party->branch_code : '')),
            'name' => $party->name ?? '',
            'address' => trim(implode(', ', array_filter([$party->address ?? null, $party->city_country ?? null]))),
        ];
        [$payee, $payor] = $type === 'sales' ? [$me, $them] : [$them, $me];

        // period = the declared month; amount goes in month 1, 2 or 3 of the quarter
        $ym = substr((string) ($inv->declared_month ?: $inv->transaction_date), 0, 7);
        $start = Carbon::createFromFormat('Y-m-d', $ym . '-01');
        $slot = 'm' . ((($start->month - 1) % 3) + 1);
        $base = round((float) $inv->sub_total, 2);

        $row = function (?object $atc, string $col) use ($inv, $base, $slot) {
            if (!$atc) return [];
            $saved = round((float) ($inv->{$col} ?? 0), 2);
            return [[
                'desc' => $atc->description ?? $atc->name, 'atc' => $atc->name,
                'm1' => 0, 'm2' => 0, 'm3' => 0, $slot => $base,
                'tax' => $saved > 0 ? $saved : round($base * self::rate($atc->rate ?? 0), 2),
            ]];
        };

        try {
            $pdf = Bir2307::pdf([
                'from' => $start->toDateString(), 'to' => $start->copy()->endOfMonth()->toDateString(),
                'payee' => $payee, 'payor' => $payor,
                'income' => $row($it, '2307_it'),
                'business' => $row($vt, '2307_vt'),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 500);
        }

        $name = '2307-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $inv->document_no ?: $inv->invoice_no) . '.pdf';
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /* =====================================================================
       GET /api/{type}/invoice/{invoiceNo}/logs
       created · scanned · tickets (EDIT / VOID / CANCEL / declared month) · audit · collections / payments
       ===================================================================== */
    public function logs(Request $request, string $type, string $invoiceNo)
    {
        [$inv] = $this->findInvoice($request, $type, $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $events = [];
        $add = function ($at, string $kind, string $title, ?string $detail = null, ?string $by = null, ?string $tone = null) use (&$events) {
            if (!$at) return;
            $events[] = ['at' => Carbon::parse($at)->toDateTimeString(), 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'by' => $by, 'tone' => $tone];
        };
        $userName = function ($id) {
            if (!$id) return null;
            foreach (['m_user_staff' => 'username', 'm_user_client' => 'username', 'm_user_client_staff' => 'username'] as $t => $c) {
                if (Schema::hasTable($t) && ($n = DB::table($t)->where('id', $id)->value($c))) return $n;
            }
            return null;
        };

        $add($inv->created_at ?? $inv->transaction_date, 'created', 'Invoice created',
            trim(($inv->document_no ? "Doc # {$inv->document_no} · " : '') . 'Total ' . number_format((float) $inv->grand_total, 2)), null, 'blue');
        if (!empty($inv->updated_at) && $inv->updated_at != ($inv->created_at ?? null)) {
            $add($inv->updated_at, 'updated', 'Last changed', null, null, 'gray');
        }

        // scanned receipt
        if (Schema::hasTable('t_mobile_transaction')) {
            $scan = DB::table('t_mobile_transaction')->where('reference_no', $inv->invoice_no)->first();
            if ($scan) $add($scan->created_at ?? null, 'scan', 'Receipt scanned', $scan->filename ?? null, null, 'blue');
        }

        // tickets
        if (Schema::hasTable('t_request_ticket')) {
            foreach (DB::table('t_request_ticket')->where('reference_no', $inv->invoice_no)->orderBy('id')->get() as $t) {
                $action = strtoupper((string) ($t->action ?? 'EDIT'));
                $label = $action === 'UNDECLARED' ? 'Declared month changed (supervisor)' : "{$action} request";
                $state = match ((int) ($t->is_status ?? 0)) { 0 => 'pending', 1 => 'approved', 2 => 'rejected', default => 'closed' };
                $add($t->created_at ?? null, 'ticket', "{$label} · {$t->ticket_no}", $t->remarks ?? null, $userName($t->request_by ?? null),
                    $state === 'pending' ? 'orange' : ($state === 'rejected' ? 'red' : 'green'));
                if ($state !== 'pending' && $action !== 'UNDECLARED' && !empty($t->updated_at) && $t->updated_at != $t->created_at) {
                    $add($t->updated_at, 'ticket', "{$action} {$state} · {$t->ticket_no}", null, $userName($t->approved_by ?? null), $state === 'rejected' ? 'red' : 'green');
                }
            }
        }

        // audit history + comments (old tbl2_audit_history UNION tbl2_transaction_comment)
        $employee = "TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name))";
        $hasHistory = false;
        if (Schema::hasTable('t_audit_history')) {
            $rows = DB::table('t_audit_history as A')
                ->leftJoin('m_employee as E', 'E.id', '=', 'A.auditor_id')
                ->leftJoin('m_audit_status as S', 'S.id', '=', 'A.audit_status_id')
                ->where('A.reference_no', $inv->invoice_no)
                ->get(['A.created_at', 'A.remarks', DB::raw("{$employee} AS employee"), DB::raw("COALESCE(S.name, '') AS status")]);
            foreach ($rows as $r) {
                $hasHistory = true;
                $add($r->created_at, 'audit', 'Audit: ' . ($r->status ?: '—'), $r->remarks ?: 'No comment', $r->employee ?: null,
                    preg_match('/reject|return/i', $r->status) ? 'red' : (preg_match('/pending/i', $r->status) ? 'orange' : 'green'));
            }
        }
        if (Schema::hasTable('t_transaction_comment')) {
            // works with your existing table: finds its reference / employee / comment / date columns
            $cols = Schema::getColumnListing('t_transaction_comment');
            $pick = function (array $names) use ($cols) {
                foreach ($names as $n) foreach ($cols as $c) if (strcasecmp($c, $n) === 0) return $c;
                return null;
            };
            $refCol = $pick(['reference_no', 'reference', 'invoice_no', 'ref_no']);
            $empCol = $pick(['employee_id', 'emp_id', 'empid', 'user_id', 'added_by']);
            $txtCol = $pick(['comments', 'comment', 'remarks', 'message', 'notes']);
            $atCol  = $pick(['created_at', 'date_added', 'dateadded', 'updated_at']);

            if ($refCol && $txtCol) {
                $q = DB::table('t_transaction_comment as C')->where("C.{$refCol}", $inv->invoice_no);
                $select = [DB::raw("C.`{$txtCol}` AS comments"), DB::raw($atCol ? "C.`{$atCol}` AS at" : 'NULL AS at')];
                if ($empCol) {
                    $q->leftJoin('m_employee as E', 'E.id', '=', "C.{$empCol}");
                    $select[] = DB::raw("{$employee} AS employee");
                }
                foreach ($q->get($select) as $r) {
                    $add($r->at ?? $inv->updated_at ?? null, 'comment', 'Random', $r->comments ?: 'No comment', ($r->employee ?? null) ?: null, 'blue');
                }
            }
        }

        // no history saved yet → show the current audit status
        if (!$hasHistory && !empty($inv->audit_status_id)) {
            $audit = DB::table('m_audit_status')->where('id', $inv->audit_status_id)->value('name');
            if ($audit && !preg_match('/pending/i', $audit)) {
                $add($inv->updated_at ?? null, 'audit', "Audit: {$audit}", $inv->audit_remarks ?? null, null, preg_match('/reject|return/i', $audit) ? 'red' : 'green');
            }
        }

        // money in / out
        if ($type === 'sales' && Schema::hasTable('t_rvoucher_detail')) {
            $rows = DB::table('t_rvoucher_detail as D')->join('t_rvoucher_master as M', 'M.rvoucher_no', '=', 'D.rvoucher_no')
                ->where('D.invoice_no', $inv->invoice_no)->orderBy('M.id')
                ->get(['M.rvoucher_no', 'M.rv_date', 'M.created_at', 'M.document_no', 'D.sub_total']);
            foreach ($rows as $r) {
                $add($r->created_at ?? $r->rv_date, 'money', "Money received · {$r->rvoucher_no}",
                    number_format((float) $r->sub_total, 2) . ' on ' . substr((string) $r->rv_date, 0, 10) . ($r->document_no ? " · OR {$r->document_no}" : ''), null, 'green');
            }
        }
        if ($type !== 'sales' && Schema::hasTable('t_pvoucher_detail')) {
            $rows = DB::table('t_pvoucher_detail as D')->join('t_pvoucher_master as M', 'M.pvoucher_no', '=', 'D.pvoucher_no')
                ->where('D.ap_invoice_no', $inv->invoice_no)->orderBy('M.id')
                ->get(['M.pvoucher_no', 'M.pv_date', 'M.created_at', 'M.payment_type', 'D.amount']);
            foreach ($rows as $r) {
                $add($r->created_at ?? $r->pv_date, 'money', "Bill paid · {$r->pvoucher_no}",
                    number_format((float) $r->amount, 2) . ' on ' . substr((string) $r->pv_date, 0, 10) . ($r->payment_type ? " · {$r->payment_type}" : ''), null, 'green');
            }
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));
        $status = DB::table('m_invoice_status')->where('id', $inv->invoice_status_id)->value('name');

        return response()->json([
            'invoice_no' => $inv->invoice_no,
            'document_no' => $inv->document_no,
            'status' => $status,
            'events' => $events,
        ]);
    }
}