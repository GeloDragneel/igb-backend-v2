<?php

namespace App\Http\Controllers\Api;

use App\Models\DeclaredMonthSettings;
use App\Support\PaymentAccounts as PA;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases / Expenses → PAY BILL (t_pvoucher_master / t_pvoucher_detail)
 *   master.total_amount = amount paid, detail.amount = amount applied to the invoice (ap_invoice_no)
 *   Invoice becomes PARTIAL or PAID; a fully paid For Payment invoice moves back to posted.
 */
class PayBillController extends SalesController
{
    const IM = 't_purchase_expense_master';
    const ID = 't_purchase_expense_detail';
    const PM = 't_pvoucher_master';
    const PD = 't_pvoucher_detail';
    const FOLDER = 'Pay_Bill';

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
    private function invoice(int $by, string $type, string $no): ?object
    {
        return DB::table(self::IM)->where('added_by', $by)->where('invoice_no', $no)
            ->whereRaw('LOWER(trans_type) = ?', [$type])->first();
    }

    private function vendorCol(): string
    {
        return Schema::hasColumn(self::IM, 'vendor_id') ? 'vendor_id' : 'customer_id';
    }

    private function paid(string $invoiceNo): float
    {
        return round((float) DB::table(self::PD)->where('ap_invoice_no', $invoiceNo)->sum('amount'), 2);
    }

    private function isLocked(?int $caid, array $months): bool
    {
        $months = array_values(array_filter($months));
        return $caid && $months && DeclaredMonthSettings::where('ca_id', $caid)->whereIn('declared_month', $months)->exists();
    }

    private function settle(object $inv): array
    {
        $paid = $this->paid($inv->invoice_no);
        $status = PA::statusFor((float) $inv->grand_total, $paid);
        $upd = ['invoice_status_id' => $status, 'updated_at' => now()];
        if ($status === PA::statusId('PAID') && strtolower((string) ($inv->record_status ?? '')) === 'billing') $upd['record_status'] = 'posted';
        DB::table(self::IM)->where('id', $inv->id)->update(PA::only(self::IM, $upd));
        DB::table(self::ID)->where('invoice_no', $inv->invoice_no)->update(PA::only(self::ID, ['invoice_status_id' => $status, 'updated_at' => now()]));
        return [$paid, $status];
    }

    /* GET /api/{purchases|expenses}/invoice/{invoiceNo}/payments */
    public function pbShow(Request $request, string $type, string $invoiceNo)
    {
        $by = $this->addedByFor($request);
        $inv = $this->invoice($by, $type, $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);

        $paid = $this->paid($inv->invoice_no);
        $rows = DB::table(self::PD . ' as D')->join(self::PM . ' as M', 'M.pvoucher_no', '=', 'D.pvoucher_no')
            ->where('D.ap_invoice_no', $inv->invoice_no)->orderByDesc('M.pv_date')->orderByDesc('M.id')
            ->get(array_filter(['M.id', 'M.pvoucher_no', 'M.pv_date', 'M.document_no', 'M.bank', 'M.payment_type', 'M.ref_data', 'D.amount',
                Schema::hasColumn(self::PM, 'attachment') ? 'M.attachment' : null]));

        return response()->json([
            'invoice_no' => $inv->invoice_no,
            'document_no' => $inv->document_no,
            'grand_total' => round((float) $inv->grand_total, 2),
            'paid' => $paid,
            'balance' => round((float) $inv->grand_total - $paid, 2),
            'account_code' => $inv->account_code,
            'is_payable' => PA::isPayable($by, $inv->account_code),
            'payments' => $rows->map(fn ($r) => [
                'id' => $r->id, 'pvoucher_no' => $r->pvoucher_no, 'date' => substr((string) $r->pv_date, 0, 10),
                'document_no' => $r->document_no, 'bank' => $r->bank, 'payment_type' => $r->payment_type,
                'ref_data' => $r->ref_data, 'amount' => (float) $r->amount, 'attachment' => $r->attachment ?? null,
            ]),
        ]);
    }

    /* POST /api/{purchases|expenses}/invoice/{invoiceNo}/payments */
    public function pbStore(Request $request, string $type, string $invoiceNo)
    {
        $d = $request->validate([
            'pv_date'      => ['required', 'date'],
            'bank'         => ['required', 'string', 'max:20'],
            'amount'       => ['required', 'numeric', 'gt:0'],
            'payment_type' => ['nullable', 'string', 'max:50'],
            'ref_data'     => ['nullable', 'string', 'max:255'],
            'document_no'  => ['nullable', 'string', 'max:100'],
            'particulars'  => ['nullable', 'string', 'max:1000'],
            'attachment'   => self::FILE_RULE,
        ], ['bank.required' => 'Pick the cash / bank account the money comes from', 'amount.gt' => 'Enter the amount paid', 'attachment.max' => 'Attachment is too big (10 MB max).']);

        $by = $this->addedByFor($request);
        $inv = $this->invoice($by, $type, $invoiceNo);
        if (!$inv) return response()->json(['message' => 'Invoice not found.'], 404);
        if ((int) $inv->invoice_status_id === 5 || preg_match('/void|cancel/i', (string) DB::table('m_invoice_status')->where('id', $inv->invoice_status_id)->value('name'))) {
            return response()->json(['message' => 'This invoice is void / cancelled.'], 422);
        }

        $amount = round((float) $d['amount'], 2);
        $balance = round((float) $inv->grand_total - $this->paid($inv->invoice_no), 2);
        if ($amount > $balance + 0.01) return response()->json(['message' => 'More than the balance (' . number_format($balance, 2) . ').'], 422);
        if ($this->isLocked($this->caidFor($request), [substr($d['pv_date'], 0, 7)])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        do { $no = 'PV-' . date('ymd-his') . random_int(10000, 99999); } while (DB::table(self::PM)->where('pvoucher_no', $no)->exists());
        try {
            $file = $this->upload($request, self::FOLDER, $no);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Attachment upload failed. Please try again.'], 422);
        }

        return DB::transaction(function () use ($by, $d, $inv, $amount, $no, $file) {
            $vendorId = (int) ($inv->{$this->vendorCol()} ?? 0);
            $vendor = $vendorId ? DB::table('m_vendor_customer')->where('id', $vendorId)->value('name') : null;
            $ex = (float) ($inv->ex_rate ?? 1) ?: 1;

            DB::table(self::PM)->insert(PA::only(self::PM, [
                'pvoucher_no' => $no, 'pv_date' => $d['pv_date'], 'vendor_id' => $vendorId, 'pay_to' => $vendor,
                'particulars' => $d['particulars'] ?? null, 'total_amount' => $amount, 'total_f_amount' => round($amount / $ex, 4),
                'sub_total' => $amount, 'base_sub_total' => $amount, 'bank' => $d['bank'], 'pv_status' => 1,
                'payment_type' => $d['payment_type'] ?? null, 'ref_data' => $d['ref_data'] ?? null,
                'ap_invoice_no' => $inv->invoice_no, 'document_no' => $d['document_no'] ?? $inv->document_no, 'attachment' => $file,
                'last_update' => now(), 'added_by' => $by, 'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table(self::PD)->insert(PA::only(self::PD, [
                'pvoucher_no' => $no, 'voucher_date' => $d['pv_date'], 'acc_code' => $inv->account_code, 'account_no' => $d['bank'],
                'item_id' => 0, 'ap_invoice_no' => $inv->invoice_no, 'ap_id' => $inv->id, 'po_id' => 0, 'qty' => 0,
                'f_amount' => round($amount / $ex, 4), 'amount' => $amount, 'ref_data' => $d['ref_data'] ?? null,
                'payment_type' => $d['payment_type'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]));

            [$paid] = $this->settle($inv);
            $left = round((float) $inv->grand_total - $paid, 2);
            return response()->json(['pvoucher_no' => $no, 'balance' => $left,
                'message' => $left <= 0.009 ? "{$no} saved – bill fully paid" : "{$no} saved – balance " . number_format($left, 2)]);
        });
    }

    /* GET /api/{purchases|expenses}/payments/{pvoucherNo}/attachment */
    public function pbAttachment(Request $request, string $type, string $pvoucherNo)
    {
        $m = DB::table(self::PM)->where('added_by', $this->addedByFor($request))->where('pvoucher_no', $pvoucherNo)->first();
        if (!$m) return response()->json(['message' => 'Payment not found.'], 404);
        return $this->streamFile(self::FOLDER, $m->pvoucher_no, $m->attachment ?? null);
    }

    /* DELETE /api/{purchases|expenses}/payments/{pvoucherNo} */
    public function pbVoid(Request $request, string $type, string $pvoucherNo)
    {
        $by = $this->addedByFor($request);
        $m = DB::table(self::PM)->where('added_by', $by)->where('pvoucher_no', $pvoucherNo)->first();
        if (!$m) return response()->json(['message' => 'Payment not found.'], 404);
        if ($this->isLocked($this->caidFor($request), [substr((string) $m->pv_date, 0, 7)])) return response()->json(['message' => 'Declared Month is disabled'], 422);

        DB::transaction(function () use ($by, $type, $m) {
            $nos = DB::table(self::PD)->where('pvoucher_no', $m->pvoucher_no)->pluck('ap_invoice_no')->filter()->unique();
            DB::table(self::PD)->where('pvoucher_no', $m->pvoucher_no)->delete();
            DB::table(self::PM)->where('id', $m->id)->delete();
            foreach ($nos as $no) if ($inv = $this->invoice($by, $type, $no)) $this->settle($inv);
        });
        return response()->json(['message' => "{$pvoucherNo} removed"]);
    }
}