<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GRNMaster;
use App\Models\PurchaseExpenseDetail;
use App\Models\PurchaseExpenseMaster;
use App\Models\SalesInvoiceMaster;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mobile (Flutter) API – the old /mobile/*.php scripts on the new tables.
 * The JSON keys sent back to the app stay the same as the old scripts (CAID, AccountNumber, …),
 * so the app only needs the new base URL.
 *
 *   old table                         → new table
 *   tbl_accounting_bank_name/_account → m_accounting_bank_name / m_accounting_bank_account
 *   tbl_payment                       → t_payment
 *   tbl2_collection_deposit_file      → t_collection_deposit_file   (new migration)
 *   tbl2_biometric_register           → m_biometric_register        (new migration)
 *   tbl2_user / tbl_user / tbl2_user_igb → m_user_client / m_user_staff / m_user_client_staff
 *   tbl_client_account / tbl_employee → m_client_account / m_employee
 *   tbl2_charts_account               → m_charts_of_account
 *   tbl2_apinvoice_master/_detail     → t_purchase_expense_master / _detail
 *   tbl2_invoice_master / tbl2_grn_master → t_sales_invoice_master / t_grn_master
 *   lib_atc / lib_vendor_customer     → m_atc / m_vendor_customer
 *   tbl2_chat_*                       → t_chat_*                    (new migration)
 *   tbl2_mobile_transaction           → t_mobile_transaction
 *   tbl2_documents                    → m_documents
 *   tbl_payment_cheque_type / tbl_payment_col_type → m_payment_cheque_type / m_payment_col_type
 *   qry_client / qry_client_account   → m_client / m_client_account
 *   qry_user (staff)                  → m_user_staff + m_employee
 *   tbl2_holidays / tbl2_leave_registration / tbl2_leave_type → m_holiday / t_leave_request / m_leave_type
 *   tbl2_overtime_registration / tbl2_biometic_logs / tbl2_cofe → t_overtime / t_attendance
 *   general_ledger / e_bir            → ReportController::generalLedger / vatSummary (same numbers as the web)
 *
 * S3 credentials come from .env (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION, AWS_BUCKET).
 */
class MobileController extends Controller
{
    const PAY_SUBMITTED = 2, PAY_DEPOSITED = 3;
    const CHAT_TYPES = ['User1', 'User2', 'User3'];   // client, staff, client staff
    const LEGACY_KEYS = [
        'id' => 'ID', 'created_at' => 'DateAdded', 'updated_at' => 'DateUpdated',
        'net_of_vat' => 'NetOfVAT', 'input_vat' => 'inputVAT', 'vat_exempt' => 'VATExempt', 'zero_rated' => 'ZeroRated',
        'non_vat' => 'NonVAT', 'ex_rate' => 'ExRate', 'ca_id' => 'CAID', 'caid' => 'CAID', 'tin' => 'TIN',
    ];

    /* =========================================================
     * Helpers
     * ========================================================= */

    private array $colCache = [];

    private function cols(string $table): array
    {
        return $this->colCache[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }

    /** first of $names that exists in $table */
    private function pick(string $table, array $names): ?string
    {
        foreach ($names as $n) foreach ($this->cols($table) as $c) if (strcasecmp($c, $n) === 0) return $c;
        return null;
    }

    /** keep only the keys that are real columns of $table */
    private function only(string $table, array $row): array
    {
        return array_intersect_key($row, array_flip($this->cols($table)));
    }

    private function err(string $message, array $extra = []): JsonResponse
    {
        return response()->json(['result' => 'Error', 'message' => $message] + $extra);
    }

    private function missing(Request $r, array $fields): ?JsonResponse
    {
        foreach ($fields as $f) {
            $v = $r->input($f);
            if ($v === null || (is_string($v) && trim($v) === '') || (is_array($v) && !$v)) {
                return $this->err("Missing required field: $f");
            }
        }
        return null;
    }

    /** every scalar as a string (the old scripts returned strings) */
    private function str($v)
    {
        return ($v === null || is_string($v)) ? $v : (string) $v;
    }

    private function details(Request $r): ?array
    {
        $d = $r->input('Details');
        if (is_string($d)) $d = json_decode($d, true);
        return (is_array($d) && count($d) > 0) ? $d : null;
    }

    private function uploadToS3(UploadedFile $file, string $folder, string $prefix): ?string
    {
        $ext = $file->getClientOriginalExtension();
        if ($ext === '') {
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$file->getMimeType()] ?? 'jpg';
        }
        $name = $prefix . date('Ymdhis') . '.' . $ext;

        $s3 = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region') ?: 'ap-southeast-1',
            'credentials' => [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'suppress_php_deprecation_warning' => true,
        ]);
        $res = $s3->putObject([
            'Bucket' => config('filesystems.disks.s3.bucket') ?: 'igb-fems-files-singapore',
            'Key' => "$folder/$name",
            'Body' => $this->fileBody($file),
            'ContentType' => $file->getMimeType(),
        ]);
        return !empty($res['ObjectURL']) ? $name : null;
    }

    private function saveFileLink(string $billType, int $paymentId, string $filename): void
    {
        DB::table('t_collection_deposit_file')->insert($this->only('t_collection_deposit_file', [
            'bill_type' => $billType, 'table_name' => 't_payment', 'table_id' => $paymentId,
            'filename' => $filename, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** client name of a m_client_account id (m_client registered name, else the person's name, else trade name) */
    private function clientNameOf($caid): ?string
    {
        if (!$caid) return null;
        $acc = DB::table('m_client_account')->where('id', $caid)->first();
        if (!$acc) return null;
        $c = DB::table('m_client')->where('client_num', $acc->client_num)->first();
        if ($c) {
            foreach (['registered_name', 'client_name', 'business_name', 'company_name', 'name'] as $k) {
                if (!empty($c->$k) && trim($c->$k) !== '') return trim($c->$k);
            }
            $person = trim(implode(' ', array_filter([$c->last_name ?? '', $c->first_name ?? '', $c->middle_name ?? ''])));
            if ($person !== '') return $person;
        }
        return $acc->trade_name ?? null;
    }

    private function employeeNameOf($employeeId): ?string
    {
        $e = $employeeId ? DB::table('m_employee')->where('id', $employeeId)->first() : null;
        return $e ? trim(implode(' ', array_filter([$e->last_name, $e->first_name, $e->middle_name ?? '']))) : null;
    }

    /* =========================================================
     * Utility
     * ========================================================= */

    /** GET check_table_structure */
    public function checkTableStructure()
    {
        try {
            if (!Schema::hasTable('t_chat_message')) return $this->err('Table t_chat_message does not exist');
            $cols = array_map(fn ($c) => array_map([$this, 'str'], (array) $c), DB::select('DESCRIBE t_chat_message'));
            return response()->json(['result' => 'Success', 'table' => 't_chat_message', 'columns' => $cols]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Bank
     * ========================================================= */

    /** GET bank_account_list?ABAID= */
    public function bankAccountList(Request $r)
    {
        $t = 'm_accounting_bank_account';
        $bank = $this->pick($t, ['abaid', 'bank_name_id', 'accounting_bank_name_id', 'bank_id']) ?? 'abaid';
        $num = $this->pick($t, ['account_number', 'account_no', 'number']);
        $name = $this->pick($t, ['account_name', 'name', 'description']);
        $active = $this->pick($t, ['is_active', 'active']);

        $list = DB::table($t)->where($bank, (int) $r->query('ABAID', 0))
            ->when($active, fn ($q) => $q->where($active, 1))
            ->orderBy('id')->get()
            ->map(fn ($a) => [
                'BAID' => $this->str($a->id),
                'ABAID' => $this->str($a->$bank),
                'AccountNumber' => $num ? $this->str($a->$num) : null,
                'AccountName' => $name ? $this->str($a->$name) : null,
            ]);

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET bank_name_list */
    public function bankNameList()
    {
        $t = 'm_accounting_bank_name';
        $abb = $this->pick($t, ['bank_name_abb', 'abbreviation', 'abb', 'code']);
        $name = $this->pick($t, ['bank_name_des', 'bank_name', 'description', 'name']);
        $active = $this->pick($t, ['is_active', 'active']);

        $list = DB::table($t)->when($active, fn ($q) => $q->where($active, 1))->orderBy('id')->get()
            ->map(fn ($b) => [
                'ABAID' => $this->str($b->id),
                'BankNameAbb' => $abb ? $this->str($b->$abb) : null,
                'BankNameDes' => $name ? $this->str($b->$name) : null,
                'Active' => $active ? $this->str($b->$active) : '1',
            ]);

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /* =========================================================
     * Billing (t_payment)
     * ========================================================= */

    /** POST bill_payment */
    public function billPayment(Request $r)
    {
        if ($e = $this->missing($r, ['PaymentDate', 'PaymentTypeID', 'CAID', 'Amount', 'TransNumber', 'ServicePeriod'])) return $e;

        $amount = round((float) $r->input('Amount', 0), 2);
        $caid = (int) $r->input('CAID');
        $employee = (int) $r->input('EmployeeID', 0) ?: null;
        $receipt = (int) $r->input('ReceiptTypeID', 0);
        $nz = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);

        try {
            $id = DB::table('t_payment')->insertGetId($this->only('t_payment', [
                'trans_number' => trim($r->input('TransNumber')),
                'service_period' => trim($r->input('ServicePeriod')),
                'service_id' => (int) $r->input('ServiceID', 0) ?: null,
                'ca_id' => $caid,
                'client_num' => DB::table('m_client_account')->where('id', $caid)->value('client_num'),
                'payment_date' => trim($r->input('PaymentDate')),
                'payment_type_id' => (int) $r->input('PaymentTypeID'),
                'cheque_type_id' => (int) $r->input('ChequeTypeID', 0) ?: null,
                'cheque_type_date' => $nz($r->input('ChequeTypeDate')),
                'details1' => $nz($r->input('BankDigitalWallet')),      // bank / wallet
                'details2' => $nz($r->input('ReferenceChequeNo')),      // reference / cheque #
                'payment_col_type_id' => (int) $r->input('PaymentColType', 0) ?: null,
                'receipt_type_id' => $receipt ?: null,
                'ar_number' => $nz($r->input('ARNumber')),
                'ap_number' => $nz($r->input('APNumber')),
                'or_number' => $nz($r->input('ORNumber')),
                'amount' => $amount,
                'co_amount' => 0,
                'total_gross_amount_cal' => $amount,
                'total_amount' => $amount,
                'qty' => 1,
                'company_id' => 1,
                'user_id' => $employee,
                'deposited_by_id' => $employee,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            return response()->json(['result' => 'Success', 'message' => 'Payment added successfully', 'id' => $id]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST bill_collection (multipart, optional file) – payments assigned to a bank account */
    public function billCollection(Request $r)
    {
        if ($e = $this->missing($r, ['PaymentType', 'BankNameID', 'AssignedBank', 'AssignedBankDate', 'AssignedBankBy', 'TotalAmount', 'TotalCO', 'TotalNetAmount', 'Details'])) return $e;
        $details = $this->details($r);
        if (!$details) return $this->err('Details must be a non-empty JSON array of PaymentIDs');

        $filename = null;
        $file = $r->file('file');
        if ($file && $file->isValid() && $file->getSize() > 0) {
            try {
                $filename = $this->uploadToS3($file, 'Billing_Collection', 'BC');
            } catch (Throwable $e) {
                return $this->err('Upload failed: ' . $e->getMessage());
            }
        }

        $by = (int) $r->input('AssignedBankBy');
        $set = $this->only('t_payment', [
            'aba_id' => (int) $r->input('BankNameID'),
            'assigned_bank' => (int) $r->input('AssignedBank'),
            'assigned_by' => $by,
            'assigned_bank_by' => $by,
            'assigned_bank_date' => trim($r->input('AssignedBankDate')),
            'payment_status' => self::PAY_SUBMITTED,
            'payment_status_id' => self::PAY_SUBMITTED,
            'updated_at' => now(),
        ]);

        $ok = 0;
        $errors = [];
        foreach ($details as $item) {
            $paymentId = (int) ($item['PaymentID'] ?? 0);
            if ($paymentId <= 0) {
                $errors[] = 'Invalid PaymentID: ' . json_encode($item);
                continue;
            }
            try {
                if (DB::table('t_payment')->where('id', $paymentId)->update($set) > 0) {
                    $ok++;
                    if ($filename) $this->saveFileLink('Collection', $paymentId, $filename);
                } else {
                    $errors[] = "PaymentID $paymentId not found or already updated";
                }
            } catch (Throwable $e) {
                $errors[] = "PaymentID $paymentId failed: " . $e->getMessage();
            }
        }

        if ($ok > 0 && !$errors) {
            return response()->json(['result' => 'Success', 'message' => "$ok record(s) submitted successfully", 'successCount' => $ok, 'filename' => $filename]);
        }
        if ($ok > 0) {
            return response()->json(['result' => 'Partial', 'message' => "$ok record(s) updated, " . count($errors) . ' failed', 'successCount' => $ok, 'errors' => $errors, 'filename' => $filename]);
        }
        return $this->err('No records were updated', ['errors' => $errors]);
    }

    /** POST bill_deposit (multipart, optional file) */
    public function billDeposit(Request $r)
    {
        if ($e = $this->missing($r, ['EmployeeID', 'Details'])) return $e;
        $details = $this->details($r);
        if (!$details) return $this->err('Details must be a non-empty JSON array of PaymentIDs');

        $employee = (int) $r->input('EmployeeID');

        $filename = null;
        $file = $r->file('file');
        if ($file && $file->isValid() && $file->getSize() > 0) {
            try {
                $filename = $this->uploadToS3($file, 'Billing_Deposit', 'BD');
            } catch (Throwable $e) {
                return $this->err('Upload failed: ' . $e->getMessage());
            }
        }

        $slipCol = $this->pick('t_payment', ['dep_slip_number']);

        try {
            [$ok, $slip] = DB::transaction(function () use ($details, $employee, $filename, $slipCol) {
                $slip = $slipCol
                    ? (string) ((int) DB::table('t_payment')->lockForUpdate()->max(DB::raw("CAST({$slipCol} AS UNSIGNED)")) + 1)
                    : null;
                $set = $this->only('t_payment', [
                    'dep_slip_number' => $slip,
                    'prepared_by_id' => $employee,
                    'prepared_date' => now(),
                    'is_prepared' => 1, 'prepared' => 1,
                    'is_deposited' => 1, 'deposited' => 1,
                    'payment_status' => self::PAY_DEPOSITED, 'payment_status_id' => self::PAY_DEPOSITED,
                    'deposited_by_id' => $employee,
                    'date_deposited' => now(),
                    'updated_at' => now(),
                ]);

                $ok = 0;
                foreach ($details as $d) {
                    $paymentId = (int) ($d['PaymentID'] ?? 0);
                    if ($paymentId <= 0) throw new \RuntimeException('Invalid PaymentID: ' . json_encode($d));
                    if (DB::table('t_payment')->where('id', $paymentId)->update($set) <= 0) {
                        throw new \RuntimeException("PaymentID $paymentId not found or already updated");
                    }
                    if ($filename) $this->saveFileLink('Deposit', $paymentId, $filename);
                    $ok++;
                }
                return [$ok, $slip];
            });

            return response()->json([
                'result' => 'Success', 'message' => "$ok record(s) submitted successfully",
                'successCount' => $ok, 'depSlipNumber' => $slip, 'filename' => $filename,
            ]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Biometric (m_biometric_register)
     * ========================================================= */

    /** client logins (m_user_client) with a fingerprint; $where adds the filter */
    private function biometricClients(callable $where)
    {
        $q = DB::table('m_biometric_register as B')
            ->join('m_user_client as U', 'U.id', '=', 'B.user_id')
            ->leftJoin('m_client_account as A', 'A.id', '=', 'B.ca_id')
            ->where('B.user_type', 'client')
            ->select(['B.id', 'B.ca_id', 'B.user_id', 'B.fingerprint_data', 'A.trade_name', 'U.username', 'U.password', 'B.created_at', 'B.updated_at']);
        $where($q);
        return $q->get();
    }

    /** staff logins (m_user_staff → m_employee) with a fingerprint */
    private function biometricStaff(callable $where)
    {
        $q = DB::table('m_biometric_register as B')
            ->join('m_user_staff as S', 'S.id', '=', 'B.user_id')
            ->leftJoin('m_employee as E', 'E.com_id', '=', 'S.comid')         // staff login → employee (same as AuthController)
            ->where('B.user_type', 'staff')
            ->select(['B.id', 'B.ca_id', 'B.user_id', 'B.fingerprint_data',
                DB::raw("TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name)) AS trade_name"),
                'S.username', 'S.password', 'B.created_at', 'B.updated_at']);
        $where($q);
        return $q->get();
    }

    private function biometricRow($b): array
    {
        return [
            'ID' => $b->id,
            'CAID' => $b->ca_id,
            'UserID' => $b->user_id,
            'FingerprintData' => $b->fingerprint_data,
            'TradeName' => $b->trade_name,
            'Username' => $b->username,
            'Password' => $b->password,
            'DateAdded' => $b->created_at,
            'DateUpdated' => $b->updated_at,
        ];
    }

    /** GET biometric_by_username?Username= */
    public function biometricByUsername(Request $r)
    {
        $username = trim((string) $r->query('Username', ''));
        if ($username === '') return $this->err('No username provided');

        $data = $this->biometricClients(fn ($q) => $q->where('U.username', $username))
            ->map(fn ($b) => $this->biometricRow($b))->values();

        return response()->json($data->isNotEmpty()
            ? ['result' => 'Success', 'data' => $data]
            : ['result' => 'Empty', 'message' => 'No biometric found']);
    }

    /** POST biometric_registration {CAID, UserID, FingerprintData, UserType = client|staff (default client)} */
    public function biometricRegistration(Request $r)
    {
        $caid = (int) $r->input('CAID', 0);
        $userId = (int) $r->input('UserID', 0);
        $finger = trim((string) $r->input('FingerprintData', ''));
        $type = strtolower((string) $r->input('UserType', 'client')) === 'staff' ? 'staff' : 'client';

        if ($caid === 0) return $this->err('Invalid CAID');
        if ($userId === 0) return $this->err('Invalid UserID');
        if ($finger === '') return $this->err('Invalid FingerprintData');

        try {
            $q = fn () => DB::table('m_biometric_register')->where('ca_id', $caid)->where('user_id', $userId)->where('user_type', $type);

            if ($q()->where('fingerprint_data', $finger)->exists()) return $this->err('Biometric already registered');

            if ($q()->exists()) {
                $q()->update(['fingerprint_data' => $finger, 'updated_at' => now()]);
                return response()->json(['result' => 'Success', 'message' => 'Biometric updated for new device']);
            }

            DB::table('m_biometric_register')->insert([
                'ca_id' => $caid, 'user_id' => $userId, 'user_type' => $type,
                'fingerprint_data' => $finger, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['result' => 'Success', 'message' => 'Biometric registered successfully']);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** GET biometric_validation?keys=CAID_UserID,CAID_UserID */
    public function biometricValidation(Request $r)
    {
        $raw = trim((string) $r->query('keys', ''));
        if ($raw === '') return $this->err('No keys provided');

        $pairs = [];
        foreach (explode(',', $raw) as $key) {
            $p = explode('_', trim($key));
            if (count($p) === 2 && (int) $p[0] > 0 && (int) $p[1] > 0) $pairs[] = [(int) $p[0], (int) $p[1]];
        }
        if (!$pairs) return $this->err('Invalid keys');

        $match = function ($q) use ($pairs) {
            $q->where(function ($w) use ($pairs) {
                foreach ($pairs as [$caid, $uid]) {
                    $w->orWhere(fn ($x) => $x->where('B.ca_id', $caid)->where('B.user_id', $uid));
                }
            });
        };

        try {
            $data = $this->biometricClients($match)->concat($this->biometricStaff($match))
                ->sortBy(fn ($b) => [(string) $b->trade_name, (string) $b->username])
                ->map(fn ($b) => $this->biometricRow($b))->values();
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }

        return response()->json($data->isNotEmpty()
            ? ['result' => 'Success', 'data' => $data]
            : ['result' => 'Empty', 'message' => 'No biometric found']);
    }

    /* =========================================================
     * Accounting
     * ========================================================= */

    /** GET charts_expenses?userId=   expense accounts (6xxxx, not a root) of the client user */
    public function chartsExpenses(Request $r)
    {
        try {
            $rows = DB::table('m_charts_of_account')
                ->where('added_by', (int) $r->query('userId', 0))
                ->where('account_code', 'like', '6%')
                ->where('root_name', '!=', '0')
                ->orderBy('account_name')
                ->get(['account_code', 'account_name'])
                ->map(fn ($a) => ['AccountCode' => $this->str($a->account_code), 'Account_Name' => $a->account_name]);

            return response()->json(['result' => 'Success', 'invoices' => $rows]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** GET cash_disbursement_journal?userId=&from=YYYY-MM&to=YYYY-MM   posted expenses by declared month */
    public function cashDisbursementJournal(Request $r)
    {
        $userId = (int) $r->query('userId', 0);
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;

        $masters = PurchaseExpenseMaster::where('added_by', $userId)
            ->active()->where('record_status', 'posted')->expenses()
            ->whereBetween('declared_month', [$from, $to])
            ->get(['id', 'invoice_no', 'document_no', 'vendor_id', 'account_code', 'declared_month', 'grand_total', '2307_it', '2307_vt']);

        $lines = PurchaseExpenseDetail::where('added_by', $userId)
            ->active()->where('record_status', 'posted')->expenses()
            ->whereBetween('declared_month', [$from, $to])
            ->get(['id', 'invoice_no', 'document_no', 'vendor_id', 'account_code', 'declared_month', 'input_vat', 'net_of_vat', 'vat_exempt', 'non_vat', 'zero_rated']);

        $vendors = DB::table('m_vendor_customer')
            ->whereIn('id', $masters->pluck('vendor_id')->merge($lines->pluck('vendor_id'))->filter()->unique())
            ->pluck('name', 'id');
        $accounts = DB::table('m_charts_of_account')->where('added_by', $userId)->pluck('account_name', 'account_code');

        $rows = [];
        $push = function ($src, int $seq, $code, string $desc, $dr, $cr) use (&$rows, $vendors) {
            $rows[] = [
                'seq' => (string) $src->invoice_no,
                'TransactionDate' => (string) $src->declared_month,
                'APInvoiceNo' => (string) $src->invoice_no,
                'AccountID' => $code,
                'DocumentNo' => (string) $src->document_no,
                'Name' => (string) ($vendors[$src->vendor_id] ?? ''),
                'LineDescription' => $desc,
                'Debit' => $dr,
                'Credit' => $cr,
                '_sort' => [(string) $src->declared_month, (string) $src->invoice_no, $seq, (int) $src->id],
            ];
        };

        foreach ($masters as $m) {
            if ((float) $m->grand_total > 0) $push($m, 0, $m->account_code, (string) ($accounts[$m->account_code] ?? ''), 0, (float) $m->grand_total);
            if ((float) $m->{'2307_it'} > 0) $push($m, 0, 21910, 'Withholding Tax - IT', 0, (float) $m->{'2307_it'});
            if ((float) $m->{'2307_vt'} > 0) $push($m, 0, 21910, 'Withholding Tax - VT', 0, (float) $m->{'2307_vt'});
        }
        foreach ($lines as $l) {
            $total = (float) $l->net_of_vat + (float) $l->vat_exempt + (float) $l->non_vat + (float) $l->zero_rated;
            if ((float) $l->input_vat > 0) $push($l, 1, $l->account_code, 'Input Tax', (float) $l->input_vat, 0);
            if ($total > 0) $push($l, 1, $l->account_code, (string) ($accounts[$l->account_code] ?? ''), $total, 0);
        }

        usort($rows, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        // blank row after each invoice (and after the last one), like the old app expects
        $blank = array_fill_keys(['seq', 'TransactionDate', 'APInvoiceNo', 'AccountID', 'DocumentNo', 'Name', 'LineDescription', 'Debit', 'Credit'], '');
        $out = [];
        $n = count($rows);
        foreach ($rows as $i => $row) {
            unset($row['_sort']);
            $out[] = $row;
            if ($i === $n - 1 || $row['APInvoiceNo'] !== $rows[$i + 1]['APInvoiceNo']) $out[] = $blank;
        }

        return response()->json(['result' => 'Success', 'invoices' => $out]);
    }

    /**
     * GET bir2307?userId=&from=YYYY-MM&to=YYYY-MM&branchType=MAIN&clientNum=&reportType=
     * reportType: BIR2307_S_VT | BIR2307_S_IT | BIR2307_PE_VT | BIR2307_PE_IT   (same totals as the web BIR 2307 Summary)
     */
    public function bir2307(Request $r)
    {
        $type = preg_replace('/^BIR2307_/', '', (string) $r->query('reportType', ''));
        if (!in_array($type, ['S_VT', 'S_IT', 'PE_VT', 'PE_IT'], true)) return $this->err('Invalid reportType');

        $from = substr((string) $r->query('from', ''), 0, 7);
        $to = substr((string) $r->query('to', ''), 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}$/', $to)) return $this->err('Invalid from/to (YYYY-MM)');
        if ($from > $to) [$from, $to] = [$to, $from];

        // MAIN = every client user of every branch under the client number, else the user himself
        if (strtoupper((string) $r->query('branchType', 'MAIN')) === 'MAIN') {
            $caids = DB::table('m_client_account')->where('client_num', (string) $r->query('clientNum', ''))->pluck('id');
            $ids = DB::table('m_user_client')->whereIn('caid', $caids)->pluck('id')->all();
        } else {
            $ids = [(int) $r->query('userId', 0)];
        }

        $kind = strtolower(substr($type, -2));            // vt | it
        $taxCol = "2307_{$kind}";
        $atcCol = "atc_{$kind}_id";
        $monthCol = "2307_{$kind}_declared_month";

        $sources = str_starts_with($type, 'S_')
            ? [[fn () => SalesInvoiceMaster::whereIn('added_by', $ids)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted'), 't_sales_invoice_master']]
            : [
                [fn () => PurchaseExpenseMaster::query()->active()->where('record_status', 'posted')->whereIn('added_by', $ids), 't_purchase_expense_master'],
                [fn () => GRNMaster::whereIn('receive_by', $ids), 't_grn_master'],
            ];

        $cells = [];   // [atc id (0 = none)][Y-m] => [base, tax]
        foreach ($sources as [$query, $table]) {
            if (!$ids || !$this->pick($table, [$taxCol])) continue;
            $mCol = $this->pick($table, [$monthCol, 'declared_month']);
            $base = $this->pick($table, ['sub_total', 'grand_total']);
            if (!$mCol || !$base) continue;
            $atc = $this->pick($table, [$atcCol]) ? "COALESCE(`{$atcCol}`, 0)" : '0';

            $res = $query()
                ->where($taxCol, '<>', 0)
                ->whereBetween($mCol, [$from, $to])
                ->selectRaw("{$atc} AS atc, `{$mCol}` AS m, SUM(COALESCE(`{$base}`,0)) AS base, SUM(COALESCE(`{$taxCol}`,0)) AS tax")
                ->groupByRaw('atc, m')
                ->toBase()
                ->get();

            foreach ($res as $x) {
                $a = (int) $x->atc;
                $cells[$a][$x->m][0] = ($cells[$a][$x->m][0] ?? 0) + (float) $x->base;
                $cells[$a][$x->m][1] = ($cells[$a][$x->m][1] ?? 0) + (float) $x->tax;
            }
        }

        $months = [];
        for ($c = Carbon::createFromFormat('Y-m-d', "{$from}-01"); $c->format('Y-m') <= $to && count($months) < 36; $c->addMonth()) {
            $months[] = ['month_year' => $c->format('Y-m'), 'formatted' => $c->format('m-M')];
        }

        $atcInfo = DB::table('m_atc')->whereIn('id', array_filter(array_keys($cells)))->get(['id', 'name', 'rate'])->keyBy('id');
        $pct = fn ($rate) => (float) $rate <= 1 ? (float) $rate * 100 : (float) $rate;

        $data = [];
        $foot = [];
        $grandBase = 0;
        $grandTax = 0;
        foreach ($cells as $atcId => $byMonth) {
            $info = $atcInfo[$atcId] ?? null;
            $rate = number_format($info ? $pct($info->rate) : 0, 2) . '%';
            $code = $info ? (string) $info->name : 'No ATC';
            $row = ['atc' => $code, 'rate' => $rate, 'atc_display' => "$code - $rate", 'months' => [], 'total_amount' => 0, 'total_bir2307' => 0];

            foreach ($months as $m) {
                $b = round($byMonth[$m['month_year']][0] ?? 0, 2);
                $t = round($byMonth[$m['month_year']][1] ?? 0, 2);
                $row['months'][] = ['month' => $m['formatted'], 'month_year' => $m['month_year'], 'tax_base' => $b, 'bir2307' => $t];
                $row['total_amount'] += $b;
                $row['total_bir2307'] += $t;
                $foot[$m['month_year']][0] = ($foot[$m['month_year']][0] ?? 0) + $b;
                $foot[$m['month_year']][1] = ($foot[$m['month_year']][1] ?? 0) + $t;
            }
            $grandBase += $row['total_amount'];
            $grandTax += $row['total_bir2307'];
            $data[] = $row;
        }
        usort($data, fn ($a, $b) => [$a['atc'] === 'No ATC', $a['atc']] <=> [$b['atc'] === 'No ATC', $b['atc']]);

        $footerMonths = array_map(fn ($m) => [
            'month' => $m['formatted'], 'month_year' => $m['month_year'],
            'tax_base' => round($foot[$m['month_year']][0] ?? 0, 2), 'bir2307' => round($foot[$m['month_year']][1] ?? 0, 2),
        ], $months);

        return response()->json(['result' => 'Success', 'invoices' => [
            'header' => $months,
            'data' => $data,
            'footer' => ['months' => $footerMonths, 'grand_total_tax_base' => round($grandBase, 2), 'grand_total_bir2307' => round($grandTax, 2)],
        ]]);
    }

    /* =========================================================
     * Chat – who can message who
     *   User1 (client) → User2 only · User2 (staff) → anyone · User3 (client staff) → User2 only
     * ========================================================= */

    private function canMessage(string $from, string $to): bool
    {
        return match ($from) {
            'User1', 'User3' => $to === 'User2',
            'User2' => in_array($to, self::CHAT_TYPES, true),
            default => false,
        };
    }

    private function isRoomMember($roomId, $userId, $type): bool
    {
        return DB::table('t_chat_room_member')->where('room_id', $roomId)->where('user_id', $userId)
            ->where('user_type', $type)->where('is_active', 1)->exists();
    }

    /** display name: User1 = client of m_user_client, User2 = employee of m_user_staff, User3 = client of m_user_client_staff (id = its master user_client_id, like the old MasterUserID) */
    private function chatFullName($uid, string $type): string
    {
        $name = match ($type) {
            'User1' => $this->clientNameOf(DB::table('m_user_client')->where('id', $uid)->value('caid')),
            'User2' => $this->employeeNameOf(DB::table('m_employee')->where('com_id', DB::table('m_user_staff')->where('id', $uid)->value('comid') ?? -1)->value('id')),
            'User3' => $this->clientNameOf(DB::table('m_user_client_staff')->where('user_client_id', $uid)->value('caid')),
            default => null,
        };
        return $name ?: 'Unknown';
    }

    /* ---------------- rooms ---------------- */

    /** POST chat_create_room {roomName, createdBy, createdByType, isGroup, members[{userId, userCount, fullName}]} */
    public function chatCreateRoom(Request $r)
    {
        $roomName = (string) $r->input('roomName', '');
        $createdBy = (string) $r->input('createdBy', '');
        $createdByType = (string) $r->input('createdByType', '');
        $isGroup = (int) $r->input('isGroup', 0);
        $members = (array) $r->input('members', []);

        if ($createdBy === '' || $createdByType === '') return $this->err('Missing required fields');

        foreach ($members as $m) {
            if (!$this->canMessage($createdByType, (string) ($m['userCount'] ?? ''))) {
                return $this->err('You are not allowed to create a chat with this user');
            }
        }

        $creatorName = '';
        foreach ($members as $m) {
            if ((string) ($m['userId'] ?? '') === $createdBy && (string) ($m['userCount'] ?? '') === $createdByType) {
                $creatorName = (string) ($m['fullName'] ?? '');
                break;
            }
        }

        try {
            $roomId = DB::transaction(function () use ($roomName, $createdBy, $createdByType, $isGroup, $members, $creatorName) {
                $roomId = DB::table('t_chat_room')->insertGetId([
                    'room_name' => $roomName, 'created_by' => $createdBy, 'created_by_type' => $createdByType,
                    'is_group' => $isGroup, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $add = fn ($uid, $type, $name) => DB::table('t_chat_room_member')->insert([
                    'room_id' => $roomId, 'user_id' => $uid, 'user_type' => $type,
                    'full_name' => $name, 'joined_at' => now(), 'is_active' => 1,
                ]);

                $add($createdBy, $createdByType, $creatorName);
                foreach ($members as $m) {
                    $uid = (string) ($m['userId'] ?? '');
                    $type = (string) ($m['userCount'] ?? '');
                    if ($uid !== '' && $type !== '') $add($uid, $type, (string) ($m['fullName'] ?? ''));
                }
                return $roomId;
            });

            return response()->json(['result' => 'Success', 'roomId' => $roomId,
                'debug' => ['creatorName' => $creatorName, 'membersAdded' => count($members)]]);
        } catch (Throwable $e) {
            return $this->err('Failed to create chat room: ' . $e->getMessage());
        }
    }

    /** POST chat_rooms {userId, userCount} */
    public function chatRooms(Request $r)
    {
        $userId = (string) $r->input('userId', '');
        $type = (string) $r->input('userCount', '');
        if ($userId === '' || $type === '') return $this->err('Missing required fields');

        try {
            $rows = DB::select("
                SELECT r.id, r.room_name, r.is_group, r.created_at,
                    (SELECT COUNT(*) FROM t_chat_room_message m WHERE m.room_id = r.id AND m.is_deleted = 0) AS message_count,
                    (SELECT m.message FROM t_chat_room_message m WHERE m.room_id = r.id AND m.is_deleted = 0 ORDER BY m.sent_at DESC LIMIT 1) AS last_message,
                    (SELECT m.sent_at FROM t_chat_room_message m WHERE m.room_id = r.id AND m.is_deleted = 0 ORDER BY m.sent_at DESC LIMIT 1) AS last_message_time,
                    (SELECT COUNT(*) FROM t_chat_room_message m
                      WHERE m.room_id = r.id AND m.is_deleted = 0 AND m.sender_id != ?
                        AND m.id NOT IN (SELECT rr.message_id FROM t_chat_read_receipt rr WHERE rr.user_id = ? AND rr.user_type = ?)
                    ) AS unread_count
                FROM t_chat_room r
                INNER JOIN t_chat_room_member rm ON rm.room_id = r.id
                WHERE rm.user_id = ? AND rm.user_type = ? AND rm.is_active = 1 AND r.is_active = 1
                ORDER BY last_message_time DESC, r.created_at DESC
            ", [$userId, $userId, $type, $userId, $type]);

            $members = DB::table('t_chat_room_member')->whereIn('room_id', array_column($rows, 'id'))->where('is_active', 1)
                ->get(['room_id', 'user_id', 'user_type', 'full_name'])->groupBy('room_id');

            $rooms = array_map(function ($row) use ($members, $userId, $type) {
                $list = ($members[$row->id] ?? collect())->map(fn ($m) => [
                    'userId' => $this->str($m->user_id), 'userCount' => $m->user_type, 'fullName' => $m->full_name,
                ])->values()->all();

                $name = $row->room_name;
                if (!$row->is_group && empty($name)) {
                    foreach ($list as $m) {
                        if ($m['userId'] != $userId || $m['userCount'] != $type) { $name = $m['fullName']; break; }
                    }
                }
                return [
                    'roomId' => $this->str($row->id),
                    'roomName' => $name,
                    'isGroup' => (bool) $row->is_group,
                    'messageCount' => (int) $row->message_count,
                    'lastMessage' => $row->last_message,
                    'lastMessageTime' => $row->last_message_time,
                    'unreadCount' => (int) $row->unread_count,
                    'members' => $list,
                ];
            }, $rows);

            return response()->json(['result' => 'Success', 'rooms' => $rooms]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST chat_messages {roomId, userId, userCount} */
    public function chatMessages(Request $r)
    {
        $roomId = (string) $r->input('roomId', '');
        $userId = (string) $r->input('userId', '');
        $type = (string) $r->input('userCount', '');
        if ($roomId === '' || $userId === '' || $type === '') return $this->err('Missing required fields');

        try {
            if (!$this->isRoomMember($roomId, $userId, $type)) return $this->err('Not a member of this room');

            $rows = DB::select("
                SELECT m.id, m.room_id, m.sender_id, m.user_type, m.full_name, m.message, m.has_attachment, m.sent_at,
                       (SELECT COUNT(*) FROM t_chat_read_receipt rr
                         WHERE rr.message_id = m.id AND rr.user_id = ? AND rr.user_type = ?) AS is_read
                FROM t_chat_room_message m
                WHERE m.room_id = ? AND m.is_deleted = 0
                ORDER BY m.sent_at ASC
            ", [$userId, $type, $roomId]);

            $withFiles = array_column(array_filter($rows, fn ($x) => (bool) $x->has_attachment), 'id');
            $files = $withFiles
                ? DB::table('t_chat_attachment')->whereIn('message_id', $withFiles)->where('is_deleted', 0)->get()->groupBy('message_id')
                : collect();

            $messages = array_map(fn ($row) => [
                'messageId' => $this->str($row->id),
                'roomId' => $this->str($row->room_id),
                'senderId' => $this->str($row->sender_id),
                'userCount' => $row->user_type,
                'senderName' => $row->full_name,
                'content' => $row->message,
                'hasAttachment' => (bool) $row->has_attachment,
                'attachments' => ($files[$row->id] ?? collect())->map(fn ($a) => [
                    'attachmentId' => $this->str($a->id),
                    'fileName' => $a->file_name,
                    'filePath' => $a->file_path,
                    'fileType' => $a->file_type,
                    'fileSize' => (int) $a->file_size,
                    'mimeType' => $a->mime_type,
                ])->values()->all(),
                'timestamp' => $row->sent_at,
                'isRead' => (bool) $row->is_read,
            ], $rows);

            return response()->json(['result' => 'Success', 'messages' => $messages]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST chat_send_message {roomId, senderId, userCount, fullName, message} */
    public function chatSendMessage(Request $r)
    {
        $roomId = (string) $r->input('roomId', '');
        $senderId = (string) $r->input('senderId', '');
        $type = (string) $r->input('userCount', '');
        $fullName = (string) $r->input('fullName', '');
        $message = (string) $r->input('message', '');

        if ($roomId === '' || $senderId === '' || $type === '' || $fullName === '') return $this->err('Missing required fields');

        try {
            if (!$this->isRoomMember($roomId, $senderId, $type)) return $this->err('Not a member of this room');

            $recipientType = DB::table('t_chat_room_member')->where('room_id', $roomId)
                ->where('user_id', '!=', $senderId)->where('is_active', 1)->value('user_type');
            if ($recipientType === null) return $this->err('No recipient found in this room');
            if (!$this->canMessage($type, (string) $recipientType)) return $this->err('You are not allowed to send messages to this user');

            $id = DB::table('t_chat_room_message')->insertGetId([
                'room_id' => $roomId, 'sender_id' => $senderId, 'user_type' => $type, 'full_name' => $fullName,
                'message' => $message, 'has_attachment' => 0, 'is_deleted' => 0, 'sent_at' => now(),
            ]);

            return response()->json(['result' => 'Success', 'messageId' => $id, 'timestamp' => now()->format('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            return $this->err('Failed to send message: ' . $e->getMessage());
        }
    }

    /** POST chat_mark_read {roomId, userId, userCount} */
    public function chatMarkRead(Request $r)
    {
        $roomId = (string) $r->input('roomId', '');
        $userId = (string) $r->input('userId', '');
        $type = (string) $r->input('userCount', '');
        if ($roomId === '' || $userId === '' || $type === '') return $this->err('Missing required fields');

        try {
            if (!$this->isRoomMember($roomId, $userId, $type)) return $this->err('Not a member of this room');

            $unread = DB::table('t_chat_room_message as m')
                ->where('m.room_id', $roomId)->where('m.sender_id', '!=', $userId)->where('m.is_deleted', 0)
                ->whereNotIn('m.id', fn ($q) => $q->select('message_id')->from('t_chat_read_receipt')
                    ->where('user_id', $userId)->where('user_type', $type))
                ->pluck('m.id');

            DB::table('t_chat_read_receipt')->insert($unread->map(fn ($id) => [
                'message_id' => $id, 'user_id' => $userId, 'user_type' => $type, 'read_at' => now(),
            ])->all());

            return response()->json(['result' => 'Success', 'markedCount' => $unread->count()]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* ---------------- direct messages (t_chat_message) ---------------- */

    /** POST chat_get_conversations {userId, userCount} */
    public function chatGetConversations(Request $r)
    {
        $userId = trim((string) $r->input('userId', ''));
        $type = trim((string) $r->input('userCount', ''));
        if ($userId === '' || $type === '') return $this->err('Missing required fields');

        try {
            $rows = DB::select("
                SELECT m.id, m.sender_id, m.sender_type, m.receiver_id, m.receiver_type, m.message, m.sent_at, m.is_read
                FROM t_chat_message m
                INNER JOIN (
                    SELECT MAX(id) AS max_id
                    FROM t_chat_message
                    WHERE (sender_id = ? AND sender_type = ?) OR (receiver_id = ? AND receiver_type = ?)
                    GROUP BY
                        CASE WHEN sender_id = ? AND sender_type = ? THEN receiver_id ELSE sender_id END,
                        CASE WHEN sender_id = ? AND sender_type = ? THEN receiver_type ELSE sender_type END
                ) latest ON m.id = latest.max_id
                ORDER BY m.sent_at DESC
            ", [$userId, $type, $userId, $type, $userId, $type, $userId, $type]);

            $names = [];
            $nameOf = function ($uid, $t) use (&$names) {
                return $names["{$uid}_{$t}"] ??= $this->chatFullName($uid, (string) $t);
            };

            $conversations = [];
            foreach ($rows as $row) {
                $mine = $row->sender_id == $userId && $row->sender_type == $type;
                $otherId = $mine ? $row->receiver_id : $row->sender_id;
                $otherType = $mine ? $row->receiver_type : $row->sender_type;
                $key = "{$otherId}_{$otherType}";
                if (isset($conversations[$key])) continue;

                $conversations[$key] = [
                    'userId' => $otherId,
                    'userType' => $otherType,
                    'fullName' => $nameOf($otherId, $otherType),
                    'lastMessage' => $row->message ?? '',
                    'lastMessageTime' => $row->sent_at,
                    'lastSenderId' => $row->sender_id,
                    'lastSenderName' => $nameOf($row->sender_id, $row->sender_type),
                    'unreadCount' => 0,
                ];
            }

            if ($conversations) {
                $unread = DB::table('t_chat_message')
                    ->selectRaw('sender_id, sender_type, COUNT(*) AS unread')
                    ->where('receiver_id', $userId)->where('receiver_type', $type)->where('is_read', 0)
                    ->groupBy('sender_id', 'sender_type')->get();
                foreach ($unread as $u) {
                    $key = "{$u->sender_id}_{$u->sender_type}";
                    if (isset($conversations[$key])) $conversations[$key]['unreadCount'] = (int) $u->unread;
                }
            }

            $conversations = array_values($conversations);
            usort($conversations, fn ($a, $b) => strtotime($b['lastMessageTime']) - strtotime($a['lastMessageTime']));

            return response()->json(['result' => 'Success', 'conversations' => $conversations]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST chat_get_messages {currentUserId, currentUserType, otherUserId, otherUserType, limit, offset} */
    public function chatGetMessages(Request $r)
    {
        $me = trim((string) $r->input('currentUserId', ''));
        $meType = trim((string) $r->input('currentUserType', ''));
        $other = trim((string) $r->input('otherUserId', ''));
        $otherType = trim((string) $r->input('otherUserType', ''));
        $limit = (int) $r->input('limit', 50);
        $limit = $limit > 100 ? 100 : ($limit < 1 ? 50 : $limit);
        $offset = max(0, (int) $r->input('offset', 0));

        if ($me === '' || $meType === '' || $other === '' || $otherType === '') return $this->err('Missing required fields');

        try {
            $between = fn ($q) => $q
                ->where(fn ($w) => $w->where('sender_id', $me)->where('sender_type', $meType)->where('receiver_id', $other)->where('receiver_type', $otherType))
                ->orWhere(fn ($w) => $w->where('sender_id', $other)->where('sender_type', $otherType)->where('receiver_id', $me)->where('receiver_type', $meType));

            $rows = DB::table('t_chat_message')->where($between)
                ->orderBy('sent_at')->orderBy('id')->offset($offset)->limit($limit)->get();

            $names = [];
            $nameOf = function ($uid, $t) use (&$names) {
                return $names["{$uid}_{$t}"] ??= $this->chatFullName($uid, (string) $t);
            };

            $messages = $rows->map(fn ($m) => [
                'messageId' => $m->id,
                'senderId' => $m->sender_id,
                'senderType' => $m->sender_type,
                'senderName' => $nameOf($m->sender_id, $m->sender_type),
                'receiverId' => $m->receiver_id,
                'receiverType' => $m->receiver_type,
                'content' => $m->message ?? '',
                'attachmentUrl' => $m->attachment,
                'attachmentName' => $m->attachment_name,
                'attachmentType' => $m->attachment_type,
                'timestamp' => $m->sent_at,
                'isRead' => (bool) $m->is_read,
            ])->values();

            DB::table('t_chat_message')
                ->where('sender_id', $other)->where('sender_type', $otherType)
                ->where('receiver_id', $me)->where('receiver_type', $meType)
                ->where('is_read', 0)->update(['is_read' => 1]);

            return response()->json(['result' => 'Success', 'messages' => $messages, 'count' => $messages->count(), 'offset' => $offset, 'limit' => $limit]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST chat_send_message_new {senderId, senderType, receiverId, receiverType, message, attachment (base64), attachmentName, attachmentType} */
    public function chatSendMessageNew(Request $r)
    {
        $senderId = trim((string) $r->input('senderId', ''));
        $senderType = trim((string) $r->input('senderType', ''));
        $receiverId = trim((string) $r->input('receiverId', ''));
        $receiverType = trim((string) $r->input('receiverType', ''));
        $message = trim((string) $r->input('message', ''));
        $b64 = $r->input('attachment');
        $attName = trim((string) $r->input('attachmentName', ''));
        $attType = trim((string) $r->input('attachmentType', ''));

        if ($senderId === '' || $senderType === '' || $receiverId === '' || $receiverType === '') return $this->err('Missing required fields');
        if ($message === '' && empty($b64)) return $this->err('Message or attachment is required');
        if (!$this->canMessage($senderType, $receiverType)) return $this->err('You are not allowed to send messages to this user');

        try {
            $url = null;
            if (!empty($b64)) {
                $decoded = base64_decode((string) $b64, true);
                if ($decoded === false) return $this->err('Invalid attachment data');

                $fileName = uniqid('att_', true) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($attName));
                $dir = public_path('uploads/chat');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (file_put_contents($dir . DIRECTORY_SEPARATOR . $fileName, $decoded) === false) return $this->err('Failed to save attachment');
                $url = asset('uploads/chat/' . $fileName);
            }

            $id = DB::table('t_chat_message')->insertGetId([
                'sender_id' => $senderId, 'sender_type' => $senderType,
                'receiver_id' => $receiverId, 'receiver_type' => $receiverType,
                'message' => $message, 'attachment' => $url,
                'attachment_name' => $attName ?: null, 'attachment_type' => $attType ?: null,
                'sent_at' => now(), 'is_read' => 0,
            ]);

            return response()->json(['result' => 'Success', 'messageId' => $id, 'timestamp' => now()->format('Y-m-d H:i:s'), 'attachmentUrl' => $url]);
        } catch (Throwable $e) {
            return $this->err('Failed to send message: ' . $e->getMessage());
        }
    }

    /** POST get_user2_list {userId, userCount}   staff logins the app can chat with (User2) */
    public function getUser2List(Request $r)
    {
        $userId = (string) $r->input('userId', '');
        $type = (string) $r->input('userCount', '');

        try {
            $active = $this->pick('m_user_staff', ['is_active', 'active']);
            $users = DB::table('m_user_staff as S')
                ->leftJoin('m_employee as E', 'E.com_id', '=', 'S.comid')         // staff login → employee (same as AuthController)
                ->when($active, fn ($q) => $q->where("S.{$active}", 1))
                ->when($type === 'User2' && $userId !== '', fn ($q) => $q->where('S.id', '!=', $userId))
                ->orderBy('E.last_name')->orderBy('E.first_name')
                ->get(['S.id', 'S.username', DB::raw("TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name)) AS full_name")])
                ->map(fn ($u) => [
                    'userId' => $this->str($u->id),
                    'fullName' => $u->full_name ?: $u->username,
                    'username' => $u->username,
                    'caid' => null,                  // old ComID – staff are not tied to a client account
                    'userCount' => 'User2',
                ])->values();

            return response()->json(['success' => true, 'users' => $users, 'count' => $users->count()]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    /* =========================================================
     * Chat debug (old debug_*.php) – only answers when APP_DEBUG=true
     * ========================================================= */

    private function debugOff(): ?JsonResponse
    {
        return config('app.debug') ? null : response()->json(['result' => 'Error', 'message' => 'Debug endpoints are off (APP_DEBUG=false).'], 404);
    }

    private function roomDebug($roomId): array
    {
        return [
            'allRoomMembers' => DB::table('t_chat_room_member')->where('room_id', $roomId)->get(),
            'roomInfo' => DB::table('t_chat_room')->where('id', $roomId)->first(),
        ];
    }

    /** GET debug_chat_membership?userId=&userCount=&roomId= */
    public function debugChatMembership(Request $r)
    {
        if ($off = $this->debugOff()) return $off;
        $userId = (string) $r->query('userId', '');
        $type = (string) $r->query('userCount', '');
        $roomId = (string) $r->query('roomId', '');
        if ($userId === '' || $type === '' || $roomId === '') {
            return $this->err('Missing required parameters: userId, userCount, roomId', ['usage' => 'debug_chat_membership?userId=1&userCount=User1&roomId=1']);
        }

        $mine = DB::table('t_chat_room_member')->where('room_id', $roomId)->where('user_id', $userId)->where('user_type', $type)->get();

        return response()->json([
            'result' => 'Success', 'userId' => $userId, 'userCount' => $type, 'roomId' => $roomId,
            'isMember' => $mine->isNotEmpty(), 'memberCount' => $mine->count(), 'userMemberships' => $mine,
        ] + $this->roomDebug($roomId) + [
            'diagnosis' => $mine->isEmpty()
                ? 'User is NOT a member of this room. Check UserID and UserCount values.'
                : 'User IS a member of this room. Check if is_active = 1.',
        ]);
    }

    /** POST debug_send_message {roomId, senderId, userCount, fullName, message} – same checks as chat_send_message, nothing saved */
    public function debugSendMessage(Request $r)
    {
        if ($off = $this->debugOff()) return $off;
        $received = [
            'roomId' => (string) $r->input('roomId', ''), 'senderId' => (string) $r->input('senderId', ''),
            'userCount' => (string) $r->input('userCount', ''), 'fullName' => (string) $r->input('fullName', ''),
            'message' => (string) $r->input('message', ''),
        ];
        if ($received['roomId'] === '' || $received['senderId'] === '' || $received['userCount'] === '') {
            return $this->err('Missing required fields', ['receivedData' => $received]);
        }

        $isMember = $this->isRoomMember($received['roomId'], $received['senderId'], $received['userCount']);
        if (!$isMember) {
            return $this->err('Not a member of this room', ['receivedData' => $received, 'debug' => [
                'lookingFor' => ['roomId' => $received['roomId'], 'userId' => $received['senderId'], 'userCount' => $received['userCount'], 'isActive' => 1],
            ] + $this->roomDebug($received['roomId']) + ['roomExists' => DB::table('t_chat_room')->where('id', $received['roomId'])->exists()]]);
        }

        return response()->json(['result' => 'Success', 'receivedData' => $received,
            'message' => 'User IS a member of this room. The message should send successfully.']);
    }

    /** GET comprehensive_debug?user1=&user2=   last rooms / members / messages + rooms shared by a User1 and a User2 */
    public function comprehensiveDebug(Request $r)
    {
        if ($off = $this->debugOff()) return $off;
        try {
            $u1 = (string) $r->query('user1', '');
            $u2 = (string) $r->query('user2', '');
            $rooms = DB::table('t_chat_room')->orderByDesc('id')->limit(10)->get();
            $members = DB::table('t_chat_room_member')->orderByDesc('id')->limit(20)->get();
            $messages = DB::table('t_chat_room_message')->orderByDesc('id')->limit(10)->get();

            $memberships = fn ($uid, $type) => DB::table('t_chat_room_member as rm')
                ->leftJoin('t_chat_room as r', 'r.id', '=', 'rm.room_id')
                ->where('rm.user_id', $uid)->where('rm.user_type', $type)
                ->get(['rm.*', 'r.room_name']);
            $m1 = $memberships($u1, 'User1');
            $m2 = $memberships($u2, 'User2');

            $shared = DB::table('t_chat_room_member')
                ->whereIn('room_id', $m1->pluck('room_id'))->whereIn('room_id', $m2->pluck('room_id'))
                ->get()->groupBy('room_id')
                ->map(fn ($list, $roomId) => [
                    'roomId' => $roomId,
                    'members' => $list->map(fn ($m) => "{$m->user_id} ({$m->user_type}) - {$m->full_name}")->implode(', '),
                ])->values();

            return response()->json(['result' => 'Success', 'debug' => [
                'allRooms' => $rooms, 'allMembers' => $members, 'allMessages' => $messages,
                'user1Memberships' => $m1, 'user2Memberships' => $m2, 'sharedRoomsBetweenUser1AndUser2' => $shared,
                'summary' => [
                    'totalRooms' => $rooms->count(), 'totalMembers' => $members->count(), 'totalMessages' => $messages->count(),
                    'user1HasRooms' => $m1->isNotEmpty(), 'user2HasRooms' => $m2->isNotEmpty(), 'user1AndUser2ShareRooms' => $shared->isNotEmpty(),
                ],
            ]]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Lists
     * ========================================================= */

    /** new snake_case row → the old PascalCase keys (id → ID, created_at → DateAdded, trans_type → TransType …) */
    private function legacy($row): array
    {
        $out = [];
        foreach ((array) $row as $k => $v) {
            $key = self::LEGACY_KEYS[$k] ?? (preg_match('/^\d/', $k)
                ? strtoupper($k)                                                   // 2307_vt → 2307_VT
                : str_replace(' ', '', ucwords(str_replace('_', ' ', $k))));      // trans_type → TransType
            $out[$key] = $this->str($v);
        }
        return $out;
    }

    /** GET cheque_type_list */
    public function chequeTypeList()
    {
        $list = DB::table('m_payment_cheque_type')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($c) => ['ChequeTypeID' => $this->str($c->id), 'ChequeType' => $c->name]);
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET client_name_list */
    public function clientNameList()
    {
        $name = $this->pick('m_client', ['registered_name', 'client_name', 'business_name', 'company_name', 'name']);
        $person = "TRIM(CONCAT_WS(' ', last_name, first_name, middle_name))";
        $expr = $name ? "COALESCE(NULLIF(TRIM({$name}), ''), {$person})" : $person;

        $list = DB::table('m_client')
            ->selectRaw("client_num AS ClientNum, {$expr} AS ClientName")
            ->havingRaw("ClientName <> ''")
            ->orderBy('ClientName')
            ->get()
            ->map(fn ($c) => ['ClientNum' => $this->str($c->ClientNum), 'ClientName' => $c->ClientName]);

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET client_account_list?ClientNum=   branches of a client ("branch code + trade name") */
    public function clientAccountList(Request $r)
    {
        $list = DB::table('m_client_account')->where('client_num', (string) $r->query('ClientNum', ''))
            ->orderBy('branch_code')->get()
            ->map(fn ($a) => [
                'CAID' => $this->str($a->id),
                'ClientNum' => $this->str($a->client_num),
                'BranchCode' => $this->str($a->branch_code ?? ''),
                'BranchTypeID' => $this->str($a->branch_type ?? ''),
                'TradeName' => trim(($a->branch_code ?? '') . ' ' . ($a->trade_name ?? '')),
                'TIN' => $this->str($a->tin ?? ''),
                'Street' => $a->street ?? null,
                'AssignedDAID' => $this->str($a->assigned_daid ?? null),
                'StatusID' => $this->str($a->status_id ?? null),
            ]);

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET documents?fromDate=&toDate= */
    public function documents(Request $r)
    {
        try {
            $from = $r->query('fromDate');
            $to = $r->query('toDate');
            $rows = DB::table('m_documents')
                ->when($from && $to, fn ($q) => $q->whereBetween('created_at', [$from, $to]))
                ->when($from && !$to, fn ($q) => $q->where('created_at', '>=', $from))
                ->when(!$from && $to, fn ($q) => $q->where('created_at', '<=', $to))
                ->orderByDesc('id')->get()
                ->map(fn ($d) => $this->legacy($d));

            return response()->json(['result' => 'Success', 'invoices' => $rows]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Billing lists (t_payment)
     * ========================================================= */

    /** t_payment + client account + collection type, with the old keys */
    private function paymentQuery()
    {
        $q = DB::table('t_payment as P')
            ->leftJoin('m_client_account as A', 'A.id', '=', 'P.ca_id')
            ->leftJoin('m_payment_col_type as COL', 'COL.id', '=', 'P.payment_col_type_id')
            ->select(['P.*', DB::raw("COALESCE(A.trade_name, 'NO CLIENT RECORD') AS trade_name"), DB::raw('COL.name AS col_type_name')]);
        $bankCol = $this->pick('m_accounting_bank_account', ['account_number', 'account_no', 'number']);
        if ($bankCol) {
            $q->leftJoin('m_accounting_bank_account as BA', 'BA.id', '=', 'P.assigned_bank')->addSelect(DB::raw("BA.{$bankCol} AS bank_account"));
        }
        return $q;
    }

    private function paymentRow($p): array
    {
        $d = fn ($v) => $v ? substr((string) $v, 0, 10) : null;
        return [
            'PaymentID' => $this->str($p->id),
            'PaymentColTypeID' => $this->str($p->payment_col_type_id ?? null),
            'PaymentColType' => $p->col_type_name ?? null,
            'ReceiptTypeID' => $this->str($p->receipt_type_id ?? null),
            'AssignedBank' => $this->str($p->assigned_bank ?? 0),
            'ARNumber' => (string) ($p->ar_number ?? ''),
            'APNumber' => (string) ($p->ap_number ?? ''),
            'ORNumber' => (string) ($p->or_number ?? ''),
            'ClientNum' => $this->str($p->client_num ?? null),
            'CAID' => $this->str($p->ca_id ?? null),
            'ServicePeriod' => $p->service_period ?? null,
            'TransNumber' => $p->trans_number ?? null,
            'Amount' => $this->str($p->amount ?? 0),
            'QTY' => $this->str($p->qty ?? 1),
            'COAmount' => $this->str($p->co_amount ?? 0),
            'TotalGrossAmountCal' => $this->str($p->total_gross_amount_cal ?? 0),
            'TotalAmount' => $this->str($p->total_amount ?? 0),
            'DueDate' => $d($p->due_date ?? null),
            'PaymentTypeID' => $this->str($p->payment_type_id ?? null),
            'PaymentDate' => $d($p->payment_date ?? null),
            'Details1' => $p->details1 ?? null,
            'Details2' => $p->details2 ?? null,
            'ChequeTypeID' => $this->str($p->cheque_type_id ?? null),
            'ChequeTypeDate' => $d($p->cheque_type_date ?? null),
            'Remarks' => $p->remarks ?? null,
            'TradeName' => $p->trade_name,
            'BankAccount' => $p->bank_account ?? null,
            'DepSlipNumber' => $this->str($p->dep_slip_number ?? null),
            'AssignedBankDate' => $d($p->assigned_bank_date ?? null),
        ];
    }

    /** WHERE col IS NULL OR col = 0 (only when the column exists) */
    private function zeroOrNull($q, ?string $col)
    {
        if ($col) $q->where(fn ($w) => $w->whereNull("P.{$col}")->orWhere("P.{$col}", 0));
        return $q;
    }

    /** GET collection_list?PaymentTypeID=   payments on hand (not yet assigned to a bank) */
    public function collectionList(Request $r)
    {
        $q = $this->paymentQuery()->where('P.payment_type_id', (int) $r->query('PaymentTypeID', 0));
        $this->zeroOrNull($q, $this->pick('t_payment', ['assigned_bank']));
        $list = $q->orderBy('P.ar_number')->get()->map(fn ($p) => $this->paymentRow($p));

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET deposit_list   payments assigned to a bank, waiting for the deposit (old qry2_deposit_list) */
    public function depositList()
    {
        $by = $this->pick('t_payment', ['assigned_by', 'assigned_bank_by']);
        $q = $this->paymentQuery();
        if ($by) $q->where("P.{$by}", '>', 0);
        $this->zeroOrNull($q, $this->pick('t_payment', ['prepared_by_id']));
        if ($pd = $this->pick('t_payment', ['prepared_date'])) $q->orderByDesc("P.{$pd}");
        $list = $q->orderByDesc('P.id')->get()->map(fn ($p) => $this->paymentRow($p));

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /* =========================================================
     * Scans (t_mobile_transaction)
     * ========================================================= */

    /** GET expenses?userId=&from=YYYY-MM&to=YYYY-MM   scanned expenses of the user */
    public function expenses(Request $r)
    {
        return $this->scanList($r, 'expenses');
    }

    /** scans (t_mobile_transaction) of one type, by month the file was added */
    private function scanList(Request $r, string $type)
    {
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '');
        $fromDate = $from !== '' ? "{$from}-01 00:00:00" : null;
        $toDate = ($to !== '' && ($c = \DateTime::createFromFormat('Y-m', $to))) ? $c->modify('last day of this month')->format('Y-m-d') . ' 23:59:59' : null;

        try {
            $rows = DB::table('t_mobile_transaction')
                ->where('trans_type', $type)
                ->where('added_by', (int) $r->query('userId', 0))
                ->when($fromDate && $toDate, fn ($q) => $q->whereBetween('created_at', [$fromDate, $toDate]))
                ->when($fromDate && !$toDate, fn ($q) => $q->where('created_at', '>=', $fromDate))
                ->when(!$fromDate && $toDate, fn ($q) => $q->where('created_at', '<=', $toDate))
                ->orderByDesc('id')->get()
                ->map(function ($row) {
                    $out = $this->legacy($row);
                    $out['Status'] = [0 => 'Pending', 1 => 'Confirmed', 2 => 'Invalid', 3 => 'Partially Invalid'][(int) ($row->trans_status ?? -1)] ?? '';
                    return $out;
                });

            return response()->json(['result' => 'Success', 'invoices' => $rows]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** GET|DELETE delete_transaction?id=   remove a scan */
    public function deleteTransaction(Request $r)
    {
        $id = $r->input('id');
        if (!is_numeric($id)) return $this->err('Invalid or missing ID');

        try {
            return DB::table('t_mobile_transaction')->where('id', (int) $id)->delete() > 0
                ? response()->json(['result' => 'Success', 'message' => 'Record deleted successfully'])
                : $this->err('No record found with that ID');
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Expenses (t_purchase_expense_*)
     * ========================================================= */

    /** client users of the report: MAIN = every branch under clientNum, else the user himself (old $Username list) */
    private function reportUsers(Request $r): array
    {
        if (strtoupper((string) $r->query('branchType', 'MAIN')) === 'MAIN') {
            $caids = DB::table('m_client_account')->where('client_num', (string) $r->query('clientNum', ''))->pluck('id');
            return DB::table('m_user_client')->whereIn('caid', $caids)->pluck('id')->all();
        }
        return [(int) $r->query('userId', 0)];
    }

    /** GET expense_report?userId=&from=YYYY-MM&to=YYYY-MM&branchType=&clientNum=   posted expense lines */
    public function expenseReport(Request $r)
    {
        $ids = $this->reportUsers($r);
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;

        $lines = PurchaseExpenseDetail::whereIn('added_by', $ids ?: [0])
            ->active()->where('record_status', 'posted')->expenses()
            ->whereBetween('declared_month', [$from, $to])
            ->orderBy('declared_month')->orderBy('id')
            ->get();

        $vendors = DB::table('m_vendor_customer')->whereIn('id', $lines->pluck('vendor_id')->filter()->unique())->get()->keyBy('id');
        $accounts = DB::table('m_charts_of_account')->whereIn('added_by', $ids ?: [0])
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);

        $rows = $lines->map(function ($l) use ($vendors, $accounts) {
            $v = $vendors[$l->vendor_id] ?? null;
            $date = $l->transaction_date ? Carbon::parse($l->transaction_date) : null;
            $doc = (string) ($l->document_no ?? '');
            $total = (float) ($l->amount ?? 0);
            return [
                'ID' => $this->str($l->id),
                'BranchCode' => $this->str($v->branch_code ?? ''),
                'PODate' => $date ? $date->format('d-M-y') : '',
                'PODate2' => $date ? $date->format('Y-m-d') : '',
                'DeclaredMonth' => (string) $l->declared_month,
                'TIN' => $this->str($v->tin ?? ''),
                'VendorName' => $v->name ?? '',
                'DocumentNo' => strlen($doc) > 3 ? $doc : (string) $l->invoice_no,
                'PONumber' => (string) $l->invoice_no,
                'AccountCode' => $this->str($l->account_code),
                'Account_Name' => (string) ($accounts[$l->added_by . '|' . $l->account_code] ?? ''),
                'Total' => $this->str($total),
                'Currency' => $l->currency ?: 'PHP',
                'ExRate' => $this->str($l->ex_rate ?: '1.0000'),
                'Total2' => $this->str($total),
                'NetOfVAT' => $this->str($l->net_of_vat ?? 0),
                'inputVAT' => $this->str($l->input_vat ?? 0),
                'VATExempt' => $this->str($l->vat_exempt ?? 0),
                'ZeroRated' => $this->str($l->zero_rated ?? 0),
                'TableType' => 'PO',
                'NonVAT' => $this->str($l->non_vat ?? 0),
                'DateAdded' => optional($l->created_at)->format('Y-m-d H:i:s'),
                'DateAdded2' => optional($l->created_at)->format('Y-m-d'),
            ];
        })->values();

        return response()->json(['result' => 'Success', 'invoices' => $rows]);
    }

    /** GET expenses_info?InvoiceNo=&UserID=   one expense invoice + its lines */
    public function expensesInfo(Request $r)
    {
        $invoiceNo = (string) $r->query('InvoiceNo', '');
        $userId = (int) $r->query('UserID', 0);

        try {
            $masters = DB::table('t_purchase_expense_master as M')
                ->leftJoin('m_vendor_customer as V', 'V.id', '=', 'M.vendor_id')
                ->where('M.invoice_no', $invoiceNo)
                ->when($userId, fn ($q) => $q->where('M.added_by', $userId))
                ->get(['M.*', 'V.name as vendor_name', 'V.tin as vendor_tin', 'V.branch_code as vendor_branch_code', 'V.street as vendor_street']);

            $itemTax = $this->pick('m_items', ['tax_type', 'item_tax_type_id', 'tax_type_id']);
            $itemPrice = $this->pick('m_items', ['price', 'selling_price', 'unit_price']);
            $itemCost = $this->pick('m_items', ['cost', 'unit_cost', 'purchase_price']);

            $details = DB::table('t_purchase_expense_detail as A')
                ->leftJoin('m_items as B', 'B.id', '=', 'A.item_id')
                ->leftJoin('m_charts_of_account as C', fn ($j) => $j->on('C.account_code', '=', 'A.account_code')->on('C.added_by', '=', 'A.added_by'))
                ->leftJoin('m_unit_type as D', 'D.id', '=', 'A.unit_type_id')
                ->where('A.invoice_no', $invoiceNo)
                ->when($userId, fn ($q) => $q->where('A.added_by', $userId))
                ->orderByDesc('A.id')
                ->get(array_filter([
                    'A.*', 'B.item_code', 'B.description as item_description', 'C.account_code as coa_code', 'C.account_name as coa_name', 'D.name as unit_name',
                    $itemTax ? "B.{$itemTax} as item_tax_type" : null,
                    $itemPrice ? "B.{$itemPrice} as item_price" : null,
                    $itemCost ? "B.{$itemCost} as item_cost" : null,
                ]))
                ->map(fn ($a) => [
                    'ID' => $this->str($a->id),
                    'APInvoiceNo' => (string) $a->invoice_no,
                    'PONumber' => (string) $a->invoice_no,
                    'ItemID' => $this->str($a->item_id),
                    'ItemCode' => $a->item_code,
                    'Description' => $a->item_description,
                    'TaxType' => $this->str($a->item_tax_type ?? null),
                    'UnitType' => $this->str($a->unit_type_id),
                    'Code' => $this->str($a->coa_code),
                    'AccountTitle' => $a->coa_name,
                    'AccountCode' => $this->str($a->account_code),
                    'POID' => null,
                    'UnitTypeDesc' => (string) ($a->unit_name ?? ''),
                    'ProdUnitVal' => (string) ($a->unit_name ?? '0'),
                    'UnitTypeID' => $this->str($a->unit_type_id ?? 0),
                    'UnitPrice' => $this->str($a->item_price ?? 0),
                    'UnitCost' => $this->str($a->item_cost ?? 0),
                    'ProductPrice' => $this->str($a->item_price ?? 0),
                    'Qty' => $this->str($a->quantity),
                    'Price' => $this->str($a->unit_price),
                    'Total' => $this->str($a->amount),
                    'inputVAT' => $this->str($a->input_vat),
                    'DiscountAmount' => $this->str($a->discount_amount),
                    'VATExempt' => $this->str($a->vat_exempt),
                    'NonVAT' => $this->str($a->non_vat),
                    'ZeroRated' => $this->str($a->zero_rated),
                    'NetOfVAT' => $this->str($a->net_of_vat),
                ])->values();

            $invoices = $masters->map(function ($m) use ($details) {
                $row = $this->legacy($m);
                $row['APInvoiceNo'] = (string) $m->invoice_no;
                $row['VendorName'] = $m->vendor_name;
                $row['detail'] = $details;
                return $row;
            });

            return response()->json(['result' => 'Success', 'invoices' => $invoices]);
        } catch (Throwable $e) {
            return $this->err('Execution failed: ' . $e->getMessage());
        }
    }

    /* =========================================================
     * Dashboard
     * ========================================================= */

    /** GET dashboard?userId=&from=YYYY-MM&to=YYYY-MM   same rules as the web Home dashboard (posted, not void) */
    public function dashboard(Request $r)
    {
        $by = (int) $r->query('userId', 0);
        $from = (string) $r->query('from', date('Y-m'));
        $to = (string) $r->query('to', date('Y-m'));
        $voidId = (int) (DB::table('m_invoice_status')->whereRaw("UPPER(name) LIKE 'VOID%'")->value('id') ?: 5);

        $months = [];
        for ($i = 5; $i >= 0; $i--) $months[] = now()->startOfMonth()->subMonths($i)->format('Y-m');

        $sales = fn () => DB::table('t_sales_invoice_master')->where('added_by', $by)->where('invoice_status_id', '<>', $voidId)
            ->where(fn ($w) => $w->whereNull('trans_type')->orWhere('trans_type', 'posted'));
        $buy = function (string $type) use ($by, $voidId) {
            $q = DB::table('t_purchase_expense_master')->where('added_by', $by)->where('invoice_status_id', '<>', $voidId)
                ->whereRaw('LOWER(trans_type) = ?', [$type])->where('grand_total', '>', 0);
            if ($this->pick('t_purchase_expense_master', ['record_status'])) {
                $q->where(fn ($w) => $w->whereNull('record_status')->orWhere('record_status', 'posted'));
            }
            return $q;
        };
        $vc = fn (string $type) => (int) DB::table('m_vendor_customer')->where('entry_by', $by)->where('user_type', $type)->count();

        $monthly = function ($q, string $key) use ($months) {
            $byMonth = $q->whereIn('declared_month', $months)->groupBy('declared_month')
                ->selectRaw('declared_month AS m, SUM(grand_total) AS t')->pluck('t', 'm');
            $list = array_map(fn ($m) => ['month' => date('M Y', strtotime("$m-01")), $key => (float) ($byMonth[$m] ?? 0)], $months);
            return [$list, max(array_column($list, $key) ?: [0])];
        };

        try {
            [$mSales, $maxSales] = $monthly($sales(), 'sales');
            [$mPurch, $maxPurch] = $monthly($buy('purchases'), 'purchases');
            [$mExp, $maxExp] = $monthly($buy('expenses'), 'expenses');

            return response()->json([
                'NoOfCustomer' => $vc('Customer'),
                'noOfVendors' => $vc('Vendor'),
                'totalSales' => round((float) $sales()->whereBetween('declared_month', [$from, $to])->sum('grand_total'), 2),
                'totalExpenses' => round((float) $buy('expenses')->whereBetween('declared_month', [$from, $to])->sum('grand_total'), 2),
                'totalPurchases' => round((float) $buy('purchases')->whereBetween('declared_month', [$from, $to])->sum('grand_total'), 2),
                'totalScanned' => (int) DB::table('t_mobile_transaction')->where('added_by', $by)->count(),
                'monthlySales' => $mSales,
                'monthlyPurchases' => $mPurch,
                'monthlyExpenses' => $mExp,
                'maxSales' => (float) $this->roundUpAdaptive($maxSales),
                'maxPurchases' => (float) $this->roundUpAdaptive($maxPurch),
                'maxExpenses' => (float) $this->roundUpAdaptive($maxExp),
            ]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** chart max: round up to a "nice" number */
    private function roundUpAdaptive(float $n): float
    {
        if ($n <= 0) return 0;
        $step = match (true) {
            $n < 10000 => 100,
            $n < 100000 => 10000,
            $n < 1000000 => 100000,
            $n < 5000000 => 500000,
            $n < 20000000 => 1000000,
            default => 5000000,
        };
        return ceil($n / $step) * $step;
    }

    /* =========================================================
     * HR (employee app)
     * ========================================================= */

    /** GET employe_calendar?employeeId=   holidays, overtime, leaves (one row per day) and attendance (m_employee.id) */
    public function employeeCalendar(Request $r)
    {
        $emp = (int) $r->query('employeeId', 0);

        try {
            $rows = [];
            foreach (DB::table('m_holiday')->get(['id', 'holiday_date', 'name']) as $h) {
                $rows[] = ['ID' => $this->str($h->id), 'Date' => substr((string) $h->holiday_date, 0, 10), 'Title' => $h->name, 'Token' => 'Holiday', 'Color' => '#ec4899'];
            }

            foreach (DB::table('t_overtime')->where('employee_id', $emp)->whereNotIn('status', ['rejected', 'cancelled'])->get(['id', 'work_date', 'reason']) as $o) {
                $rows[] = ['ID' => $this->str($o->id), 'Date' => substr((string) $o->work_date, 0, 10), 'Title' => 'OT : ' . $o->reason, 'Token' => 'Overtime', 'Color' => '#3b82f6'];
            }

            $leaves = DB::table('t_leave_request as A')->join('m_leave_type as B', 'B.id', '=', 'A.leave_type_id')
                ->where('A.employee_id', $emp)->whereNotIn('A.status', ['rejected', 'cancelled'])
                ->get(['A.id', 'A.date_from', 'A.date_to', 'B.name']);
            foreach ($leaves as $l) {
                $end = Carbon::parse($l->date_to ?: $l->date_from);
                for ($d = Carbon::parse($l->date_from); $d->lte($end) && count($rows) < 20000; $d->addDay()) {
                    $rows[] = ['ID' => $this->str($l->id), 'Date' => $d->format('Y-m-d'), 'Title' => $l->name, 'Token' => 'Leave', 'Color' => '#8b5cf6'];
                }
            }

            $att = DB::table('t_attendance')->where('employee_id', $emp)->whereNotNull('time_in')->get(['id', 'work_date', 'time_in', 'time_out']);
            foreach ($att as $a) {
                $fmt = fn ($t) => $t ? Carbon::parse($t)->format('h:iA') : '';
                $rows[] = ['ID' => $this->str($a->id), 'Date' => substr((string) $a->work_date, 0, 10), 'Title' => $fmt($a->time_in) . ' - ' . $fmt($a->time_out), 'Token' => 'Present', 'Color' => '#10b981'];
            }

            usort($rows, fn ($a, $b) => strcmp($a['Date'], $b['Date']));
            return response()->json(['result' => 'Success', 'invoices' => $rows]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** GET employee_timeout?employeeId=   today's time-out of the employee ('' when not yet out) */
    public function employeeTimeout(Request $r)
    {
        $out = DB::table('t_attendance')->where('employee_id', (int) $r->query('employeeId', 0))
            ->whereDate('work_date', today())->orderByDesc('id')->value('time_out');

        return response()->json(['result' => 'Success', 'list' => $out ? (string) $out : '']);
    }

    /* =========================================================
     * Reports – the same numbers as the web reports (ReportController), in the old mobile layout
     * ========================================================= */

    /** run a ReportController action as the client user → its JSON as an array */
    private function webReport(string $action, int $userId, array $params): array
    {
        $user = \App\Models\UserClient::find($userId);
        if (!$user) throw new \RuntimeException('User not found');
        return $this->callAs(ReportController::class, $action, $user, $params);
    }

    /** call another controller's action with these inputs (and login) → its JSON as an array */
    private function callAs(string $controller, string $action, ?object $user, array $params, string $method = 'GET'): array
    {
        $sub = Request::create('/', $method, $params);
        $sub->setUserResolver(fn () => $user);
        return app($controller)->{$action}($sub)->getData(true);
    }

    /** readable message from a validation / abort / other error */
    private function failMessage(Throwable $e): string
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) return $e->validator->errors()->first();
        return $e->getMessage() ?: 'Something went wrong.';
    }

    /** GET general_ledger?userId=&from=YYYY-MM&to=YYYY-MM   (declared month) */
    public function generalLedger(Request $r)
    {
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;

        try {
            $gl = $this->webReport('generalLedger', (int) $r->query('userId', 0),
                ['filter_type' => 'DM', 'from' => $from, 'to' => $to, 'branch' => 'current']);
        } catch (Throwable $e) {
            return $this->err($this->failMessage($e));
        }

        $blank = fn ($v) => (float) $v == 0 ? '' : $v;
        $out = [];
        $current = null;
        foreach ($gl['rows'] ?? [] as $row) {
            $code = (string) $row['account_code'];
            if ($code !== $current) {
                $beg = $gl['beginning'][$code] ?? null;
                $out[] = [
                    'ID' => 0,
                    'AccountCode' => $code,
                    'Account_Name' => $row['account_name'],
                    'VendorName' => 'Beginning Balance',
                    'TransType' => $row['trans_type'],
                    'RefData' => $row['ref_data'],
                    'Debit' => $beg ? $blank($beg['debit'] ?? 0) : '',
                    'Credit' => $beg ? $blank($beg['credit'] ?? 0) : '',
                    'TransactionDate' => $from,
                    'Reference' => '',
                    'Jrnl' => '',
                    'Balance' => $beg ? $blank($beg['balance'] ?? 0) : '',
                ];
                $current = $code;
            }
            $name = (string) ($row['description'] ?? '');
            $out[] = [
                'ID' => $row['id'],
                'AccountCode' => $code,
                'Account_Name' => $row['account_name'],
                'VendorName' => strlen($name) > 25 ? substr($name, 0, 22) . '...' : $name,
                'VendorName_Orig' => $name,
                'TransType' => $row['trans_type'],
                'RefData' => $row['ref_data'],
                'Debit' => $blank($row['debit']),
                'Credit' => $blank($row['credit']),
                'TransactionDate' => $row['date'],
                'Reference' => $row['reference'],
                'Jrnl' => $row['jrnl'],
                'Balance' => $blank($row['balance'] ?? 0),
            ];
        }

        return response()->json(['result' => 'Success', 'invoices' => $out]);
    }

    /** GET e_bir?userId=&from=YYYY-MM&to=YYYY-MM&branchType=MAIN   VAT summary as column_1 … column_N rows */
    public function eBir(Request $r)
    {
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;
        $main = strtoupper((string) $r->query('branchType', 'MAIN')) === 'MAIN';

        try {
            $v = $this->webReport('vatSummary', (int) $r->query('userId', 0),
                ['filter_type' => 'DM', 'from' => $from, 'to' => $to, 'branch' => $main ? 'all' : 'current']);
        } catch (Throwable $e) {
            return $this->err($this->failMessage($e));
        }

        $months = $v['months'] ?? [];
        $n = count($months) + 3;                                   // tag, label, months…, total
        $money = fn ($x) => number_format((float) $x, 2);
        $line = function (string $tag, string $label, ?array $row) use ($months, $n, $money) {
            $out = ['column_1' => $tag, 'column_2' => $label];
            $i = 3;
            foreach ($months as $m) $out['column_' . $i++] = $row ? $money($row['values'][$m] ?? 0) : '';
            $out['column_' . $n] = $row ? $money($row['total'] ?? 0) : '';
            return $out;
        };
        $totalOnly = function (string $tag, string $label, float $total) use ($line, $n, $money) {
            $out = $line($tag, $label, null);
            $out['column_' . $n] = $money($total);
            return $out;
        };
        $blank = fn () => array_fill_keys(array_map(fn ($i) => 'column_' . $i, range(1, $n)), '');

        $header = ['column_1' => 'Tag', 'column_2' => 'Summary Data'];
        $i = 3;
        foreach ($months as $m) $header['column_' . $i++] = Carbon::createFromFormat('Y-m-d', "{$m}-01")->format('m-M');
        $header['column_' . $n] = 'Total';

        $rows = [$header];
        foreach (['sales' => 'Sales', 'purchases' => 'Purchases', 'expenses' => 'Expenses'] as $key => $tag) {
            foreach ($v[$key]['rows'] ?? [] as $row) $rows[] = $line($tag, $row['label'], $row);
            $rows[] = $line($tag, '', $v[$key]['total'] ?? null);
            $rows[] = $blank();
        }
        $rows[] = $line('Total Purchases/Expenses', '', $v['purch_exp'] ?? null);
        $rows[] = $blank();
        $rows[] = $line('Net Total', '', $v['net'] ?? null);
        $rows[] = $blank();
        $rows[] = $line('Output VAT', '', $v['output'] ?? null);
        $rows[] = $line('Input VAT', '', $v['input'] ?? null);
        $rows[] = $line('VAT Due', '', $v['due'] ?? null);
        $rows[] = array_merge($blank(), ['column_1' => 'Less : Tax Paid']);

        $less = $v['less'] ?? ['first' => 0, 'second' => 0, 'vt2307' => 0];
        $rows[] = $totalOnly('', '1ST MONTH', (float) $less['first']);
        $rows[] = $totalOnly('', '2ND MONTH', (float) $less['second']);
        $rows[] = $totalOnly('', '2307 (VT)', (float) $less['vt2307']);
        $payable = (float) ($v['due']['total'] ?? 0) - (float) $less['first'] - (float) $less['second'] - (float) $less['vt2307'];
        $rows[] = $totalOnly('TOTAL VAT PAYABLE/EXCESS', '', $payable);

        return response()->json(['result' => 'Success', 'invoices' => $rows]);
    }

    /* =========================================================
     * Login (old login.php) – User1 client · User2 staff · User3 client staff
     * hashed passwords, same checks as the web AuthController; also gives a Sanctum token
     * ========================================================= */

    /** POST login {username, password, loginAttempt} */
    public function login(Request $r)
    {
        $username = trim((string) $r->input('username', ''));
        $password = (string) $r->input('password', '');
        $attempt = (int) $r->input('loginAttempt', 0);
        if ($username === '' || $password === '') return response()->json(['result' => 'NotCorrect']);

        $matches = array_values(array_filter($this->mobileCandidates($username), fn ($c) => $this->passwordMatches($c[0], $password)));
        if (count($matches) > 1) return response()->json(['result' => 'Duplicate']);
        if (!$matches) return response()->json(['result' => $attempt == 4 ? 'Attempt' : 'NotCorrect']);

        [$user, $type] = $matches[0];
        if (($rp = $this->pick($user->getTable(), ['reset_password'])) && (int) $user->$rp === 1) return response()->json(['result' => 'ResetPassword']);

        $acc = null;
        $emp = null;
        if ($type !== 'User2') {
            $acc = DB::table('m_client_account')->where('id', $user->caid)->first();
            $client = $acc ? DB::table('m_client')->where('client_num', $acc->client_num)->first() : null;
            if ($client && ($ca = $this->pick('m_client', ['is_active', 'active'])) && (int) $client->$ca === 0) return response()->json(['result' => 'InActive']);
        }
        if ($type === 'User2') $emp = DB::table('m_employee')->where('com_id', $user->comid)->first();
        if ($type === 'User3') {
            $empId = $this->pick('m_user_client_staff', ['employee_id', 'emp_id']);
            $emp = $empId && $user->$empId ? DB::table('m_employee')->where('id', $user->$empId)->first() : null;
        }

        $designation = (int) ($emp->designation_id ?? 0);
        $position = '';
        if ($designation > 0 && ($col = $this->pick('m_user_type', ['name', 'description', 'user_type']))) {
            $position = (string) DB::table('m_user_type')->where('id', $designation)->value($col);
        }
        $workId = $emp ? ($emp->{$this->pick('m_employee', ['work_id', 'com_id']) ?? 'id'} ?? 0) : 0;
        $month = now('Asia/Manila')->format('Y-m');

        $token = method_exists($user, 'createToken') ? $user->createToken('mobile-' . $type)->plainTextToken : null;

        // every value as text, like the old login.php (the app reads them as String)
        return response()->json(array_map(fn ($v) => $v === null ? '' : (string) $v, [
            'result' => 'Success',
            'token' => $token,
            'tradeName' => $acc->trade_name ?? '',
            'fullName' => $type === 'User2' ? ($this->employeeNameOf($emp->id ?? null) ?: $user->username) : ($this->clientNameOf($user->caid) ?: $user->username),
            'sessionName' => $type === 'User3' ? ($this->employeeNameOf($emp->id ?? null) ?: ($user->fullname ?? '')) : '',
            'branchCode' => $acc->branch_code ?? '',
            'userId' => $type === 'User3' ? (int) $user->user_client_id : (int) $user->id,
            'loginId' => (int) $user->id,
            'comId' => $type === 'User2' ? $user->comid : (int) $user->caid,
            'fromDate' => $month,
            'toDate' => $month,
            'branchType' => $acc->branch_type ?? '',
            'clientNum' => $acc->client_num ?? '',
            'TIN' => $acc->tin ?? '',
            'userCount' => $type,
            'employeeId' => (int) ($emp->id ?? 0),
            'CAID' => $type === 'User2' ? $user->comid : (int) $user->caid,      // old: staff CAID = ComID
            'workId' => $workId,
            'userTypeId' => $type === 'User2' ? (int) ($user->user_type_id ?? 0) : $designation,
            'designationId' => $designation,
            'position' => $position ?: ($type === 'User2' ? 'Staff' : ($type === 'User3' ? 'Client Staff' : '')),
        ]));
    }

    /** active logins with this username that may use the mobile app */
    private function mobileCandidates(string $username): array
    {
        $list = [];
        $flag = fn ($q, string $table, string $col) => $this->pick($table, [$col]) ? $q->where($col, 1) : $q;

        $client = $flag(\App\Models\UserClient::query()->where('username', $username)->where('is_active', 1), 'm_user_client', 'is_mobile')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('m_client_account as B')->whereColumn('B.id', 'm_user_client.caid'))
            ->first();
        if ($client) $list[] = [$client, 'User1'];

        $staff = \App\Models\UserStaff::query()->where('username', $username)->where('is_active', 1)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('m_employee as B')->whereColumn('B.com_id', 'm_user_staff.comid'))
            ->first();
        if ($staff) $list[] = [$staff, 'User2'];

        $cs = $flag(\App\Models\UserClientStaff::query()->where('username', $username)->where('is_active', 1), 'm_user_client_staff', 'is_scan_suite')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('m_client_account as B')->whereColumn('B.id', 'm_user_client_staff.caid'))
            ->first();
        if ($cs) $list[] = [$cs, 'User3'];

        return $list;
    }

    /** hashed passwords only (same as the web login); old hashes are upgraded */
    private function passwordMatches($user, string $plain): bool
    {
        $stored = (string) $user->getRawOriginal('password');
        if ($stored === '' || (!str_starts_with($stored, '$2y$') && !str_starts_with($stored, '$argon'))) return false;
        try {
            if (!\Illuminate\Support\Facades\Hash::check($plain, $stored)) return false;
        } catch (Throwable $e) {
            return false;
        }
        if (\Illuminate\Support\Facades\Hash::needsRehash($stored)) {
            $user->password = $plain;
            $user->saveQuietly();
        }
        return true;
    }

    /* =========================================================
     * Payment lists
     * ========================================================= */

    /** GET payment_type_list */
    public function paymentTypeList()
    {
        $list = DB::table('m_payment_type')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($p) => ['PayTypeID' => $this->str($p->id), 'PaymentType' => $p->name]);
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET payment_col_type   active collection types */
    public function paymentColType()
    {
        $active = $this->pick('m_payment_col_type', ['is_active']);
        $list = DB::table('m_payment_col_type')->when($active, fn ($q) => $q->where($active, 1))->orderBy('id')->get()
            ->map(fn ($c) => ['PaymentColTypeID' => $this->str($c->id), 'PaymentColType' => $c->name, 'IsActive' => $this->str($active ? $c->$active : 1)]);
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET payment_receipt_type */
    public function paymentReceiptType()
    {
        $list = DB::table('m_payment_receipt_type')->orderBy('id')->get(['id', 'name'])
            ->map(fn ($p) => ['ReceiptTypeID' => $this->str($p->id), 'ReceiptType' => $p->name]);
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET payment_list?TransNumber=   payments of one SOA */
    public function paymentList(Request $r)
    {
        $list = DB::table('t_payment as A')
            ->join('m_payment_type as B', 'B.id', '=', 'A.payment_type_id')
            ->leftJoin('m_payment_cheque_type as C', 'C.id', '=', 'A.cheque_type_id')
            ->where('A.trans_number', (string) $r->query('TransNumber', ''))
            ->orderBy('A.id')
            ->get(['A.*', 'B.name as payment_type_name', 'C.id as cheque_id', 'C.name as cheque_name'])
            ->map(fn ($p) => [
                'PaymentID' => $this->str($p->id),
                'CAID' => $this->str($p->ca_id),
                'PaymentDate' => $p->payment_date ? substr((string) $p->payment_date, 0, 10) : null,
                'PaymentTypeID' => $this->str($p->payment_type_id),
                'PaymentType' => $p->payment_type_name,
                'TotalGrossAmountCal' => $this->str($p->total_gross_amount_cal ?? 0),
                'PaymentColType' => $this->str($p->payment_col_type_id ?? null),
                'ReceiptTypeID' => $this->str($p->receipt_type_id ?? null),
                'ARNumber' => $p->ar_number ?? null,
                'APNumber' => $p->ap_number ?? null,
                'ORNumber' => $p->or_number ?? null,
                'ChequeTypeID' => $this->str($p->cheque_id ?? 0),
                'ChequeType' => (string) ($p->cheque_name ?? ''),
                'ChequeTypeDate' => $p->cheque_type_date ? substr((string) $p->cheque_type_date, 0, 10) : null,
                'BankDigitalWallet' => (string) ($p->details1 ?? ''),
                'ReferenceChequeNo' => (string) ($p->details2 ?? ''),
                'Amount' => $this->str($p->amount ?? 0),
            ]);
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /* =========================================================
     * Purchase journal (same rows as the web Purchases Journal)
     * ========================================================= */

    /** GET purchase_receipt_journal?userId=&from=YYYY-MM&to=YYYY-MM */
    public function purchaseReceiptJournal(Request $r)
    {
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;
        try {
            $pj = $this->webReport('purchasesJournal', (int) $r->query('userId', 0), ['filter_type' => 'DM', 'from' => $from, 'to' => $to, 'branch' => 'current']);
        } catch (Throwable $e) {
            return $this->err($this->failMessage($e));
        }

        $rows = array_map(fn ($x) => [
            'seq' => (string) $x['invoice_no'],
            'TransactionDate' => (string) $x['date'],
            'APInvoiceNo' => (string) $x['invoice_no'],
            'AccountID' => $x['account_id'],
            'DocumentNo' => (string) $x['tran'],
            'Name' => (string) $x['name'],
            'LineDescription' => (string) $x['description'],
            'Debit' => $x['debit'],
            'Credit' => $x['credit'],
        ], $pj['rows'] ?? []);

        $blank = array_fill_keys(['seq', 'TransactionDate', 'APInvoiceNo', 'AccountID', 'DocumentNo', 'Name', 'LineDescription', 'Debit', 'Credit'], '');
        $out = [];
        $n = count($rows);
        foreach ($rows as $i => $row) {
            $out[] = $row;
            if ($i === $n - 1 || $row['APInvoiceNo'] !== $rows[$i + 1]['APInvoiceNo']) $out[] = $blank;
        }
        return response()->json(['result' => 'Success', 'invoices' => $out]);
    }

    /* =========================================================
     * HR: leave (t_leave_request) + overtime (t_overtime) – saved straight to the tables
     * ========================================================= */

    /** old LeaveDurationID → t_leave_request.is_half_day / half_day_part */
    const LEAVE_DURATIONS = [
        1 => ['Whole Day', 0, null],
        2 => ['Half Day (AM)', 1, 'AM'],
        3 => ['Half Day (PM)', 1, 'PM'],
    ];

    /** GET leave_type_list */
    public function leaveTypeList()
    {
        $list = DB::table('m_leave_type')->where('is_active', 1)->orderBy('id')->get()
            ->map(fn ($t) => [
                'LeaveTypeID' => $this->str($t->id), 'LeaveTypeName' => $t->name, 'LeaveTypeCode' => $t->code,
                'IsPaid' => $this->str((int) $t->is_paid), 'DefaultDays' => $this->str($t->default_days),
            ]);
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET leave_duration   whole day / half day AM / half day PM */
    public function leaveDuration()
    {
        $list = collect(self::LEAVE_DURATIONS)->map(fn ($d, $id) => [
            'LeaveDurationID' => (string) $id, 'LeaveDuration' => $d[0], 'IsHalfDay' => (string) $d[1], 'HalfDayPart' => $d[2],
        ])->values();
        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** POST leave_registration {LeaveID?, EmployeeID, LeaveTypeID, LeaveDurationID, StartDate, EndDate, Reason, TotalDays, CreatedBy} */
    public function leaveRegistration(Request $r)
    {
        $leaveId = (int) $r->input('LeaveID', 0);
        $emp = (int) $r->input('EmployeeID', 0);
        $type = (int) $r->input('LeaveTypeID', 0);
        $from = trim((string) $r->input('StartDate', ''));
        $to = trim((string) $r->input('EndDate', ''));
        if ($emp <= 0 || $type <= 0 || $from === '' || $to === '') return $this->err('Missing required fields');

        [, $half, $part] = self::LEAVE_DURATIONS[(int) $r->input('LeaveDurationID', 1)] ?? self::LEAVE_DURATIONS[1];
        $days = (float) $r->input('TotalDays', 0);
        if ($days <= 0) $days = $half ? 0.5 : Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        // overlapping leave (rejected / cancelled ones don't count)
        $overlap = DB::table('t_leave_request')->where('employee_id', $emp)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->where('date_from', '<=', $to)->where('date_to', '>=', $from)
            ->when($leaveId > 0, fn ($q) => $q->where('id', '!=', $leaveId))
            ->exists();
        if ($overlap) return $this->err('A leave record already exists for the selected date range.');

        $row = [
            'leave_type_id' => $type, 'date_from' => $from, 'date_to' => $to, 'days' => $days,
            'is_half_day' => $half, 'half_day_part' => $part,
            'reason' => trim((string) $r->input('Reason', '')),
            'updated_at' => now(),
        ];

        try {
            if ($leaveId <= 0) {
                $id = DB::table('t_leave_request')->insertGetId($this->only('t_leave_request', $row + [
                    'employee_id' => $emp, 'status' => 'pending',
                    'created_by' => (int) $r->input('CreatedBy', $emp) ?: $emp, 'created_at' => now(),
                ]));
                return response()->json(['result' => 'Success', 'message' => 'Leave request submitted successfully.', 'LeaveID' => $id]);
            }

            $old = DB::table('t_leave_request')->where('id', $leaveId)->where('employee_id', $emp)->first();
            if (!$old) return $this->err('No changes made or record not found.');
            if ($old->status !== 'pending') return $this->err("This leave is already {$old->status}.");

            DB::table('t_leave_request')->where('id', $leaveId)->update($this->only('t_leave_request', $row));
            return response()->json(['result' => 'Success', 'message' => 'Leave request updated successfully.']);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /**
     * POST overtime_registration {OTSummaryID?, EmpID, OTDate, OTStart, OTEnd, Remarks}
     * on a work day the start is moved to the shift's time-out (like the old script); rest day / holiday decides the pay rate
     */
    public function overtimeRegistration(Request $r)
    {
        $otId = (int) $r->input('OTSummaryID', 0);
        $emp = (int) $r->input('EmpID', 0);
        $date = trim((string) $r->input('OTDate', ''));
        $startIn = trim((string) $r->input('OTStart', ''));
        $endIn = trim((string) $r->input('OTEnd', ''));
        if ($emp <= 0 || $date === '' || $startIn === '' || $endIn === '') return $this->err('Missing required fields.');

        $employee = DB::table('m_employee')->where('id', $emp)->first();
        if (!$employee) return $this->err('Employee not found.');

        // that day's attendance is locked once paid
        $att = DB::table('t_attendance')->where('employee_id', $emp)->whereDate('work_date', $date)->first();
        if ($att && !empty($att->payroll_run_id)) return $this->err('This date is already paid in payroll. Please contact HR Management.');

        // shift: the employee's own or the default one (m_shift)
        $shift = (!empty($employee->shift_id) ? DB::table('m_shift')->where('id', $employee->shift_id)->first() : null)
            ?? DB::table('m_shift')->where('is_default', 1)->where('is_active', 1)->first();
        $shiftOut = $att->sched_time_out ?? null;
        $shiftOutStr = $shiftOut ? Carbon::parse($shiftOut)->format('H:i') : ($shift ? substr((string) $shift->time_out, 0, 5) : '');
        if ($shiftOutStr === '') return $this->err('Could not retrieve employee shift time-out.');

        $workDays = $shift ? array_map('intval', array_filter(explode(',', (string) $shift->work_days))) : [];
        $isWorkDay = !$workDays || in_array((int) Carbon::parse($date)->format('N'), $workDays, true);   // 1 = Mon … 7 = Sun
        $holiday = DB::table('m_holiday')->whereDate('holiday_date', $date)->value('holiday_type');

        $shiftAt = Carbon::parse("{$date} {$shiftOutStr}:00");
        $start = Carbon::parse($startIn);
        $end = Carbon::parse($endIn);
        if ($isWorkDay && !$holiday && $start->lt($shiftAt)) $start = $shiftAt;
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        if ($seconds <= 0) return $this->err("End time must be after the shift time-out ({$shiftOutStr}).");
        $minutes = (int) floor($seconds / 60);

        $code = match (true) {
            $holiday === 'regular' => 'RH_OT',
            in_array($holiday, ['special', 'special_working'], true) => 'SH_OT',
            !$isWorkDay => 'RD_OT',
            default => 'REG_OT',
        };
        if (!DB::table('m_pay_rate')->where('code', $code)->exists()) $code = null;

        $dup = DB::table('t_overtime')->where('employee_id', $emp)->whereDate('work_date', $date)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->when($otId > 0, fn ($q) => $q->where('id', '!=', $otId))->exists();
        if ($dup) return $this->err('An overtime record already exists for the selected date.');

        $row = [
            'work_date' => $date,
            'time_start' => $start->format('Y-m-d H:i:s'),
            'time_end' => $end->format('Y-m-d H:i:s'),
            'minutes' => $minutes,
            'pay_rate_code' => $code,
            'reason' => trim((string) $r->input('Remarks', '')),
            'updated_at' => now(),
        ];
        $result = [
            'ShiftID' => $shift->id ?? null, 'Shift' => $shift->name ?? '', 'ShiftCode' => $shift->code ?? '',
            'PayRateCode' => $code, 'OTStart' => $row['time_start'], 'OTEnd' => $row['time_end'],
            'OTH' => round($seconds / 3600, 4), 'cOT' => $minutes,
        ];

        try {
            if ($otId <= 0) {
                $id = DB::table('t_overtime')->insertGetId($this->only('t_overtime', $row + [
                    'employee_id' => $emp, 'status' => 'pending', 'created_by' => $emp, 'created_at' => now(),
                ]));
                return response()->json(['result' => 'Success', 'message' => 'Overtime submitted successfully.', 'OTSummaryID' => $id] + $result);
            }

            $old = DB::table('t_overtime')->where('id', $otId)->where('employee_id', $emp)->first();
            if (!$old) return $this->err('No changes made or record not found.');
            if ($old->status !== 'pending') return $this->err("This overtime is already {$old->status}.");

            DB::table('t_overtime')->where('id', $otId)->update($this->only('t_overtime', $row));
            return response()->json(['result' => 'Success', 'message' => 'Overtime updated successfully.'] + $result);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Group chat (t_chat_group*)
     * ========================================================= */

    private function chatUsername($uid, string $type): string
    {
        $name = match ($type) {
            'User1' => DB::table('m_user_client')->where('id', $uid)->value('username'),
            'User2' => DB::table('m_user_staff')->where('id', $uid)->value('username'),
            'User3' => DB::table('m_user_client_staff')->where('user_client_id', $uid)->value('username'),
            default => null,
        };
        return $name ?: 'Unknown';
    }

    /** POST group_create {groupName, createdBy, createdByType, members[{userId, userType}]} */
    public function groupCreate(Request $r)
    {
        $name = trim((string) $r->input('groupName', ''));
        $by = trim((string) $r->input('createdBy', ''));
        $byType = trim((string) $r->input('createdByType', ''));
        $members = (array) $r->input('members', []);
        if ($name === '' || $by === '' || !$members) return $this->err('Missing fields');

        try {
            $groupId = DB::transaction(function () use ($name, $by, $byType, $members) {
                $groupId = DB::table('t_chat_group')->insertGetId([
                    'group_name' => $name, 'created_by' => $by, 'created_by_type' => $byType, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $rows = [['group_id' => $groupId, 'user_id' => $by, 'user_type' => $byType, 'is_admin' => 1, 'added_by' => $by, 'joined_at' => now()]];
                foreach ($members as $m) {
                    if (($m['userId'] ?? '') === '' || ($m['userType'] ?? '') === '') continue;
                    $rows[] = ['group_id' => $groupId, 'user_id' => (string) $m['userId'], 'user_type' => (string) $m['userType'], 'is_admin' => 0, 'added_by' => $by, 'joined_at' => now()];
                }
                DB::table('t_chat_group_member')->insertOrIgnore($rows);
                return $groupId;
            });
            return response()->json(['result' => 'Success', 'groupId' => $groupId]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST group_add_members {groupId, addedBy, members[{userId, userType}]} */
    public function groupAddMembers(Request $r)
    {
        $groupId = (int) $r->input('groupId', 0);
        $by = (string) $r->input('addedBy', '');
        $members = (array) $r->input('members', []);
        if (!$groupId || $by === '' || !$members) return $this->err('Missing fields');

        try {
            $rows = [];
            foreach ($members as $m) {
                if (($m['userId'] ?? '') === '' || ($m['userType'] ?? '') === '') continue;
                $rows[] = ['group_id' => $groupId, 'user_id' => (string) $m['userId'], 'user_type' => (string) $m['userType'], 'is_admin' => 0, 'added_by' => $by, 'joined_at' => now()];
            }
            DB::table('t_chat_group_member')->insertOrIgnore($rows);
            return response()->json(['result' => 'Success']);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST group_leave {groupId, userId, userType} */
    public function groupLeave(Request $r)
    {
        $groupId = (int) $r->input('groupId', 0);
        $uid = trim((string) $r->input('userId', ''));
        $type = trim((string) $r->input('userType', ''));
        if (!$groupId || $uid === '' || $type === '') return $this->err('Missing fields');

        DB::table('t_chat_group_member')->where('group_id', $groupId)->where('user_id', $uid)->where('user_type', $type)->delete();
        return response()->json(['result' => 'Success']);
    }

    /** POST group_get_list {userId, userCount}   my groups with last message + unread count */
    public function groupGetList(Request $r)
    {
        $uid = trim((string) $r->input('userId', ''));
        $type = trim((string) $r->input('userCount', ''));
        if ($uid === '' || $type === '') return $this->err('Missing fields');

        try {
            $rows = DB::select("
                SELECT g.id, g.group_name, g.created_by, g.created_by_type, g.created_at,
                    (SELECT m.message     FROM t_chat_group_message m WHERE m.group_id = g.id ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_message,
                    (SELECT m.sender_name FROM t_chat_group_message m WHERE m.group_id = g.id ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_sender_name,
                    (SELECT m.sender_id   FROM t_chat_group_message m WHERE m.group_id = g.id ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_sender_id,
                    (SELECT m.sent_at     FROM t_chat_group_message m WHERE m.group_id = g.id ORDER BY m.sent_at DESC, m.id DESC LIMIT 1) AS last_message_time,
                    (SELECT COUNT(*) FROM t_chat_group_message m
                      WHERE m.group_id = g.id AND NOT (m.sender_id = ? AND m.sender_type = ?)
                        AND m.id NOT IN (SELECT rd.message_id FROM t_chat_group_read rd WHERE rd.user_id = ? AND rd.user_type = ?)
                    ) AS unread_count
                FROM t_chat_group g
                INNER JOIN t_chat_group_member mem ON mem.group_id = g.id AND mem.user_id = ? AND mem.user_type = ?
                ORDER BY COALESCE(last_message_time, g.created_at) DESC
            ", [$uid, $type, $uid, $type, $uid, $type]);

            $groups = array_map(fn ($g) => [
                'groupId' => $g->id,
                'groupName' => $g->group_name,
                'createdBy' => $g->created_by,
                'createdByType' => $g->created_by_type,
                'lastMessage' => $g->last_message ?? '',
                'lastSenderName' => $g->last_sender_name ?? '',
                'lastSenderId' => $g->last_sender_id ?? '',
                'lastMessageTime' => $g->last_message_time ?? $g->created_at,
                'unreadCount' => (int) $g->unread_count,
            ], $rows);

            return response()->json(['result' => 'Success', 'groups' => $groups]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST group_get_members {groupId} */
    public function groupGetMembers(Request $r)
    {
        $groupId = (int) $r->input('groupId', 0);
        if (!$groupId) return $this->err('Missing groupId');

        try {
            $all = DB::table('t_chat_group_member')->where('group_id', $groupId)->orderByDesc('is_admin')->orderBy('joined_at')->get();
            $members = $all->map(function ($m) use ($all) {
                $adder = $all->first(fn ($x) => (string) $x->user_id === (string) $m->added_by);
                return [
                    'userId' => $m->user_id,
                    'userType' => $m->user_type,
                    'fullName' => $this->chatFullName($m->user_id, $m->user_type),
                    'username' => $this->chatUsername($m->user_id, $m->user_type),
                    'joinedAt' => $m->joined_at,
                    'isAdmin' => (int) $m->is_admin === 1,
                    'addedBy' => $m->added_by,
                    'addedByName' => $adder ? $this->chatFullName($adder->user_id, $adder->user_type) : null,
                ];
            })->values();

            return response()->json(['result' => 'Success', 'members' => $members]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST group_get_available_users {groupId}   everybody not yet in the group */
    public function groupGetAvailableUsers(Request $r)
    {
        $groupId = (int) $r->input('groupId', 0);
        if (!$groupId) return $this->err('Missing groupId');

        try {
            $in = DB::table('t_chat_group_member')->where('group_id', $groupId)->get(['user_id', 'user_type'])
                ->map(fn ($m) => "{$m->user_type}|{$m->user_id}")->flip();

            $users = collect();
            DB::table('m_user_staff as S')->join('m_employee as E', 'E.com_id', '=', 'S.comid')->where('S.is_active', 1)
                ->get(['S.id', 'S.username', DB::raw("TRIM(CONCAT_WS(' ', E.last_name, E.first_name, E.middle_name)) AS full_name")])
                ->each(fn ($u) => $users->push(['userId' => (string) $u->id, 'userType' => 'User2', 'fullName' => $u->full_name ?: $u->username, 'username' => $u->username]));
            DB::table('m_user_client')->where('is_active', 1)->get(['id', 'caid', 'username'])
                ->each(fn ($u) => $users->push(['userId' => (string) $u->id, 'userType' => 'User1', 'fullName' => $this->clientNameOf($u->caid) ?: $u->username, 'username' => $u->username]));
            DB::table('m_user_client_staff')->where('is_active', 1)->get(['user_client_id', 'caid', 'username'])
                ->each(fn ($u) => $users->push(['userId' => (string) $u->user_client_id, 'userType' => 'User3', 'fullName' => $this->clientNameOf($u->caid) ?: $u->username, 'username' => $u->username]));

            $users = $users->reject(fn ($u) => isset($in["{$u['userType']}|{$u['userId']}"]))
                ->unique(fn ($u) => "{$u['userType']}|{$u['userId']}")->values();

            return response()->json(['result' => 'Success', 'users' => $users]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST group_get_messages {groupId, userId, userType}   all messages; marks them read for me */
    public function groupGetMessages(Request $r)
    {
        $groupId = (int) $r->input('groupId', 0);
        $uid = trim((string) $r->input('userId', ''));
        $type = trim((string) $r->input('userType', ''));
        if (!$groupId || $uid === '') return $this->err('Missing fields');

        try {
            $rows = DB::table('t_chat_group_message')->where('group_id', $groupId)->orderBy('sent_at')->orderBy('id')->get();
            $messages = $rows->map(fn ($m) => [
                'messageId' => $m->id,
                'senderId' => $m->sender_id,
                'senderType' => $m->sender_type,
                'senderName' => $m->sender_name,
                'content' => $m->message ?? '',
                'attachmentUrl' => $m->attachment,
                'attachmentName' => $m->attachment_name,
                'attachmentType' => $m->attachment_type,
                'timestamp' => $m->sent_at,
                'isRead' => true,
            ])->values();

            if ($rows->isNotEmpty()) {
                DB::table('t_chat_group_read')->insertOrIgnore($rows->map(fn ($m) => [
                    'group_id' => $groupId, 'message_id' => $m->id, 'user_id' => $uid, 'user_type' => $type, 'read_at' => now(),
                ])->all());
            }

            return response()->json(['result' => 'Success', 'messages' => $messages]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /** POST group_send_message {groupId, senderId, senderType, senderName, message, attachment (base64), attachmentName, attachmentType} */
    public function groupSendMessage(Request $r)
    {
        $groupId = (int) $r->input('groupId', 0);
        $sender = trim((string) $r->input('senderId', ''));
        $type = trim((string) $r->input('senderType', ''));
        $b64 = $r->input('attachment');
        $attName = trim((string) $r->input('attachmentName', ''));
        if (!$groupId || $sender === '') return $this->err('Missing fields');
        if (!DB::table('t_chat_group_member')->where('group_id', $groupId)->where('user_id', $sender)->where('user_type', $type)->exists()) {
            return $this->err('You are not a member of this group');
        }

        try {
            $url = null;
            if (!empty($b64)) {
                $decoded = base64_decode((string) $b64, true);
                if ($decoded === false) return $this->err('Invalid attachment');
                $fileName = uniqid('grp_', true) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($attName));
                $dir = public_path('uploads/chat');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (file_put_contents($dir . DIRECTORY_SEPARATOR . $fileName, $decoded) === false) return $this->err('Failed to save attachment');
                $url = asset('uploads/chat/' . $fileName);
            }

            $id = DB::table('t_chat_group_message')->insertGetId([
                'group_id' => $groupId, 'sender_id' => $sender, 'sender_type' => $type,
                'sender_name' => trim((string) $r->input('senderName', '')) ?: $this->chatFullName($sender, $type),
                'message' => trim((string) $r->input('message', '')),
                'attachment' => $url, 'attachment_name' => $attName ?: null,
                'attachment_type' => trim((string) $r->input('attachmentType', '')) ?: null,
                'sent_at' => now(),
            ]);
            DB::table('t_chat_group_read')->insertOrIgnore([
                'group_id' => $groupId, 'message_id' => $id, 'user_id' => $sender, 'user_type' => $type, 'read_at' => now(),
            ]);

            return response()->json(['result' => 'Success', 'messageId' => $id, 'timestamp' => now()->format('Y-m-d H:i:s'), 'attachmentUrl' => $url]);
        } catch (Throwable $e) {
            return $this->err($e->getMessage());
        }
    }

    /* =========================================================
     * Scans by type (sales.php / purchases.php / expenses.php)
     * ========================================================= */

    /** GET sales?userId=&from=&to= */
    public function sales(Request $r)
    {
        return $this->scanList($r, 'sales');
    }

    /** GET purchases?userId=&from=&to= */
    public function purchases(Request $r)
    {
        return $this->scanList($r, 'purchases');
    }

    /* =========================================================
     * Upload receipt (scan) / document
     * ========================================================= */

    /**
     * POST upload_receipt (multipart) {userId, employeeId, caid, type = sales|purchases|expenses, accountCode, pdf}
     * file → S3, row in t_mobile_transaction, notification to the assigned DA (t_notification + Pusher when set up)
     */
    public function uploadReceipt(Request $r)
    {
        $userId = (int) $r->input('userId', 0);
        $employeeId = (int) $r->input('employeeId', 0);
        $type = (string) $r->input('type', '');
        $file = $r->file('pdf');

        $fail = function (string $m) use ($r) {
            // written to storage/logs/laravel.log so the reason can be seen on the server
            \Illuminate\Support\Facades\Log::warning('upload_receipt failed: ' . $m, [
                'userId' => $r->input('userId'), 'type' => $r->input('type'),
                'files' => array_keys($r->allFiles()),
            ]);
            return response()->json(['success' => false, 'message' => $m], 500);
        };
        if (!$userId) return $fail('User ID is required');
        $folders = ['sales' => ['S', 'Sales_Invoices', '(SALES)'], 'purchases' => ['P', 'Vendor_Invoices', '(PURCHASES)'], 'expenses' => ['E', 'Expenses_Transaction', '(EXPENSES)']];
        if (!isset($folders[$type])) return $fail('Invalid transaction type');
        if (!$file || !$file->isValid()) return $fail('PDF file upload failed');
        if ($file->getSize() == 0) return $fail('Uploaded PDF file is empty');
        [$ext, $folder, $label] = $folders[$type];

        // the user's account (r caid wins, like the old script)
        $caid = (int) $r->input('caid', 0) ?: (int) DB::table('m_user_client')->where('id', $userId)->value('caid');
        $acc = $caid ? DB::table('m_client_account')->where('id', $caid)->first() : null;
        $daid = (int) ($acc->assigned_daid ?? 0);
        $auCol = $this->pick('m_client_account', ['assigned_auid', 'assigned_auditor_id']);
        $auid = $auCol ? (int) ($acc->$auCol ?? 0) : 0;

        $stamp = date('Ymdhis');
        $fileExt = $file->getClientOriginalExtension() ?: 'pdf';
        $s3Name = "{$userId}-{$ext}{$stamp}.{$fileExt}";
        $dbName = "{$ext}{$stamp}.{$fileExt}";

        try {
            $url = $this->putS3(null, "{$folder}/{$s3Name}", 'application/pdf', $this->fileBody($file));
            if (!$url) return $fail('S3 upload failed');

            [$id, $notifId, $message] = DB::transaction(function () use ($r, $dbName, $type, $userId, $caid, $daid, $auid, $employeeId, $acc, $label) {
                $id = DB::table('t_mobile_transaction')->insertGetId($this->only('t_mobile_transaction', [
                    'filename' => $dbName, 'trans_type' => $type, 'added_by' => $userId,
                    'account_code' => (string) $r->input('accountCode', ''), 'reference' => '',
                    'assigned_daid' => $daid, 'assigned_auid' => $auid, 'assigned_auditor_id' => $auid,
                    'employee_id' => $employeeId, 'trans_status' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]));

                $message = trim(($acc->branch_type ?? '') . ' ' . ($acc->branch_code ?? '') . ': ' . ($acc->trade_name ?? '') . ' ' . $label);
                $notifId = null;
                if (Schema::hasTable('t_notification')) {
                    $notifId = DB::table('t_notification')->insertGetId([
                        'ca_id' => $caid, 'from_emp_id' => $employeeId, 'to_emp_id' => $daid,
                        'message' => $message, 'is_read' => 0, 'module' => 'Scan', 'reference' => '',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                return [$id, $notifId, $message];
            });

            $this->pushNotification($daid, 'scan', $message, (int) $notifId);

            return response()->json([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'filename' => $s3Name,
                's3_url' => $url,
                'transaction_id' => $id,
                'file_size' => $file->getSize(),
            ]);
        } catch (Throwable $e) {
            report($e);                               // full error in storage/logs/laravel.log
            return $fail($this->s3Reason($e));
        }
    }

    /** POST upload_document (multipart) {comId, id, file = image}   → S3 Documents/<comId>-<id>.webp */
    public function uploadDocument(Request $r)
    {
        $fail = fn (string $m) => response()->json(['success' => false, 'error' => $m], 400);
        $file = $r->file('file');
        $comId = (string) $r->input('comId', '');
        $id = (string) $r->input('id', '');

        if (!$file || !$file->isValid()) return $fail('No file uploaded or upload error');
        if ($comId === '' || $id === '') return $fail('Missing comId or id');
        if ($file->getSize() > 10 * 1024 * 1024) return $fail('File too large. Maximum size is 10MB.');

        $info = @getimagesize($this->tmpPath($file));
        if ($info === false) return $fail('Invalid image file');
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($this->tmpPath($file)),
            IMAGETYPE_PNG => @imagecreatefrompng($this->tmpPath($file)),
            IMAGETYPE_GIF => @imagecreatefromgif($this->tmpPath($file)),
            IMAGETYPE_WEBP => @imagecreatefromwebp($this->tmpPath($file)),
            default => null,
        };
        if ($image === null) return $fail('Unsupported image format');
        if ($image === false) return $fail('Failed to process image');

        ob_start();
        imagewebp($image, null, 80);
        $data = ob_get_clean();
        imagedestroy($image);
        if (!$data) return $fail('Failed to save image');

        // safe file name: only letters, digits, - and _
        $clean = fn ($v) => preg_replace('/[^A-Za-z0-9_-]/', '', $v);
        $filename = $clean($comId) . '-' . $clean($id) . '.webp';

        try {
            $url = $this->putS3(null, "Documents/{$filename}", 'image/webp', $data, 'public-read');
            if (!$url) return $fail('S3 upload failed');
        } catch (Throwable $e) {
            return $fail($e->getMessage());
        }

        return response()->json(['success' => true, 'message' => 'Image uploaded successfully', 'filename' => $filename, 's3_url' => $url, 'path' => "Documents/{$filename}"]);
    }

    /** S3 putObject from a file path or raw body → ObjectURL */
    /**
     * path of an uploaded temp file. getRealPath() is empty on IIS when the web user can't list the temp folder,
     * so fall back to the plain temp path PHP gave us.
     */
    private function tmpPath(\Illuminate\Http\UploadedFile $file): string
    {
        return (string) ($file->getRealPath() ?: $file->getPathname());
    }

    /** short, readable reason for an S3 / network failure (full error still goes to laravel.log) */
    private function s3Reason(Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e instanceof \Aws\Exception\AwsException) {
            $code = (string) $e->getAwsErrorCode();
            $aws = (string) $e->getAwsErrorMessage();
            $hint = match ($code) {
                'InvalidAccessKeyId', 'SignatureDoesNotMatch' => 'AWS key / secret in .env is wrong',
                'AccessDenied' => 'these AWS keys may not write to the bucket (needs s3:PutObject)',
                'PermanentRedirect', 'AuthorizationHeaderMalformed' => 'wrong AWS region in .env (should be ap-southeast-1)',
                'NoSuchBucket' => 'the bucket name in .env is wrong',
                'AccessControlListNotSupported' => 'the bucket does not allow ACLs',
                default => '',
            };
            if ($code !== '') return "S3 {$code}: {$aws}" . ($hint ? " ({$hint})" : '');
        }
        if (preg_match('/cURL error (\d+)/', $msg, $m)) {
            return $m[1] === '60'
                ? 'S3 SSL error (cURL 60): set curl.cainfo / openssl.cafile to cacert.pem in the server php.ini, then restart IIS'
                : "S3 network error (cURL {$m[1]}): the server could not reach Amazon S3";
        }
        return mb_substr($msg, 0, 300);
    }

    /** contents of an uploaded file (works even when its path can't be opened) */
    private function fileBody(\Illuminate\Http\UploadedFile $file): string
    {
        $path = $this->tmpPath($file);
        $data = $path !== '' ? @file_get_contents($path) : false;
        if ($data === false || $data === '') $data = $file->get();     // last try through Laravel
        if ($data === false || $data === '' || $data === null) {
            throw new \RuntimeException('The server could not read the uploaded file (check upload_tmp_dir permissions in php.ini).');
        }
        return $data;
    }

    private function putS3(?string $path, string $key, string $contentType, ?string $body = null, ?string $acl = null): ?string
    {
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region') ?: 'ap-southeast-1',
            'credentials' => ['key' => config('filesystems.disks.s3.key'), 'secret' => config('filesystems.disks.s3.secret')],
            'suppress_php_deprecation_warning' => true,
        ]);
        $args = ['Bucket' => config('filesystems.disks.s3.bucket') ?: 'igb-fems-files-singapore', 'Key' => $key, 'ContentType' => $contentType];
        $body !== null ? $args['Body'] = $body : $args['SourceFile'] = $path;
        if ($acl) $args['ACL'] = $acl;
        $res = $s3->putObject($args);
        return $res['ObjectURL'] ?? null;
    }

    /** Pusher message to the web (only when PUSHER_* is set in .env); never breaks the upload */
    private function pushNotification(int $employeeId, string $action, string $message, int $lastId = 0): void
    {
        $cfg = config('broadcasting.connections.pusher');
        if (empty($cfg['key']) || empty($cfg['secret']) || empty($cfg['app_id']) || !class_exists(\Pusher\Pusher::class)) return;
        try {
            (new \Pusher\Pusher($cfg['key'], $cfg['secret'], $cfg['app_id'], $cfg['options'] ?? []))
                ->trigger(config('services.mobile_push.channel', 'my-channel'), config('services.mobile_push.event', 'my-event'), [
                    'employee_id' => $employeeId, 'action' => $action, 'message' => $message, 'last_id' => $lastId,
                ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /* =========================================================
     * Billing: services + service periods of a client account
     * ========================================================= */

    /** GET service_list?TransNumber=   services on one SOA (old qry_service_v2) */
    public function serviceList(Request $r)
    {
        $paid = DB::table('t_payment')->whereNotNull('service_id')->groupBy('service_id')->selectRaw('service_id, SUM(amount) AS paid');
        $net = "(COALESCE(S.total_service_fee,0) + COALESCE(S.inherited_vat,0) - COALESCE(S.inherited_wh,0) - COALESCE(S.inherited_discount_amount,0))";

        $list = DB::table('t_service as S')
            ->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->leftJoin('m_invoice_status as I', 'I.id', '=', 'S.invoice_status_id')
            ->leftJoinSub($paid, 'PD', 'PD.service_id', '=', 'S.id')
            ->where('S.trans_number', (string) $r->query('TransNumber', ''))
            ->orderBy('S.id')
            ->get(['S.*', DB::raw("COALESCE(L.name, '') AS service_name"), DB::raw("COALESCE(I.name, '') AS status_name"),
                DB::raw("{$net} AS net"), DB::raw('COALESCE(PD.paid, 0) AS paid_amount')])
            ->map(function ($s) {
                $row = $this->legacy($s);
                $row['ServiceID'] = $this->str($s->id);
                $row['Service'] = $s->service_name;
                $row['InvoiceStatus'] = $s->status_name;
                $row['NetAmount'] = $this->str(round((float) $s->net, 2));
                $row['Balance'] = $this->str(round(max(0, (float) $s->net - (float) $s->paid_amount), 2));
                return $row;
            });

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /** GET service_period?CAID=   SOAs of the client account, newest period first */
    public function servicePeriod(Request $r)
    {
        $caid = (int) $r->query('CAID', 0);

        $pt = collect(['m_accounting_period', 't_accounting_period', 'm_accounting_periods'])->first(fn ($t) => Schema::hasTable($t));
        $pc = $pt ? $this->pick($pt, ['service_period', 'period', 'name']) : null;
        $num = $pt ? $this->pick($pt, ['acc_period_num', 'period_num', 'sort_order', 'id']) : null;
        $cur = $pt ? $this->pick($pt, ['current_period', 'is_current']) : null;

        $q = DB::table('t_transaction as T')->where('T.ca_id', $caid)->whereNotNull('T.trans_number')
            ->select(['T.*']);
        if ($pt && $pc) {
            $q->leftJoin("{$pt} as P", "P.{$pc}", '=', 'T.service_period')
                ->addSelect([DB::raw($num ? "P.{$num} AS acc_period_num" : 'NULL AS acc_period_num'), DB::raw($cur ? "P.{$cur} AS current_period" : 'NULL AS current_period')]);
            if ($num) $q->orderByDesc("P.{$num}");
        } else {
            $q->addSelect([DB::raw('NULL AS acc_period_num'), DB::raw('NULL AS current_period')]);
        }

        $list = $q->orderByDesc('T.id')->get()->map(fn ($t) => [
            'TransID' => $this->str($t->id),
            'TransNumber' => $t->trans_number,
            'ServicePeriod' => $t->service_period,
            'IsPaid' => $this->str($t->is_paid ?? 0),
            'CurrentPeriod' => $this->str($t->current_period),
            'AssToID' => $this->str($t->ass_to_id ?? null),
            'AccPeriodNum' => $this->str($t->acc_period_num),
            'EONum1' => $this->str($t->eo_num1 ?? null),
            'EONum2' => $this->str($t->eo_num2 ?? null),
        ]);

        return response()->json(['result' => 'Success', 'list' => $list]);
    }

    /* =========================================================
     * Sales: journal / report / one invoice
     * ========================================================= */

    /** GET sales_journal?userId=&from=YYYY-MM&to=YYYY-MM   same rows as the web Sales Journal */
    public function salesJournal(Request $r)
    {
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;
        try {
            $sj = $this->webReport('salesJournal', (int) $r->query('userId', 0), ['filter_type' => 'DM', 'from' => $from, 'to' => $to, 'branch' => 'current']);
        } catch (Throwable $e) {
            return $this->err($this->failMessage($e));
        }

        $rows = array_map(fn ($x) => [
            'InvoiceNo' => (string) $x['invoice_no'],
            'TransactionDate' => (string) $x['date'],
            'AccountID' => $x['account_id'],
            'DocumentNo' => (string) $x['tran'],
            'Name' => (string) $x['name'],
            'LineDescription' => (string) $x['description'],
            'Debit' => $x['debit'],
            'Credit' => $x['credit'],
        ], $sj['rows'] ?? []);

        $blank = array_fill_keys(['TransactionDate', 'InvoiceNo', 'AccountID', 'DocumentNo', 'Name', 'LineDescription', 'Debit', 'Credit'], '');
        $out = [];
        $n = count($rows);
        foreach ($rows as $i => $row) {
            $out[] = $row;
            if ($i === $n - 1 || $row['InvoiceNo'] !== $rows[$i + 1]['InvoiceNo']) $out[] = $blank;
        }
        return response()->json(['result' => 'Success', 'invoices' => $out]);
    }

    /** GET sales_report?userId=&from=&to=&branchType=&clientNum=   posted sales lines (same filter as the web Sales Report) */
    public function salesReport(Request $r)
    {
        $ids = $this->reportUsers($r) ?: [0];
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;

        $lines = DB::table('t_sales_invoice_detail')->whereIn('added_by', $ids)
            ->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted')
            ->whereBetween('declared_month', [$from, $to])
            ->orderBy('declared_month')->orderBy('id')->get();

        $customers = DB::table('m_vendor_customer')->whereIn('id', $lines->pluck('customer_id')->filter()->unique())->get()->keyBy('id');
        $accounts = $this->accountNames($ids);

        $rows = $lines->map(function ($l) use ($customers, $accounts) {
            $c = $customers[$l->customer_id] ?? null;
            $rate = (float) ($l->ex_rate ?: 1);
            $doc = (string) ($l->document_no ?? '');
            return [
                'ID' => $this->str($l->id),
                'BranchCode' => $this->str($c->branch_code ?? ''),
                'PODate' => $l->transaction_date ? Carbon::parse($l->transaction_date)->format('d-M-y') : '',
                'InvoiceNo' => (string) $l->invoice_no,
                'DeclaredMonth' => (string) $l->declared_month,
                'TIN' => $this->str($c->tin ?? ''),
                'VendorName' => $c->name ?? 'WALK IN',
                'DocumentNo' => strlen($doc) > 3 ? $doc : (string) $l->invoice_no,
                'AccountCode' => $this->str($l->account_code),
                'Account_Name' => (string) ($accounts[$l->added_by . '|' . $l->account_code] ?? ''),
                'Total' => $this->str($l->amount),
                'Currency' => $l->currency ?: 'PHP',
                'ExRate' => $this->str($rate),
                'DateAdded' => $l->created_at ? (string) $l->created_at : null,
                'Total2' => $this->str(round((float) $l->amount * $rate, 4)),
                'NetOfVAT' => $this->str(round((float) $l->net_of_vat * $rate, 4)),
                'inputVAT' => $this->str(round((float) $l->input_vat * $rate, 4)),
                'VATExempt' => $this->str(round((float) $l->vat_exempt * $rate, 4)),
                'ZeroRated' => $this->str(round((float) $l->zero_rated * $rate, 4)),
                'NonVAT' => $this->str(round((float) $l->non_vat * $rate, 4)),
            ];
        })->values();

        return response()->json(['result' => 'Success', 'invoices' => $rows]);
    }

    /** "added_by|account_code" → account name */
    private function accountNames(array $ids)
    {
        return DB::table('m_charts_of_account')->whereIn('added_by', $ids)
            ->get(['added_by', 'account_code', 'account_name'])
            ->mapWithKeys(fn ($a) => [$a->added_by . '|' . $a->account_code => $a->account_name]);
    }

    /** GET sales_info?InvoiceNo=&UserID=   one sales invoice + its lines */
    public function salesInfo(Request $r)
    {
        $invoiceNo = (string) $r->query('InvoiceNo', '');
        $userId = (int) $r->query('UserID', 0);

        try {
            $masters = DB::table('t_sales_invoice_master as M')
                ->leftJoin('m_vendor_customer as V', 'V.id', '=', 'M.customer_id')
                ->where('M.invoice_no', $invoiceNo)
                ->when($userId, fn ($q) => $q->where('M.added_by', $userId))
                ->get(['M.*', 'V.name as customer_name', 'V.tin as customer_tin', 'V.branch_code as customer_branch_code']);

            $itemTax = $this->pick('m_items', ['tax_type', 'item_tax_type_id', 'tax_type_id']);
            $itemPrice = $this->pick('m_items', ['price', 'selling_price', 'unit_price']);
            $wh = $this->pick('t_sales_invoice_detail', ['store_warehouse_id', 'warehouse_id', 'warehouse']);
            $cost = $this->pick('t_sales_invoice_detail', ['cost_each', 'unit_cost']);
            $masterId = $this->pick('t_sales_invoice_detail', ['invoice_master_id', 'sales_invoice_master_id', 'master_id']);

            $details = DB::table('t_sales_invoice_detail as A')
                ->leftJoin('m_items as B', 'B.id', '=', 'A.item_id')
                ->leftJoin('m_charts_of_account as C', fn ($j) => $j->on('C.account_code', '=', 'A.account_code')->on('C.added_by', '=', 'A.added_by'))
                ->leftJoin('m_unit_type as D', 'D.id', '=', 'A.unit_type_id')
                ->where('A.invoice_no', $invoiceNo)
                ->when($userId, fn ($q) => $q->where('A.added_by', $userId))
                ->orderByDesc('A.id')
                ->get(array_filter([
                    'A.*', 'B.item_code', 'B.description as item_description', 'C.account_code as coa_code', 'C.account_name as coa_name', 'D.name as unit_name',
                    $itemTax ? "B.{$itemTax} as item_tax_type" : null,
                    $itemPrice ? "B.{$itemPrice} as item_price" : null,
                ]))
                ->map(fn ($a) => [
                    'ID' => $this->str($a->id),
                    'InvoiceMasterID' => $this->str($masterId ? $a->$masterId : null),
                    'InvoiceNo' => (string) $a->invoice_no,
                    'ItemID' => $this->str($a->item_id),
                    'AccountCode' => $this->str($a->account_code),
                    'inputVAT' => $this->str($a->input_vat),
                    'NetOfVAT' => $this->str($a->net_of_vat),
                    'VATExempt' => $this->str($a->vat_exempt),
                    'NonVAT' => $this->str($a->non_vat),
                    'ZeroRated' => $this->str($a->zero_rated),
                    'UnitType' => $this->str($a->unit_type_id ?? null),
                    'WareHouse' => $this->str($wh ? $a->$wh : null),
                    'DiscountAmount' => $this->str($a->discount_amount ?? 0),
                    'ItemCode' => $a->item_code,
                    'TaxType' => $this->str($a->item_tax_type ?? null),
                    'Code' => $this->str($a->coa_code),
                    'AccountTitle' => $a->coa_name,
                    'UnitTypeDesc' => (string) ($a->unit_name ?? ''),
                    'UnitTypeID' => $this->str($a->unit_type_id ?? 0),
                    'UnitPrice' => $this->str($a->item_price ?? 0),
                    'UnitCost' => $this->str($cost ? $a->$cost : 0),
                    'ProductID' => $this->str($a->item_id),
                    'Description' => $a->item_description,
                    'ProductPrice' => $this->str($a->item_price ?? 0),
                    'Qty' => $this->str($a->quantity ?? null),
                    'Price' => $this->str($a->unit_price ?? null),
                    'Total' => $this->str($a->amount ?? null),
                ])->values();

            $invoices = $masters->map(function ($m) use ($details) {
                $row = $this->legacy($m);
                $row['InvoiceNo'] = (string) $m->invoice_no;
                $row['CustomerName'] = $m->customer_name;
                $row['detail'] = $details;
                return $row;
            });

            return response()->json(['result' => 'Success', 'invoices' => $invoices]);
        } catch (Throwable $e) {
            return $this->err('Execution failed: ' . $e->getMessage());
        }
    }

    /* =========================================================
     * Purchases report
     * ========================================================= */

    /** GET purchase_report?userId=&from=&to=&branchType=&clientNum=   GRN lines + posted AP purchase lines (same filter as the web) */
    public function purchaseReport(Request $r)
    {
        $ids = $this->reportUsers($r) ?: [0];
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;

        $grn = DB::table('t_grn_detail')->whereIn('receive_by', $ids)->where('total', '<>', 0)
            ->where($this->pick('t_grn_detail', ['grn_status_id', 'invoice_status_id']) ?? 'grn_status_id', '<>', 5)
            ->whereBetween('declared_month', [$from, $to])->get();
        $ap = PurchaseExpenseDetail::whereIn('added_by', $ids)->purchases()->where('record_status', 'posted')
            ->where('invoice_status_id', '<>', 5)->where('amount', '<>', 0)
            ->whereBetween('declared_month', [$from, $to])->get();

        $vendors = DB::table('m_vendor_customer')->whereIn('id', $grn->pluck('vendor_id')->merge($ap->pluck('vendor_id'))->filter()->unique())->get()->keyBy('id');
        $accounts = $this->accountNames($ids);

        $row = function ($src, $by, $date, string $doc, string $ref, $total) use ($vendors, $accounts) {
            $v = $vendors[$src->vendor_id] ?? null;
            $d = $date ? Carbon::parse($date) : null;
            $po = (string) ($src->po_number ?? '');
            $created = $src->created_at ? Carbon::parse($src->created_at) : null;
            return [
                'ID' => $this->str($src->id),
                'BranchCode' => $this->str($v->branch_code ?? ''),
                'PODate' => $d ? $d->format('d-M-y') : '',
                'PODate2' => $d ? $d->format('Y-m-d') : '',
                'DeclaredMonth' => (string) $src->declared_month,
                'TIN' => $this->str($v->tin ?? ''),
                'VendorName' => $v->name ?? '',
                'DocumentNo' => strlen($doc) > 3 ? $doc : $po,
                'RefNo' => $ref,
                'PONumber' => $po,
                'AccountCode' => $this->str($src->account_code),
                'Account_Name' => (string) ($accounts[$by . '|' . $src->account_code] ?? ''),
                'Total' => $this->str($total),
                'Currency' => 'PHP',
                'ExRate' => '1.0000',
                'Total2' => $this->str($total),
                'NetOfVAT' => $this->str($src->net_of_vat),
                'inputVAT' => $this->str($src->input_vat),
                'VATExempt' => $this->str($src->vat_exempt),
                'ZeroRated' => $this->str($src->zero_rated),
                'TableType' => 'PO',
                'NonVAT' => $this->str($src->non_vat),
                'DateAdded' => $created ? $created->format('Y-m-d H:i:s') : null,
                'DateAdded2' => $created ? $created->format('Y-m-d') : null,
                '_sort' => [(string) $src->declared_month, $d ? $d->format('Y-m-d') : ''],
            ];
        };

        $rows = $grn->map(fn ($l) => $row($l, $l->receive_by, $l->grn_date, (string) $l->grn_no, (string) $l->grn_no, $l->total))
            ->merge($ap->map(fn ($l) => $row($l, $l->added_by, $l->transaction_date, (string) ($l->document_no ?? ''), (string) $l->invoice_no, $l->amount)))
            ->sortBy('_sort')->values()
            ->map(function ($x) { unset($x['_sort']); return $x; });

        return response()->json(['result' => 'Success', 'invoices' => $rows]);
    }

    /* =========================================================
     * Trial balance (same numbers as the web Trial Balance)
     * ========================================================= */

    /** GET trial_balance?userId=&from=YYYY-MM&to=YYYY-MM   one side per account (debit − credit) */
    public function trialBalance(Request $r)
    {
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '') ?: $from;
        try {
            $tb = $this->webReport('trialBalance', (int) $r->query('userId', 0), ['filter_type' => 'DM', 'from' => $from, 'to' => $to, 'branch' => 'current']);
        } catch (Throwable $e) {
            return $this->err($this->failMessage($e));
        }

        $rows = [];
        foreach ($tb['rows'] ?? [] as $i => $a) {
            $dr = (float) $a['debit'];
            $cr = (float) $a['credit'];
            $isDebit = $dr >= $cr ? 1 : 0;
            if ($dr > 0 && $cr > 0) {
                [$dr, $cr] = $isDebit ? [$dr - $cr, 0] : [0, $cr - $dr];
            }
            $rows[] = [
                'ID' => $i + 1,
                'IsDebit' => $isDebit,
                'AccountCode' => (string) $a['account_code'],
                'Account_Name' => $a['account_name'],
                'Debit' => round($dr, 4),
                'Credit' => round($cr, 4),
            ];
        }
        return response()->json(['result' => 'Success', 'invoices' => $rows]);
    }

    /* =========================================================
     * Transactions of a year (transaction.php): PurchaseJournal | ExpenseJournal | SalesJournal
     * ========================================================= */

    /** GET transaction?userId=&year=&transactionType= */
    public function transaction(Request $r)
    {
        $userId = (int) $r->query('userId', 0);
        $year = (string) (int) $r->query('year', date('Y'));
        $type = (string) $r->query('transactionType', 'PurchaseJournal');

        $header = ['Transaction ID', 'Date', 'Vendor/Customer', 'TIN', 'Branch Code', 'Tax Type', 'Street and Barangay',
            'City/Municipality/Province/Country', 'Document', 'Remarks', 'Client Branch', 'Declared Month', 'Tax Declared For',
            'Account Title', 'Currency', 'Amount', 'Exchange Rate', 'PHP Amount', 'VAT Exempt', 'Zero Rated', 'Non VAT', 'Net of VAT',
            'input VAT', 'ATC(VT/PT)', 'BIR2307(VT/PT)', '2307 (VT/PT) Declared Month', 'BIR2307(VT/PT) Remarks', 'ATC(IT)',
            'BIR2307(IT)', '2307 (IT) Declared Month', 'BIR2307(IT) Remarks', 'Status', 'Entry By', 'DateTime Entry'];

        $inYear = fn ($q) => $q->where('declared_month', 'like', "{$year}-%");
        $docs = [];   // [[key, lines collection, master, party id, date, doc no, ref, rate, currency]]

        if ($type === 'SalesJournal') {
            $lines = $inYear(DB::table('t_sales_invoice_detail')->where('added_by', $userId)->where('invoice_status_id', '<>', 5)->where('trans_type', 'posted'))
                ->orderBy('transaction_date')->orderBy('id')->get();
            $masters = DB::table('t_sales_invoice_master')->where('added_by', $userId)->whereIn('invoice_no', $lines->pluck('invoice_no')->unique())->get()->keyBy('invoice_no');
            foreach ($lines->groupBy('invoice_no') as $no => $ls) {
                $m = $masters[$no] ?? null;
                $f = $ls->first();
                $docs[] = [$no, $ls, $m, $f->customer_id, $f->transaction_date, (string) ($f->document_no ?? ''), (float) ($f->ex_rate ?: 1), $f->currency ?: 'PHP', 'amount'];
            }
        } else {
            $isExp = $type === 'ExpenseJournal';
            $ap = PurchaseExpenseDetail::where('added_by', $userId)->active()->where('record_status', 'posted')
                ->when($isExp, fn ($q) => $q->expenses(), fn ($q) => $q->purchases())
                ->where('declared_month', 'like', "{$year}-%")->orderBy('transaction_date')->orderBy('id')->get();
            $apM = DB::table('t_purchase_expense_master')->where('added_by', $userId)->whereIn('invoice_no', $ap->pluck('invoice_no')->unique())->get()->keyBy('invoice_no');
            foreach ($ap->groupBy('invoice_no') as $no => $ls) {
                $f = $ls->first();
                $docs[] = [$no, $ls, $apM[$no] ?? null, $f->vendor_id, $f->transaction_date, (string) ($f->document_no ?? ''), 1.0, 'PHP', 'amount'];
            }
            if (!$isExp) {
                $st = $this->pick('t_grn_detail', ['grn_status_id', 'invoice_status_id']) ?? 'grn_status_id';
                $grn = $inYear(DB::table('t_grn_detail')->where('receive_by', $userId)->where($st, '<>', 5))->orderBy('grn_date')->orderBy('id')->get();
                $grnM = DB::table('t_grn_master')->whereIn('grn_no', $grn->pluck('grn_no')->unique())->get()->keyBy('grn_no');
                foreach ($grn->groupBy('grn_no') as $no => $ls) {
                    $f = $ls->first();
                    $docs[] = [$no, $ls, $grnM[$no] ?? null, $f->vendor_id, $f->grn_date, (string) $no, 1.0, 'PHP', 'total'];
                }
            }
        }

        // lookups
        $parties = DB::table('m_vendor_customer')->whereIn('id', collect($docs)->pluck(3)->filter()->unique())->get()->keyBy('id');
        $names = $this->accountNames([$userId]);
        $atcs = DB::table('m_atc')->pluck('rate', 'id');
        $statuses = DB::table('m_invoice_status')->pluck('name', 'id');
        $entryBy = DB::table('m_user_client')->where('id', $userId)->value('username');
        $taxCol = $this->pick('m_items', ['tax_type', 'item_tax_type_id', 'tax_type_id']);
        $taxTable = collect(['m_item_tax_type', 'm_item_tax_types'])->first(fn ($t) => Schema::hasTable($t));
        $taxName = $taxTable ? $this->pick($taxTable, ['name', 'tax_type', 'description']) : null;
        $itemTax = $taxCol ? DB::table('m_items')->whereIn('id', collect($docs)->flatMap(fn ($d) => $d[1]->pluck('item_id'))->filter()->unique())->pluck($taxCol, 'id') : collect();
        $taxNames = $taxTable && $taxName ? DB::table($taxTable)->pluck($taxName, 'id') : collect();
        $pTax = $this->pick('m_vendor_customer', ['tax_type', 'tax_type_id']);
        $addr = $this->pick('m_vendor_customer', ['mail_to_address', 'street']);
        $city = $this->pick('m_vendor_customer', ['mail_to_city_country', 'city']);

        $fmt = fn ($v) => ['value' => $this->str(round((float) $v, 4)), 'formatted' => number_format((float) $v, 2)];
        $foot = array_fill_keys(['F_Total', 'F_PHPAmount', 'F_VATExempt', 'F_ZeroRated', 'F_NonVAT', 'F_NetOfVAT', 'F_inputVAT', 'F2307_VT', 'F2307_IT'], 0.0);

        $data = [];
        foreach ($docs as [$no, $ls, $m, $partyId, $date, $docNo, $rate, $currency, $amtCol]) {
            $p = $parties[$partyId] ?? null;
            $sum = fn ($col, $mul = 1) => $ls->sum(fn ($l) => (float) ($l->$col ?? 0) * $mul);
            $per = fn ($col, $mul = 1) => $ls->map(fn ($l) => $fmt((float) ($l->$col ?? 0) * $mul))->values()->all();
            $vt = (float) ($m->{'2307_vt'} ?? 0);
            $it = (float) ($m->{'2307_it'} ?? 0);
            $d = $date ? Carbon::parse($date) : null;
            $statusId = $m->invoice_status_id ?? $ls->first()->invoice_status_id ?? $ls->first()->grn_status_id ?? null;

            $row = [
                'TransactionID' => (string) $no,
                'TransactionDate' => $d ? $d->format('d-M-y') : '',
                'OrigDate' => $d ? $d->format('Y-m-d') : '',
                'VendorCustomer' => ['id' => $this->str($partyId), 'name' => $p->name ?? ''],
                'TIN' => $this->str($p->tin ?? ''),
                'BranchCode' => $this->str($p->branch_code ?? ''),
                'TaxType' => $pTax ? $this->str($taxNames[$p->$pTax ?? 0] ?? ($p->$pTax ?? '')) : '',
                'StreetBarangay' => $addr ? ($p->$addr ?? '') : '',
                'CityCountry' => $city ? ($p->$city ?? '') : '',
                'DocumentNo' => $docNo,
                'Remarks' => (string) ($m->remarks ?? ''),
                'ClientBranch' => '',
                'DeclaredMonth' => (string) $ls->first()->declared_month,
                'TaxDeclaredFor' => $ls->map(fn ($l) => (string) ($taxNames[$itemTax[$l->item_id ?? 0] ?? 0] ?? ''))->values()->all(),
                'AccountTitle' => $ls->map(fn ($l) => ['code' => $this->str($l->account_code), 'name' => (string) ($names[$userId . '|' . $l->account_code] ?? '')])->values()->all(),
                'Currency' => $currency,
                'Amount' => $per($amtCol),
                'ExchangeRate' => number_format($rate, 4, '.', ''),
                'PHPAmount' => $per($amtCol, $rate),
                'VATExempt' => $per('vat_exempt', $rate),
                'ZeroRated' => $per('zero_rated', $rate),
                'NonVAT' => $per('non_vat', $rate),
                'NetOfVAT' => $per('net_of_vat', $rate),
                'inputVAT' => $per('input_vat', $rate),
                'ATC_VT' => $this->str($atcs[$m->atc_vt_id ?? 0] ?? 0),
                'BIR2307_VT' => $fmt($vt),
                'VT_DeclaredMonth' => $m->{'2307_vt_declared_month'} ?? null,
                'VT_DeclaredRemarks' => '',
                'ATC_IT' => $this->str($atcs[$m->atc_it_id ?? 0] ?? 0),
                'BIR2307_IT' => $fmt($it),
                'IT_DeclaredMonth' => $m->{'2307_it_declared_month'} ?? null,
                'IT_DeclaredRemarks' => '',
                'Status' => $statuses[$statusId] ?? null,
                'EntryBy' => $entryBy,
                'DateTimeEntry' => $m->created_at ?? $ls->first()->created_at ?? null,
                'F_Total' => $this->str(round($sum($amtCol), 4)),
                'F_PHPAmount' => $this->str(round($sum($amtCol, $rate), 4)),
                'F_VATExempt' => $this->str(round($sum('vat_exempt', $rate), 4)),
                'F_ZeroRated' => $this->str(round($sum('zero_rated', $rate), 4)),
                'F_NonVAT' => $this->str(round($sum('non_vat', $rate), 4)),
                'F_NetOfVAT' => $this->str(round($sum('net_of_vat', $rate), 4)),
                'F_inputVAT' => $this->str(round($sum('input_vat', $rate), 4)),
            ];
            $data[] = $row;

            foreach (['F_Total', 'F_PHPAmount', 'F_VATExempt', 'F_ZeroRated', 'F_NonVAT', 'F_NetOfVAT', 'F_inputVAT'] as $k) $foot[$k] += (float) $row[$k];
            $foot['F2307_VT'] += $vt;
            $foot['F2307_IT'] += $it;
        }

        usort($data, fn ($a, $b) => strcmp($a['OrigDate'], $b['OrigDate']));

        return response()->json([
            'result' => 'Success',
            'header' => $header,
            'data' => $data,
            'footer' => array_map(fn ($v) => ['value' => $v, 'formatted' => number_format($v, 2)], $foot),
        ]);
    }
}