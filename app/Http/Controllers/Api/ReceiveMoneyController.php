<?php

namespace App\Http\Controllers\Api;

use App\Models\DeclaredMonthSettings;
use App\Support\PaymentAccounts as PA;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales → RECEIVE MONEY (t_rvoucher_master / t_rvoucher_detail)
 *   master.sub_total = cash received, master.total = cash + withholding (amount applied to the invoice)
 *   detail.sub_total = amount applied to the invoice (what Receivables / Banking read)
 *   Invoice becomes PARTIAL or PAID; a fully paid For Collection invoice moves back to posted.
 */
class ReceiveMoneyController extends SalesController
{
    const IM = 't_sales_invoice_master';
    const ID = 't_sales_invoice_detail';
    const RM = 't_rvoucher_master';
    const RD = 't_rvoucher_detail';
    const FOLDER = 'Received_Money';

    private const FILE_RULE = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx'];

    /** upload to S3 "{folder}/{voucherNo}-{name}", returns the stored name */
    private function upload(Request $request, string $folder, string $voucherNo): ?string
    {
        if (!$request->hasFile('attachment')) return null;
        $file = $request->file('attachment');
        $clean = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $file->getClientOriginalName());
        \Illuminate\Support\Facades\Storage::disk('s3')->putFileAs($folder, $file, "{$voucherNo}-{$clean}");
        return $clean;
    }

    /** stream "{folder}/{voucherNo}-{name}" back to the browser */
    private function streamFile(string $folder, string $voucherNo, ?string $name)
    {
        $name = trim((string) $name);
        if ($name === '') return response()->json(['message' => 'No attachment.'], 404);
        if (preg_match('/^https?:\/\//i', $name)) return response()->json(['url' => $name]);

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = \Illuminate\Support\Facades\Storage::disk('s3');

        foreach (["{$folder}/{$voucherNo}-{$name}", "{$folder}/{$name}"] as $key) {
            if ($disk->exists($key)) return $disk->response($key, $name);
        }
        return response()->json(['message' => 'File not found on storage.'], 404);
    }

    private function invoice(int $by, string $no): ?object
    {
        return DB::table(self::IM)->where('added_by', $by)->where('invoice_no', $no)->first();
    }

    private function collected(string $invoiceNo): float
    {
        return round((float) DB::table(self::RD)->where('invoice_no', $invoiceNo)->sum('sub_total'), 2);
    }

    private function isLocked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    /** set PAID / PARTIAL / UNPAID on the invoice (and its lines) */
    private function settle(object $inv): array
    {
        $collected = $this->collected($inv->invoice_no);
        $status = PA::statusFor((float) $inv->grand_total, $collected);
        $upd = ['invoice_status_id' => $status, 'updated_at' => now()];
        // fully paid "For Collection" invoice goes back to the main list
        if ($status === PA::statusId('PAID') && strtolower((string) ($inv->trans_type ?? '')) === 'billing') $upd['trans_type'] = 'posted';
        DB::table(self::IM)->where('id', $inv->id)->update(PA::only(self::IM, $upd));
        DB::table(self::ID)->where('invoice_no', $inv->invoice_no)->update(PA::only(self::ID, ['invoice_status_id' => $status, 'updated_at' => now()]));
        return [$collected, $status];
    }

    /* GET /api/sales/invoice/{invoiceNo}/collections */
    public function rmShow(Request $request, string $invoiceNo)
    {
        $by = $this->addedByFor($request);
        $inv = $this->invoice($by, $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $collected = $this->collected($inv->invoice_no);
        $rows = DB::table(self::RD . ' as D')->join(self::RM . ' as M', 'M.rvoucher_no', '=', 'D.rvoucher_no')
            ->where('D.invoice_no', $inv->invoice_no)->orderByDesc('M.rv_date')->orderByDesc('M.id')
            ->get(['M.id', 'M.rvoucher_no', 'M.rv_date', 'M.document_no', 'M.bank', 'M.sub_total as cash', 'M.2307_vt', 'M.2307_it', 'D.sub_total as applied', 'M.declared_month', 'M.attachment']);

        return response()->json([
            'invoice_no' => $inv->invoice_no,
            'document_no' => $inv->document_no,
            'grand_total' => round((float) $inv->grand_total, 2),
            'collected' => $collected,
            'balance' => round((float) $inv->grand_total - $collected, 2),
            'account_code' => $inv->account_code,
            'is_receivable' => PA::isReceivable($by, $inv->account_code),
            'receipts' => $rows->map(fn ($r) => [
                'id' => $r->id, 'rvoucher_no' => $r->rvoucher_no, 'date' => substr((string) $r->rv_date, 0, 10),
                'document_no' => $r->document_no, 'bank' => $r->bank, 'cash' => (float) $r->cash,
                'withholding' => round((float) $r->{'2307_vt'} + (float) $r->{'2307_it'}, 2), 'applied' => (float) $r->applied,
                'declared_month' => $r->declared_month, 'attachment' => $r->attachment,
            ]),
        ]);
    }

    /* POST /api/sales/invoice/{invoiceNo}/collections */
    public function rmStore(Request $request, string $invoiceNo)
    {
        $d = $request->validate([
            'rv_date'        => ['required', 'date'],
            'declared_month' => ['required', 'date_format:Y-m'],
            'bank'           => ['required', 'string', 'max:20'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'document_no'    => ['nullable', 'string', 'max:100'],
            'atc_vt_id'      => ['nullable', 'integer'],
            '2307_vt'        => ['nullable', 'numeric', 'min:0'],
            'vt_month'       => ['nullable', 'date_format:Y-m'],
            'atc_it_id'      => ['nullable', 'integer'],
            '2307_it'        => ['nullable', 'numeric', 'min:0'],
            'it_month'       => ['nullable', 'date_format:Y-m'],
            'attachment'     => self::FILE_RULE,
        ], ['bank.required' => 'Pick the cash / bank account the money goes to', 'attachment.max' => 'Attachment is too big (10 MB max).']);

        $by = $this->addedByFor($request);
        $inv = $this->invoice($by, $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);
        if ((int) $inv->invoice_status_id === 5 || preg_match('/void|cancel/i', (string) DB::table('m_invoice_status')->where('id', $inv->invoice_status_id)->value('name'))) {
            return response()->json(['message' => 'This invoice is void / cancelled.'], 422);
        }

        $vt = round((float) ($d['2307_vt'] ?? 0), 2);
        $it = round((float) ($d['2307_it'] ?? 0), 2);
        $cash = round((float) $d['amount'], 2);
        $applied = round($cash + $vt + $it, 2);
        $balance = round((float) $inv->grand_total - $this->collected($inv->invoice_no), 2);
        if ($applied <= 0) return response()->json(['message' => 'Enter the amount received.'], 422);
        if ($applied > $balance + 0.01) return response()->json(['message' => 'More than the balance (' . number_format($balance, 2) . ').'], 422);
        if ($vt > 0 && empty($d['atc_vt_id'])) return response()->json(['message' => 'Pick the ATC for 2307 (VT/PT).'], 422);
        if ($it > 0 && empty($d['atc_it_id'])) return response()->json(['message' => 'Pick the ATC for 2307 (IT).'], 422);
        $vtMonth = $vt > 0 ? ($d['vt_month'] ?? $d['declared_month']) : null;
        $itMonth = $it > 0 ? ($d['it_month'] ?? $d['declared_month']) : null;
        if ($this->isLocked($this->caidFor($request), [$d['declared_month'], $vtMonth, $itMonth])) {
            return response()->json(['message' => 'Declared Month is disabled'], 422);
        }

        do { $no = 'RV-' . date('ymd-his') . random_int(10000, 99999); } while (DB::table(self::RM)->where('rvoucher_no', $no)->exists());
        try {
            $file = $this->upload($request, self::FOLDER, $no);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Attachment upload failed. Please try again.'], 422);
        }

        return DB::transaction(function () use ($by, $d, $inv, $cash, $applied, $vt, $it, $vtMonth, $itMonth, $no, $file) {

            $common = [
                'customer_id' => $inv->customer_id, 'rvoucher_no' => $no, 'invoice_no' => $inv->invoice_no, 'rv_date' => $d['rv_date'],
                'account_code' => $inv->account_code, 'declared_month' => $d['declared_month'], 'added_by' => $by,
                'atc_vt_id' => $vt > 0 ? $d['atc_vt_id'] : null, '2307_vt' => $vt, '2307_vt_declared_month' => $vtMonth,
                'atc_it_id' => $it > 0 ? $d['atc_it_id'] : null, '2307_it' => $it, '2307_it_declared_month' => $itMonth,
                'created_at' => now(), 'updated_at' => now(),
            ];
            $masterId = DB::table(self::RM)->insertGetId(PA::only(self::RM, $common + [
                'document_no' => $d['document_no'] ?? null, 'rv_status' => 1, 'sub_total' => $cash, 'total' => $applied, 'bank' => $d['bank'], 'attachment' => $file,
            ]));
            DB::table(self::RD)->insert(PA::only(self::RD, $common + [
                'sub_total' => $applied, 'inv_master_id' => $inv->id, 'rv_master_id' => $masterId,
            ]));

            [$collected] = $this->settle($inv);
            $left = round((float) $inv->grand_total - $collected, 2);
            return response()->json(['rvoucher_no' => $no, 'balance' => $left,
                'message' => $left <= 0.009 ? "{$no} saved – invoice fully paid" : "{$no} saved – balance " . number_format($left, 2)]);
        });
    }

    /* GET /api/sales/collections/{rvoucherNo}/attachment */
    public function rmAttachment(Request $request, string $rvoucherNo)
    {
        $m = DB::table(self::RM)->where('added_by', $this->addedByFor($request))->where('rvoucher_no', $rvoucherNo)->first(['rvoucher_no', 'attachment']);
        if (!$m) return response()->json(['message' => 'Receipt not found.'], 404);
        return $this->streamFile(self::FOLDER, $m->rvoucher_no, $m->attachment);
    }

    /* DELETE /api/sales/collections/{rvoucherNo}   (removes the receipt, invoice status goes back) */
    public function rmVoid(Request $request, string $rvoucherNo)
    {
        $by = $this->addedByFor($request);
        $m = DB::table(self::RM)->where('added_by', $by)->where('rvoucher_no', $rvoucherNo)->first();
        if (!$m) return response()->json(['message' => 'Receipt not found.'], 404);
        if ($this->isLocked($this->caidFor($request), [$m->declared_month])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        DB::transaction(function () use ($by, $m) {
            $invoiceNos = DB::table(self::RD)->where('rvoucher_no', $m->rvoucher_no)->pluck('invoice_no')->filter()->unique();
            DB::table(self::RD)->where('rvoucher_no', $m->rvoucher_no)->delete();
            DB::table(self::RM)->where('id', $m->id)->delete();
            foreach ($invoiceNos as $no) if ($inv = $this->invoice($by, $no)) $this->settle($inv);
        });
        return response()->json(['message' => "{$rvoucherNo} removed"]);
    }
}