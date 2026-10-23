<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Payment;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring
 *
 * Reconciliation Monitoring (old pages/Monitoring/Reconciliation_Monitoring.php) — year grid per client:
 *   GET  /monitoring/reconciliation?year=2026                       clients × 12 months (locked / printed / status)
 *   GET  /monitoring/reconciliation/{clientNum}/accounts?year=      branch accounts of a client (old doGetReconMonitoringDtls)
 *   GET  /monitoring/reconciliation/compliance?client_id=&month=    compliances due that month + ticked (old doGetComplianceList)
 *   PUT  /monitoring/reconciliation/compliance                      tick / untick one compliance (old ajax/doUpdateCompliance.php)
 *   PUT  /monitoring/reconciliation/flag                            locked / printed (old ajax/doUpdateReconMonitoring.php)
 *
 * Audit Reconciliation (old pages/Monitoring/Audit_Reconciliation.php) — diagnose one month:
 *   GET  /monitoring/audit-reconciliation?month=YYYY-MM&type=EBIR   run diagnose (old doGetClientPerformance)
 *   POST /monitoring/audit-reconciliation                           save the reconciled clients (old ajax/save_reconciled.php)
 *
 * For every active client (status 2/9/11) of the return type, each compliance due this month is compared:
 *   eBIRForms save file (EBIR_SAVEFILE_DIR, default C:/eBIRForms/savefile/)  vs  the FEMS totals for that client.
 * Old tables → new: tbl2_user → m_user_client (id, caid) · tbl_client_account → m_client_account · tbl_client → m_client
 *   tbl2_invoice_* → t_sales_invoice_* · tbl2_apinvoice_* → t_purchase_expense_* · tbl2_grn_* → t_grn_* · tbl2_importation_detail → t_importation_detail
 */
class MonitoringController extends Controller
{
    const VOID = 5;
    const CLIENT_STATUSES = [2, 9, 11];
    const FILE_CODES = ['0619E', '1601Cv2018', '2550Qv2024', '1701Qv2018', '2551Qv2018', '1601EQ', '2550M'];

    /* ---------------- helpers ---------------- */

    private static array $colCache = [];

    protected function cols(string $table): array
    {
        return self::$colCache[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }

    protected function pick(string $table, array $names): ?string
    {
        foreach ($names as $n) if (in_array($n, $this->cols($table), true)) return $n;
        return null;
    }

    protected function firstTable(array $names): ?string
    {
        foreach ($names as $n) if (Schema::hasTable($n)) return $n;
        return null;
    }

    /** logged-in employee id (must exist in m_employee) */
    protected function me(Request $request): ?int
    {
        $u = $request->user();
        if (!$u) return null;
        $ids = [];
        foreach (['employee_id', 'emp_id', 'employeeid'] as $k) {
            $v = $u->getAttribute($k);
            if (is_numeric($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        if (method_exists($u, 'employee') && ($e = $u->employee)) $ids[] = (int) $e->id;
        $ids[] = (int) $u->id;
        foreach (array_unique(array_filter($ids)) as $id) {
            if (DB::table('m_employee')->where('id', $id)->exists()) return $id;
        }
        return null;
    }

    /** active client filter on m_client alias (old: D.Active = 1 AND D.StatusID IN (2,9,11)) */
    private function clientWhere(string $a = 'D'): string
    {
        $sql = "{$a}.status_id IN (" . implode(',', self::CLIENT_STATUSES) . ")";
        if ($active = $this->pick('m_client', ['active', 'is_active'])) $sql .= " AND {$a}.{$active} = 1";
        return $sql;
    }

    /** old: INNER JOIN tbl2_user B ON B.UserID = A.AddedBy → tbl_client_account → tbl_client */
    private function clientJoin(string $userCol): string
    {
        return "INNER JOIN m_user_client U ON U.id = A.{$userCol}
                INNER JOIN m_client_account C ON C.id = U.caid
                INNER JOIN m_client D ON D.client_num = C.client_num";
    }

    /** run a "client id → value" query */
    private function mapOf(string $sql, array $bindings, string $col = 'v'): array
    {
        $map = [];
        foreach (DB::select($sql, $bindings) as $r) $map[(int) $r->cid] = (float) $r->{$col};
        return $map;
    }

    /* ---------------- FEMS totals (old doGetFems*) ---------------- */

    /** 0619E / 1601EQ: expanded withholding (2307_IT) from AP invoices + receive inventory */
    private function femsWithheldIt(string $from, string $to, bool $roundEach): array
    {
        $sum = $roundEach ? "ROUND(SUM(A.`2307_it`), 2)" : "SUM(A.`2307_it`)";
        $w = $this->clientWhere();
        $sql = "
            SELECT x.cid, SUM(x.v) AS v FROM (
                SELECT D.id AS cid, {$sum} AS v
                FROM t_purchase_expense_master A {$this->clientJoin('added_by')}
                WHERE A.invoice_status_id <> " . self::VOID . " AND A.`2307_it` != 0
                  AND A.`2307_it_declared_month` BETWEEN ? AND ? AND {$w}
                GROUP BY D.id
                UNION ALL
                SELECT D.id AS cid, {$sum} AS v
                FROM t_grn_master A {$this->clientJoin('receive_by')}
                WHERE A.invoice_status_id <> " . self::VOID . " AND A.`2307_it` != 0
                  AND A.`2307_it_declared_month` BETWEEN ? AND ? AND {$w}
                GROUP BY D.id
            ) x GROUP BY x.cid";
        return $this->mapOf($sql, [$from, $to, $from, $to]);
    }

    /** 1601C: compensation (account 69400) */
    private function fems1601C(string $from, string $to): array
    {
        $sql = "
            SELECT D.id AS cid, COALESCE(SUM(A.net_of_vat + A.vat_exempt + A.non_vat + A.zero_rated), 0) AS v
            FROM t_purchase_expense_detail A {$this->clientJoin('added_by')}
            WHERE A.declared_month BETWEEN ? AND ? AND A.account_code = '69400' AND {$this->clientWhere()}
            GROUP BY D.id";
        return $this->mapOf($sql, [$from, $to]);
    }

    /** old doGetVATPaidCode */
    private function vatPaidCodes(): array
    {
        return DB::table('m_charts_of_account')->where('account_name', 'like', '%Vat Paid%')
            ->distinct()->pluck('account_code')->map(fn ($c) => (string) $c)->all();
    }

    /** 2550M / 2550Q breakdown per client (old doGetFems2550M / doGetFems2550Q) */
    private function fems25(string $from, string $to, bool $quarterly): array
    {
        $map = [];
        $put = function (array $rows, array $fields) use (&$map) {
            foreach ($rows as $r) {
                $cid = (int) $r->cid;
                $map[$cid] ??= ['NetOfVAT' => 0, 'ZeroRated' => 0, 'VATExempt' => 0, 'PENetOfVAT' => 0, 'ImpNetOfVAT' => 0,
                    'VATExmptZeroRated' => 0, 'VATTaxWithheld' => 0, 'AdvanceVATPayment' => 0];
                foreach ($fields as $key => $col) $map[$cid][$key] = (float) $r->{$col};
            }
        };
        $w = $this->clientWhere();
        $void = self::VOID;
        $b = [$from, $to];
        $grnJoin = "INNER JOIN t_grn_master GM ON GM.grn_no = A.grn_no AND GM.invoice_status_id <> {$void}";

        // sales: NetOfVAT / ZeroRated / VATExempt
        $put(DB::select("
            SELECT D.id AS cid,
                   COALESCE(SUM(A.vat_exempt * A.ex_rate), 0) AS ve,
                   COALESCE(SUM(A.zero_rated * A.ex_rate), 0) AS zr,
                   COALESCE(SUM(A.net_of_vat * A.ex_rate), 0) AS nv
            FROM t_sales_invoice_detail A {$this->clientJoin('added_by')}
            WHERE A.declared_month BETWEEN ? AND ? AND A.invoice_status_id <> {$void} AND {$w}
            GROUP BY D.id", $b), ['NetOfVAT' => 'nv', 'ZeroRated' => 'zr', 'VATExempt' => 've']);

        // purchases net of VAT (receive inventory + AP)
        $put(DB::select("
            SELECT x.cid, SUM(x.v) AS v FROM (
                SELECT D.id AS cid, COALESCE(SUM(A.net_of_vat), 0) AS v
                FROM t_grn_detail A {$grnJoin} {$this->clientJoin('receive_by')}
                WHERE A.declared_month BETWEEN ? AND ? AND A.net_of_vat != 0 AND {$w}
                GROUP BY D.id
                UNION ALL
                SELECT D.id AS cid, COALESCE(SUM(A.net_of_vat), 0) AS v
                FROM t_purchase_expense_detail A {$this->clientJoin('added_by')}
                WHERE A.invoice_status_id <> {$void} AND A.declared_month BETWEEN ? AND ? AND A.net_of_vat != 0 AND {$w}
                GROUP BY D.id
            ) x GROUP BY x.cid", [...$b, ...$b]), ['PENetOfVAT' => 'v']);

        // importation net of VAT
        $put(DB::select("
            SELECT D.id AS cid, COALESCE(SUM(A.taxable_imports * A.ex_rate), 0) AS v
            FROM t_importation_detail A {$this->clientJoin('added_by')}
            WHERE A.declared_month BETWEEN ? AND ? AND A.taxable_imports > 0 AND A.invoice_status_id <> {$void} AND {$w}
            GROUP BY D.id", $b), ['ImpNetOfVAT' => 'v']);

        // purchases VAT exempt + zero rated
        $put(DB::select("
            SELECT x.cid, SUM(x.v) AS v FROM (
                SELECT D.id AS cid, COALESCE(SUM(A.vat_exempt + A.zero_rated), 0) AS v
                FROM t_grn_detail A {$grnJoin} {$this->clientJoin('receive_by')}
                WHERE A.declared_month BETWEEN ? AND ? AND {$w}
                GROUP BY D.id
                UNION ALL
                SELECT D.id AS cid, COALESCE(SUM(A.vat_exempt + A.zero_rated), 0) AS v
                FROM t_purchase_expense_detail A {$this->clientJoin('added_by')}
                WHERE A.invoice_status_id <> {$void} AND A.declared_month BETWEEN ? AND ? AND {$w}
                GROUP BY D.id
            ) x GROUP BY x.cid", [...$b, ...$b]), ['VATExmptZeroRated' => 'v']);

        // VAT withheld (2307_VT on sales)
        $put(DB::select("
            SELECT D.id AS cid, SUM(A.`2307_vt`) AS v
            FROM t_sales_invoice_master A {$this->clientJoin('added_by')}
            WHERE A.invoice_status_id <> {$void} AND A.`2307_vt` > 0
              AND A.`2307_vt_declared_month` BETWEEN ? AND ? AND {$w}
            GROUP BY D.id", $b), ['VATTaxWithheld' => 'v']);

        // 2550Q only: advance VAT payment (VAT Paid accounts, months before the last month of the quarter)
        if ($quarterly && ($codes = $this->vatPaidCodes())) {
            $total = $this->pick('t_purchase_expense_detail', ['total'])
                ? 'A.total' : 'A.amount - COALESCE(A.discount_amount, 0)';
            $toAdj = date('Y-m', strtotime($to . '-01 -1 month'));
            $in = implode(',', array_fill(0, count($codes), '?'));
            $put(DB::select("
                SELECT D.id AS cid, COALESCE(SUM({$total}), 0) AS v
                FROM t_purchase_expense_detail A {$this->clientJoin('added_by')}
                WHERE A.declared_month BETWEEN ? AND ? AND A.account_code IN ({$in}) AND {$w}
                GROUP BY D.id", [$from, $toAdj, ...$codes]), ['AdvanceVATPayment' => 'v']);
        }

        return $map;
    }

    /* ---------------- eBIRForms save files ---------------- */

    private function ebirDir(): string
    {
        return rtrim(str_replace('\\', '/', env('EBIR_SAVEFILE_DIR', 'C:/eBIRForms/savefile/')), '/') . '/';
    }

    /** old buildEBIRFileIndex */
    private function fileIndex(string $dir): array
    {
        $index = ['_all' => []];
        foreach (self::FILE_CODES as $code) $index[$code] = [];
        foreach (glob($dir . '*.xml') ?: [] as $path) {
            $name = basename($path);
            $index['_all'][] = $name;
            foreach (self::FILE_CODES as $code) {
                if (strpos($name, '-' . $code . '-') !== false) $index[$code][] = $name;
            }
        }
        return $index;
    }

    /** old findEBIRFile: longest file name matching TIN*-FORM-PERIOD*.xml */
    private function findFile(array $index, string $tin, string $compliance, string $period): string
    {
        if ($period === '') return '';
        $code = match ($compliance) {
            '1601C' => '1601Cv2018', '2550Q' => '2550Qv2024', '1701Q' => '1701Qv2018', '2551Q' => '2551Qv2018',
            default => $compliance,
        };
        $needle = $tin . '*-' . $code . '-' . $period . '*.xml';
        $best = '';
        foreach ($index[$code] ?? $index['_all'] as $file) {
            if (fnmatch($needle, $file) && strlen($file) > strlen($best)) $best = $file;
        }
        return $best;
    }

    /** old doGetEBIRSaveFile: key=value pairs inside <div>…</div> */
    private function readFile(string $dir, string $file): array
    {
        static $cache = [];
        if ($file === '') return [];
        if (isset($cache[$file])) return $cache[$file];
        $path = $dir . $file;
        if (!is_file($path)) return $cache[$file] = [];

        preg_match_all('/<div>(.*?)<\/div>/s', (string) file_get_contents($path), $m);
        $data = [];
        foreach ($m[1] as $block) {
            if (preg_match('/^([a-zA-Z0-9:._]+)=/', $block, $k)) {
                $key = $k[1];
                $value = preg_replace('/^' . preg_quote($key, '/') . '=/', '', $block);
                $value = preg_replace('/' . preg_quote($key, '/') . '=$/', '', $value);
                $data[$key] = urldecode(trim($value));
            }
        }
        return $cache[$file] = $data;
    }

    private function num(array $raw, string $key): float
    {
        return (float) str_replace(',', '', $raw[$key] ?? '0');
    }

    private function same(float $a, float $b): bool
    {
        return round($a, 2) == round($b, 2);
    }

    /** old doGetComplianceList: does the filed return match FEMS? */
    private function isChecked(string $code, array $raw, int $cid, array $fems): bool
    {
        if (!$raw) return false;
        switch ($code) {
            case '0619E':
                return $this->same($this->num($raw, 'frm0619E:txtTax14'), $fems['0619E'][$cid] ?? 0);
            case '1601C':
                return $this->same($this->num($raw, 'frm1601c:txtTax14'), $fems['1601C'][$cid] ?? 0);
            case '1601EQ':
                return $this->same($this->num($raw, 'frm1601EQ:txtTax19'), $fems['1601EQ'][$cid] ?? 0);
            case '2550M':
                $f = $fems['2550M'][$cid] ?? [];
                // the old code compared txtTax14 with itself, so zero-rated never failed — kept the same
                return $this->same($this->num($raw, 'frm2550m:txtTax12A'), $f['NetOfVAT'] ?? 0)
                    && $this->same($this->num($raw, 'frm2550m:txtTax15'), $f['VATExempt'] ?? 0)
                    && $this->same($this->num($raw, 'frm2550m:txtTax18E'), $f['PENetOfVAT'] ?? 0)
                    && $this->same($this->num($raw, 'frm2550m:txtTax18G'), $f['ImpNetOfVAT'] ?? 0)
                    && $this->same($this->num($raw, 'frm2550m:txtTax18M'), $f['VATExmptZeroRated'] ?? 0)
                    && $this->same($this->num($raw, 'frm2550m:txtTax23C'), $f['VATTaxWithheld'] ?? 0);
            case '2550Q':
                $f = $fems['2550Q'][$cid] ?? [];
                $p = 'frm2550qv2024:';
                return $this->same($this->num($raw, $p . 'vatableSales'), $f['NetOfVAT'] ?? 0)
                    && $this->same($this->num($raw, $p . 'zeroRatedSales'), $f['ZeroRated'] ?? 0)
                    && $this->same($this->num($raw, $p . 'exemptSales'), $f['VATExempt'] ?? 0)
                    && $this->same($this->num($raw, $p . 'domesticPurchase'), $f['PENetOfVAT'] ?? 0)
                    && $this->same($this->num($raw, $p . 'importPurchase'), $f['ImpNetOfVAT'] ?? 0)
                    && $this->same($this->num($raw, $p . 'domesticPurchaseNoTax'), $f['VATExmptZeroRated'] ?? 0)
                    && $this->same($this->num($raw, $p . 'advVatPayment'), $f['AdvanceVATPayment'] ?? 0)
                    && $this->same($this->num($raw, $p . 'creditableVat'), $f['VATTaxWithheld'] ?? 0);
        }
        return false;
    }

    /* ---------------- GET /monitoring/audit-reconciliation ---------------- */

    public function scan(Request $request)
    {
        $d = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'type'  => ['required', 'in:EBIR,EFPS'],
        ], ['month.required' => 'Month is required']);
        @set_time_limit(300);

        $month = $d['month'];
        $monthNum = (int) substr($month, 5, 2);

        // compliance catalog due this month (old loadComplianceForMonth: month number in Terms "1-2-3…")
        $cTable = $this->firstTable(['m_compliance', 'm_compliances']);
        $cName = $cTable ? $this->pick($cTable, ['compliance', 'name', 'description']) : null;
        $cTerms = $cTable ? $this->pick($cTable, ['terms', 'term']) : null;
        $catalog = ($cTable && $cName && $cTerms)
            ? DB::table($cTable)->whereRaw("FIND_IN_SET(?, REPLACE({$cTerms}, '-', ',')) > 0", [$monthNum])
                ->orderBy($cName)->get(['id', "{$cName} as code"])
            : collect();
        $codeById = $catalog->mapWithKeys(fn ($c) => [(string) $c->id => (string) $c->code])->all();

        // clients (old: tbl_client + at least one login + BIR TIN)
        $vTable = $this->firstTable(['m_client_valid_id', 'm_client_valid_ids']);
        $compCol = $this->pick('m_client', ['compliance_ids', 'compliance_id']);
        $typeCol = $this->pick('m_client', ['return_type']);
        $tinSql = $vTable
            ? "(SELECT V.id_num FROM {$vTable} V WHERE V.client_num = A.client_num AND V.institution = 'BIR' ORDER BY V.id DESC LIMIT 1)"
            : 'NULL';
        $sql = "
            SELECT A.id, A.client_num, " . ($compCol ? "A.{$compCol}" : "''") . " AS compliance_ids,
                   CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name) AS name, {$tinSql} AS tin
            FROM m_client A
            WHERE {$this->clientWhere('A')}
              " . ($typeCol ? "AND A.{$typeCol} = ?" : '') . "
              AND EXISTS (SELECT 1 FROM m_client_account B INNER JOIN m_user_client U ON U.caid = B.id WHERE B.client_num = A.client_num)
            ORDER BY A.last_name DESC";
        $clients = DB::select($sql, $typeCol ? [$d['type']] : []);

        $validTin = fn ($tin) => !empty($tin) && strlen((string) $tin) >= 9;
        $idsOf = fn ($s) => array_values(array_filter(array_map('trim', explode(',', (string) $s)), 'strlen'));

        // only run the FEMS totals some client actually needs
        $needed = [];
        foreach ($clients as $c) {
            if (!$validTin($c->tin)) continue;
            foreach ($idsOf($c->compliance_ids) as $id) {
                if (isset($codeById[$id])) $needed[$codeById[$id]] = true;
            }
        }
        $qFrom = (new DateTime($month . '-01'))->modify('-2 months')->format('Y-m');
        $fems = [
            '0619E'  => isset($needed['0619E']) ? $this->femsWithheldIt($month, $month, false) : [],
            '1601C'  => isset($needed['1601C']) ? $this->fems1601C($month, $month) : [],
            '2550M'  => isset($needed['2550M']) ? $this->fems25($month, $month, false) : [],
            '2550Q'  => isset($needed['2550Q']) ? $this->fems25($qFrom, $month, true) : [],
            '1601EQ' => isset($needed['1601EQ']) ? $this->femsWithheldIt($qFrom, $month, true) : [],
        ];

        // file periods
        [$y, $m] = explode('-', $month);
        $mmyyyy = $m . $y;
        $q = (int) ceil(((int) $m) / 3);
        $periods = [
            '2550M' => $mmyyyy, '0619E' => $mmyyyy, '1601C' => $mmyyyy,
            '1701Q' => $y . 'Q' . $q, '2550Q' => '12' . $y . 'Q' . $q, '2551Q' => '12' . $y . 'Q' . $q, '1601EQ' => $y . 'Q' . $q,
        ];
        $dir = $this->ebirDir();
        $index = $needed ? $this->fileIndex($dir) : ['_all' => []];

        $saved = DB::table('t_reconciliation_monitoring')->where('declared_month', $month)->pluck('client_id')
            ->flip()->all();

        $rows = [];
        foreach ($clients as $c) {
            $tin = $c->tin ?: 'N/A';
            $compliance = [];
            if ($validTin($c->tin)) {
                $tinKey = trim(str_replace('-', '', (string) $c->tin));
                $allowed = array_flip($idsOf($c->compliance_ids));
                foreach ($catalog as $cat) {
                    if (!isset($allowed[(string) $cat->id])) continue;
                    $code = (string) $cat->code;
                    $file = $this->findFile($index, $tinKey, $code, $periods[$code] ?? '');
                    $raw = $this->readFile($dir, $file);
                    $checked = $file !== '' && $this->isChecked($code, $raw, (int) $c->id, $fems);
                    $compliance[] = [
                        'id' => (int) $cat->id, 'code' => $code, 'file' => $file,
                        'state' => $file === '' ? 'missing' : ($checked ? 'checked' : 'unchecked'),
                    ];
                }
            }

            $hasTin = trim((string) $tin) !== '' && $tin !== 'N/A';
            $done = array_values(array_filter($compliance, fn ($x) => $x['state'] === 'checked'));
            $status = !$hasTin ? 'critical'
                : (!$compliance ? 'good' : (count($done) === count($compliance) ? 'excellent' : 'attention'));

            $rows[] = [
                'client_id'  => (int) $c->id,
                'client_num' => $c->client_num,
                'name'       => trim((string) $c->name),
                'tin'        => $tin,
                'status'     => $status,
                'score'      => $compliance ? (int) round(count($done) / count($compliance) * 100) : null,
                'compliance' => $compliance,
                'saved'      => isset($saved[(int) $c->id]),
            ];
        }

        return response()->json([
            'month'      => $month,
            'type'       => $d['type'],
            'clients'    => $rows,
            'files_dir'  => is_dir($dir) ? null : "eBIRForms folder not found on the server: {$dir}",
            'type_note'  => $typeCol ? null : 'm_client has no return_type column, so EBIR / EFPS is not filtered',
        ]);
    }

    /* ---------------- POST /monitoring/audit-reconciliation ---------------- */

    public function save(Request $request)
    {
        $d = $request->validate([
            'declared_month'           => ['required', 'date_format:Y-m'],
            'reconciled'               => ['required', 'array', 'min:1'],
            'reconciled.*.client_id'   => ['required', 'integer'],
            'reconciled.*.compliance'  => ['array'],
            'reconciled.*.compliance.*' => ['integer'],
        ], ['reconciled.required' => 'No reconciled clients to submit', 'reconciled.min' => 'No reconciled clients to submit']);

        $month = $d['declared_month'];
        $emp = $this->me($request);
        $cTable = $this->firstTable(['m_compliance', 'm_compliances']) ?? 'm_compliance';

        $clientIds = collect($d['reconciled'])->pluck('client_id')->unique()->all();
        $validClients = DB::table('m_client')->whereIn('id', $clientIds)->pluck('id')->flip();
        $validComp = DB::table($cTable)->whereIn('id', collect($d['reconciled'])->pluck('compliance')->flatten()->unique()->all())
            ->pluck('id')->flip();

        $saved = 0;
        $locked = 0;
        DB::transaction(function () use ($d, $month, $emp, $validClients, $validComp, &$saved, &$locked) {
            $now = now();
            foreach ($d['reconciled'] as $r) {
                $cid = (int) $r['client_id'];
                if (!isset($validClients[$cid])) continue;

                $row = DB::table('t_reconciliation_monitoring')->where('client_id', $cid)->where('declared_month', $month)
                    ->lockForUpdate()->first();
                if ($row && (int) $row->is_lock === 1) { $locked++; continue; }
                if ($row) {
                    DB::table('t_reconciliation_monitoring')->where('id', $row->id)->update(['updated_at' => $now]);
                } else {
                    DB::table('t_reconciliation_monitoring')->insert([
                        'client_id' => $cid, 'declared_month' => $month, 'is_lock' => 0, 'is_printed' => 0,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                $comp = collect($r['compliance'] ?? [])->map(fn ($x) => (int) $x)->unique()
                    ->filter(fn ($x) => isset($validComp[$x]))
                    ->map(fn ($x) => [
                        'compliance_id' => $x, 'client_id' => $cid, 'declared_month' => $month,
                        'emp_id' => $emp, 'created_at' => $now, 'updated_at' => $now,
                    ])->values()->all();
                if ($comp) DB::table('t_recon_compliance')->insertOrIgnore($comp);
                $saved++;
            }
        });

        $msg = "{$saved} reconciled client(s) saved";
        if ($locked) $msg .= ", {$locked} skipped (locked)";
        return response()->json(['message' => $msg, 'saved' => $saved, 'locked' => $locked]);
    }

    /* =====================================================================
       RECONCILIATION MONITORING (year grid)
       ===================================================================== */

    const AUDIT_SUPERVISOR = 10, AUDITOR = 5, DATA_ANALYST = 3;   // m_employee.designation_id
    const SUPERVISOR_USER_TYPES = [10];                             // old qry_user UserTypeID IN (10)
    const NO_DATA_COLOR = '#e8eaed';

    private function designation(Request $request): int
    {
        $emp = $this->me($request);
        return $emp ? (int) DB::table('m_employee')->where('id', $emp)->value('designation_id') : 0;
    }

    /** old tbl2_declared_month_settings: a row for CAID + month = that month is locked */
    private function settingsTable(): string
    {
        if (class_exists(\App\Models\DeclaredMonthSettings::class)) return (new \App\Models\DeclaredMonthSettings)->getTable();
        return $this->firstTable(['t_declared_month_settings', 'm_declared_month_settings', 'declared_month_settings']) ?? 't_declared_month_settings';
    }

    private function colorList()
    {
        return Schema::hasTable('m_recon_color_code')
            ? DB::table('m_recon_color_code')->orderBy('id')->get(['id', 'description', 'color_code'])
            : collect();
    }

    /** printed / colour per client + month, locked per account + month, for one year */
    private function yearFlags(int $year): array
    {
        $printed = [];
        foreach (DB::table('t_reconciliation_monitoring')->where('declared_month', 'like', $year . '-%')
                     ->get(['client_id', 'declared_month', 'is_printed', 'color_code_id']) as $r) {
            $printed[(int) $r->client_id][substr($r->declared_month, 5, 2)] = $r;
        }
        $locked = [];
        foreach (DB::table($this->settingsTable())->where('declared_month', 'like', $year . '-%')
                     ->get(['ca_id', 'declared_month']) as $r) {
            $locked[(int) $r->ca_id][substr($r->declared_month, 5, 2)] = true;
        }
        return [$printed, $locked];
    }

    private function monthsFor(int $clientId, int $caId, array $printed, array $locked): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $mm = sprintf('%02d', $m);
            $p = $printed[$clientId][$mm] ?? null;
            $out[] = [
                'locked'        => isset($locked[$caId][$mm]),
                'printed'       => $p ? (bool) $p->is_printed : false,
                'color_code_id' => $p && $p->color_code_id ? (int) $p->color_code_id : null,
            ];
        }
        return $out;
    }

    /* GET /monitoring/reconciliation?year=2026  (old doGetReconMonitoring) */
    public function monitoring(Request $request)
    {
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) $d['year'];
        $designation = $this->designation($request);
        $emp = $this->me($request);

        $compCol = $this->pick('m_client', ['compliance_ids', 'compliance_id']);
        $q = DB::table('m_client as A')
            ->join('m_client_account as B', 'B.client_num', '=', 'A.client_num')
            ->join('m_user_client as U', 'U.caid', '=', 'B.id')
            ->whereRaw($this->clientWhere('A'))
            ->groupBy('A.id', 'A.client_num', 'A.last_name', 'A.first_name', 'A.middle_name')
            ->orderBy('A.last_name')->orderBy('A.first_name')
            ->selectRaw("A.id, A.client_num, " . ($compCol ? "MAX(A.{$compCol})" : "''") . " AS compliance_ids,
                CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name) AS name,
                COUNT(DISTINCT B.id) AS count_acc,
                COALESCE(MAX(CASE WHEN B.branch_type = 'MAIN' THEN B.id END), MAX(B.id)) AS main_caid");

        // old: auditor sees his assigned accounts, data analyst hers, supervisor + others see all
        if ($designation === self::AUDITOR && $emp && $this->pick('m_client_account', ['assigned_auid'])) $q->where('B.assigned_auid', $emp);
        if ($designation === self::DATA_ANALYST && $emp && $this->pick('m_client_account', ['assigned_daid'])) $q->where('B.assigned_daid', $emp);

        [$printed, $locked] = $this->yearFlags($year);
        $rows = $q->get()->map(fn ($c) => [
            'client_id'      => (int) $c->id,
            'client_num'     => $c->client_num,
            'ca_id'          => (int) $c->main_caid,
            'name'           => trim((string) $c->name),
            'compliance_ids' => (string) $c->compliance_ids,
            'count_acc'      => (int) $c->count_acc,
            'months'         => $this->monthsFor((int) $c->id, (int) $c->main_caid, $printed, $locked),
        ])->values();

        $thisYear = (int) date('Y');
        return response()->json([
            'year'       => $year,
            'years'      => range($thisYear, $thisYear - 5),
            'colors'     => $this->colorList(),
            'can_status' => $designation === self::AUDIT_SUPERVISOR,
            'can_lock'   => $designation !== self::DATA_ANALYST,
            'rows'       => $rows,
        ]);
    }

    /* GET /monitoring/reconciliation/{clientNum}/accounts?year=  (old doGetReconMonitoringDtls: the non-MAIN branch accounts) */
    public function monitoringAccounts(Request $request, string $clientNum)
    {
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $client = DB::table('m_client')->where('client_num', $clientNum)->first(['id']);
        if (!$client) return response()->json(['message' => 'Client not found.'], 404);

        [$printed, $locked] = $this->yearFlags((int) $d['year']);
        $rows = DB::table('m_client_account as B')
            ->join('m_user_client as U', 'U.caid', '=', 'B.id')
            ->where('B.client_num', $clientNum)
            ->where(fn ($w) => $w->whereNull('B.branch_type')->orWhere('B.branch_type', '!=', 'MAIN'))
            ->groupBy('B.id', 'B.branch_code', 'B.trade_name')
            ->orderBy('B.branch_code')
            ->get(['B.id', DB::raw("TRIM(CONCAT_WS(' ', B.branch_code, B.trade_name)) AS name")])
            ->map(fn ($a) => [
                'ca_id'  => (int) $a->id,
                'name'   => $a->name,
                'months' => $this->monthsFor((int) $client->id, (int) $a->id, $printed, $locked),
            ])->values();

        return response()->json(['rows' => $rows]);
    }

    /** compliances due this month for the client (ComplianceIDs ∩ Terms) with ticked state (old doGetComplianceList) */
    private function dueCompliance(int $clientId, string $month)
    {
        $compCol = $this->pick('m_client', ['compliance_ids', 'compliance_id']);
        $cTable = $this->firstTable(['m_compliance', 'm_compliances']);
        $cName = $cTable ? $this->pick($cTable, ['compliance', 'name', 'description']) : null;
        $cTerms = $cTable ? $this->pick($cTable, ['terms', 'term']) : null;
        if (!$compCol || !$cTable || !$cName || !$cTerms) return collect();

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) DB::table('m_client')->where('id', $clientId)->value($compCol)))));
        if (!$ids) return collect();

        return DB::table("{$cTable} as A")
            ->leftJoin('t_recon_compliance as B', function ($j) use ($clientId, $month) {
                $j->on('B.compliance_id', '=', 'A.id')->where('B.client_id', $clientId)->where('B.declared_month', $month);
            })
            ->whereIn('A.id', $ids)
            ->whereRaw("FIND_IN_SET(?, REPLACE(A.{$cTerms}, '-', ',')) > 0", [(int) substr($month, 5, 2)])
            ->groupBy('A.id', "A.{$cName}")
            ->orderBy("A.{$cName}")
            ->get(['A.id', "A.{$cName} as code", DB::raw('COUNT(B.id) AS cnt')])
            ->map(fn ($c) => ['id' => (int) $c->id, 'code' => (string) $c->code, 'checked' => (int) $c->cnt > 0]);
    }

    /* GET /monitoring/reconciliation/compliance?client_id=&month=YYYY-MM */
    public function complianceList(Request $request)
    {
        $d = $request->validate(['client_id' => ['required', 'integer'], 'month' => ['required', 'date_format:Y-m']]);
        return response()->json(['items' => $this->dueCompliance((int) $d['client_id'], $d['month'])->values()]);
    }

    /**
     * STATUS colour after a tick (old ajax/doUpdateCompliance.php — not supplied, so this is the assumed rule):
     *   nothing ticked → no colour ("No data in the system") · every due compliance ticked → the "complete / reconciled" colour
     *   some ticked → the "partial / incomplete" colour. Colours are matched on m_recon_color_code.description.
     */
    private function statusColorId(int $clientId, string $month): ?int
    {
        $due = $this->dueCompliance($clientId, $month);
        $done = $due->where('checked', true)->count();
        if ($done === 0) return null;

        $find = function (callable $test) {
            foreach ($this->colorList() as $c) if ($test(strtolower((string) $c->description))) return (int) $c->id;
            return null;
        };
        $isNot = fn ($t) => str_contains($t, 'not') || str_contains($t, 'incomplete') || str_contains($t, 'partial') || str_contains($t, 'no ');
        if ($done >= $due->count()) {
            return $find(fn ($t) => !$isNot($t) && (str_contains($t, 'complete') || str_contains($t, 'reconciled') || str_contains($t, 'done')));
        }
        return $find(fn ($t) => str_contains($t, 'partial') || str_contains($t, 'incomplete') || str_contains($t, 'progress') || str_contains($t, 'ongoing'));
    }

    /* PUT /monitoring/reconciliation/compliance  { client_id, month, compliance_id }  — audit supervisor only */
    public function toggleCompliance(Request $request)
    {
        $d = $request->validate([
            'client_id'     => ['required', 'integer', 'exists:m_client,id'],
            'month'         => ['required', 'date_format:Y-m'],
            'compliance_id' => ['required', 'integer'],
        ]);
        if ($this->designation($request) !== self::AUDIT_SUPERVISOR) {
            return response()->json(['message' => 'Only the audit supervisor can change the status.'], 403);
        }
        $cid = (int) $d['client_id'];
        $month = $d['month'];
        if (!$this->dueCompliance($cid, $month)->contains('id', (int) $d['compliance_id'])) {
            return response()->json(['message' => 'This compliance is not due for the client this month.'], 422);
        }

        $emp = $this->me($request);
        $result = DB::transaction(function () use ($cid, $month, $d, $emp) {
            $now = now();
            $row = DB::table('t_recon_compliance')->where('client_id', $cid)->where('declared_month', $month)
                ->where('compliance_id', $d['compliance_id'])->lockForUpdate()->first();
            if ($row) {
                DB::table('t_recon_compliance')->where('id', $row->id)->delete();
            } else {
                DB::table('t_recon_compliance')->insert([
                    'compliance_id' => $d['compliance_id'], 'client_id' => $cid, 'declared_month' => $month,
                    'emp_id' => $emp, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $colorId = $this->statusColorId($cid, $month);
            $this->upsertMonitoring($cid, $month, ['color_code_id' => $colorId]);
            return ['checked' => !$row, 'color_code_id' => $colorId];
        });

        return response()->json($result);
    }

    private function upsertMonitoring(int $clientId, string $month, array $values): void
    {
        $now = now();
        $exists = DB::table('t_reconciliation_monitoring')->where('client_id', $clientId)->where('declared_month', $month)->exists();
        if ($exists) {
            DB::table('t_reconciliation_monitoring')->where('client_id', $clientId)->where('declared_month', $month)
                ->update($values + ['updated_at' => $now]);
        } else {
            DB::table('t_reconciliation_monitoring')->insert($values + [
                'client_id' => $clientId, 'declared_month' => $month, 'is_lock' => 0, 'is_printed' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function isLocked(int $caId, string $month): bool
    {
        return DB::table($this->settingsTable())->where('ca_id', $caId)->where('declared_month', $month)->exists();
    }

    /* PUT /monitoring/reconciliation/flag  { client_id, ca_id, month, field: locked|printed, value, username?, password? } */
    public function setFlag(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['required', 'integer', 'exists:m_client,id'],
            'ca_id'     => ['required', 'integer', 'exists:m_client_account,id'],
            'month'     => ['required', 'date_format:Y-m'],
            'field'     => ['required', 'in:locked,printed'],
            'value'     => ['required', 'boolean'],
            'username'  => ['nullable', 'string'],
            'password'  => ['nullable', 'string'],
        ]);
        $cid = (int) $d['client_id'];
        $caId = (int) $d['ca_id'];
        $month = $d['month'];
        $value = (bool) $d['value'];
        $label = date('F Y', strtotime($month . '-01'));

        if ($d['field'] === 'printed') {
            if ($value && !$this->isLocked($caId, $month)) {
                return response()->json(['message' => 'Please lock this month first before marking it as printed.'], 422);
            }
            $this->upsertMonitoring($cid, $month, ['is_printed' => $value ? 1 : 0]);
            return response()->json(['message' => $value ? "{$label} marked printed" : "{$label} unmarked printed"]);
        }

        // locked
        if ($this->designation($request) === self::DATA_ANALYST) {
            return response()->json(['message' => 'Data analysts cannot lock or unlock a month.'], 403);
        }
        $table = $this->settingsTable();
        $isLocked = $this->isLocked($caId, $month);

        if (!$value && $isLocked) {
            $u = ($d['username'] ?? null) && ($d['password'] ?? null)
                ? DB::table('m_user_staff')->where('username', $d['username'])->where('is_active', 1)
                    ->whereIn('user_type_id', self::SUPERVISOR_USER_TYPES)->first()
                : null;
            if (!$u || !Hash::check((string) $d['password'], (string) $u->password)) {
                return response()->json(['message' => ($d['username'] ?? null) ? 'User not found' : 'Supervisor override needed to unlock.',
                    'need_override' => true], 422);
            }
        }

        // the client's own row uses its MAIN account (or the highest account id when there is no MAIN) — same as the list
        $clientNum = DB::table('m_client')->where('id', $cid)->value('client_num');
        $mainCaid = (int) DB::table('m_client_account as B')->join('m_user_client as U', 'U.caid', '=', 'B.id')
            ->where('B.client_num', $clientNum)
            ->selectRaw("COALESCE(MAX(CASE WHEN B.branch_type = 'MAIN' THEN B.id END), MAX(B.id)) AS v")->value('v');
        $isMain = $mainCaid === $caId;
        $emp = $this->me($request);

        DB::transaction(function () use ($table, $caId, $month, $value, $isLocked, $cid, $isMain, $emp) {
            if ($value && !$isLocked) {
                $row = ['ca_id' => $caId, 'declared_month' => $month, 'created_at' => now(), 'updated_at' => now()];
                foreach (['added_by', 'emp_id', 'locked_by'] as $col) {
                    if ($emp && in_array($col, $this->cols($table), true)) $row[$col] = $emp;
                }
                DB::table($table)->insert(array_intersect_key($row, array_flip($this->cols($table))));
            }
            if (!$value && $isLocked) {
                DB::table($table)->where('ca_id', $caId)->where('declared_month', $month)->delete();
                // printed needs locked, so unlocking also clears printed for that account's client row
                if ($isMain) $this->upsertMonitoring($cid, $month, ['is_printed' => 0]);
            }
            if ($isMain) $this->upsertMonitoring($cid, $month, ['is_lock' => $value ? 1 : 0]);
        });

        return response()->json(['message' => $value ? "{$label} locked" : "{$label} unlocked"]);
    }

    /* =====================================================================
       AUDIT MONITORING (old pages/Monitoring/Audit_Monitoring.php)
       audits per client per day of a month — t_audit_history joined to the sales / purchase-expense invoices
       ===================================================================== */

    const AUDIT_LABELS = [0 => 'Pending', 1 => 'Confirmed', 2 => 'Audited', 3 => 'Reverted'];
    const AUDIT_SEE_ALL = [10, 4];   // designations that see every auditor's audits (old page: only 10)

    protected function employeeName(string $a = 'E'): string
    {
        return "TRIM(CONCAT_WS(' ', {$a}.last_name, {$a}.first_name, {$a}.middle_name))";
    }

    /** old: supervisor (10) sees all or the picked auditor, everybody else only his own audits */
    private function auditorFilter(Request $request, $auditorId): ?int
    {
        if (in_array($this->designation($request), self::AUDIT_SEE_ALL, true)) return (int) $auditorId > 0 ? (int) $auditorId : null;
        return $this->me($request) ?? -1;   // -1 = no employee → nothing
    }

    /* GET /monitoring/audit/lookups   client statuses + auditors (supervisor only) */
    public function auditLookups(Request $request)
    {
        $designation = $this->designation($request);
        $emp = $this->me($request);
        $sl = $this->pick('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']);

        $seeAll = in_array($designation, self::AUDIT_SEE_ALL, true);
        $auditors = [];
        if ($seeAll) {
            $company = $emp ? DB::table('m_employee')->where('id', $emp)->value('company_caid') : null;
            $active = $this->pick('m_employee', ['is_active', 'active']);
            $auditors = DB::table('m_employee as E')->where('E.designation_id', self::AUDITOR)
                ->when($active, fn ($q) => $q->where("E.{$active}", 1))
                ->when($company, fn ($q) => $q->where('E.company_caid', $company))
                ->orderBy('E.last_name')->get(['E.id', DB::raw($this->employeeName() . ' AS name')]);
        }

        return response()->json([
            'statuses'       => $sl ? DB::table('m_client_account_status')->orderBy($sl)->get(['id', "{$sl} as name"]) : [],
            'auditors'       => $auditors,
            'can_pick_auditor' => $seeAll,
            'month'          => date('Y-m'),
        ]);
    }

    /** clients with at least one login: client_num → { name, status, user ids } (old step 1 of doGetScanMonitoring) */
    private function auditClients(?int $statusId, ?string $clientNum = null): array
    {
        $sl = $this->pick('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']);
        $active = $this->pick('m_client', ['active', 'is_active']);
        $q = DB::table('m_client as A')
            ->join('m_client_account as B', 'B.client_num', '=', 'A.client_num')
            ->join('m_user_client as U', 'U.caid', '=', 'B.id')
            ->when($sl, fn ($q) => $q->leftJoin('m_client_account_status as S', 'S.id', '=', 'A.status_id'))
            ->when($active, fn ($q) => $q->where("A.{$active}", 1))
            ->when($statusId, fn ($q) => $q->where('A.status_id', $statusId))
            ->when($clientNum, fn ($q) => $q->where('A.client_num', $clientNum))
            ->groupBy('A.client_num')
            ->orderByRaw('MAX(A.last_name)')
            ->selectRaw("A.client_num, MAX(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name)) AS name,
                " . ($sl ? "MAX(S.{$sl})" : "''") . " AS status, GROUP_CONCAT(DISTINCT U.id) AS user_ids");

        $out = [];
        foreach ($q->get() as $c) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $c->user_ids))));
            if ($ids) $out[$c->client_num] = ['name' => trim($c->name), 'status' => (string) $c->status, 'user_ids' => $ids];
        }
        return $out;
    }

    /* GET /monitoring/audit?month=YYYY-MM&status_id=&auditor_id=   (old doGetScanMonitoring) */
    public function audit(Request $request)
    {
        $d = $request->validate([
            'month'      => ['required', 'date_format:Y-m'],
            'status_id'  => ['nullable', 'integer'],
            'auditor_id' => ['nullable', 'integer'],
        ]);
        $auditor = $this->auditorFilter($request, $d['auditor_id'] ?? 0);

        $start = new DateTime($d['month'] . '-01');
        $end = (clone $start)->modify('last day of this month');
        $days = [];
        for ($c = clone $start; $c <= $end; $c->modify('+1 day')) $days[] = $c->format('Y-m-d');

        // why a month can come back empty: these counts go back with the rows
        $range = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];
        $info = [
            'designation'     => $this->designation($request),
            'employee_id'     => $this->me($request),
            'auditor_filter'  => $auditor,
            'audits_in_month' => DB::table('t_audit_history')->whereBetween('created_at', $range)->count(),
            'audits_for_auditor' => $auditor === null ? null
                : DB::table('t_audit_history')->whereBetween('created_at', $range)->where('auditor_id', $auditor)->count(),
        ];

        $clients = $this->auditClients((int) ($d['status_id'] ?? 0) ?: null);
        $userIds = collect($clients)->pluck('user_ids')->flatten()->unique()->values()->all();
        if (!$userIds) return response()->json(['month' => $d['month'], 'days' => $days, 'rows' => [], 'info' => $info]);

        $in = implode(',', array_fill(0, count($userIds), '?'));
        $aud = $auditor !== null ? 'AND A.auditor_id = ?' : '';
        $name = $this->employeeName('B');
        $name2 = $this->employeeName('C');
        $part = fn (string $table) => "
            SELECT D.added_by AS uid, DATE(A.created_at) AS day, COUNT(A.id) AS cnt,
                   GROUP_CONCAT(DISTINCT {$name}) AS da, GROUP_CONCAT(DISTINCT {$name2}) AS au
            FROM t_audit_history A
            INNER JOIN {$table} D ON D.invoice_no = A.reference_no
            LEFT JOIN m_employee B ON B.id = D.assigned_daid
            LEFT JOIN m_employee C ON C.id = D.assigned_auid
            WHERE D.added_by IN ({$in}) AND A.created_at BETWEEN ? AND ? {$aud}
            GROUP BY D.added_by, DATE(A.created_at)";
        $b = [...$userIds, $start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59'), ...($auditor !== null ? [$auditor] : [])];
        $rows = DB::select($part('t_sales_invoice_master') . ' UNION ALL ' . $part('t_purchase_expense_master'), [...$b, ...$b]);

        $byUser = [];   // uid → { days: {date: n}, da: [], au: [] }
        foreach ($rows as $r) {
            $u = (int) $r->uid;
            $byUser[$u]['days'][$r->day] = ($byUser[$u]['days'][$r->day] ?? 0) + (int) $r->cnt;
            foreach (['da', 'au'] as $k) {
                foreach (array_filter(array_map('trim', explode(',', (string) $r->$k))) as $n) $byUser[$u][$k][$n] = true;
            }
        }

        $out = [];
        foreach ($clients as $num => $c) {
            $perDay = [];
            $da = [];
            $au = [];
            foreach ($c['user_ids'] as $u) {
                foreach ($byUser[$u]['days'] ?? [] as $day => $n) $perDay[$day] = ($perDay[$day] ?? 0) + $n;
                $da += $byUser[$u]['da'] ?? [];
                $au += $byUser[$u]['au'] ?? [];
            }
            $total = array_sum($perDay);
            if ($total <= 0) continue;                                   // old: only clients with audits
            $out[] = [
                'client_num' => $num, 'name' => $c['name'], 'status' => $c['status'],
                'days' => (object) $perDay, 'total' => $total,
                'da' => array_keys($da), 'auditors' => array_keys($au),
            ];
        }

        return response()->json(['month' => $d['month'], 'days' => $days, 'rows' => $out, 'info' => $info]);
    }

    /* GET /monitoring/audit/detail?date=YYYY-MM-DD&client_num=&auditor_id=   (old doGetScanMonitoring_Detail) */
    public function auditDetail(Request $request)
    {
        $d = $request->validate([
            'date'       => ['required', 'date_format:Y-m-d'],
            'client_num' => ['required', 'string'],
            'auditor_id' => ['nullable', 'integer'],
        ]);
        $auditor = $this->auditorFilter($request, $d['auditor_id'] ?? 0);

        // trade names of the client → their login ids
        $map = [];
        foreach (DB::table('m_client_account as B')->join('m_user_client as U', 'U.caid', '=', 'B.id')
                     ->where('B.client_num', $d['client_num'])->orderBy('B.branch_code')
                     ->get([DB::raw("CONCAT_WS(' : ', B.branch_code, B.trade_name) AS trade"), 'U.id']) as $r) {
            $map[$r->trade][] = (int) $r->id;
        }
        if (!$map) return response()->json(['rows' => []]);

        $userIds = collect($map)->flatten()->unique()->values()->all();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $aud = $auditor !== null ? 'AND A.auditor_id = ?' : '';
        $b = [...$userIds, $d['date'], ...($auditor !== null ? [$auditor] : [])];

        $rows = DB::select("
            SELECT B.added_by AS uid, LOWER(TRIM(B.trans_type)) AS type, A.audit_status_id AS st, COUNT(A.id) AS cnt
            FROM t_audit_history A
            INNER JOIN t_purchase_expense_master B ON B.invoice_no = A.reference_no
            WHERE B.added_by IN ({$in}) AND DATE(A.created_at) = ? {$aud}
            GROUP BY B.added_by, LOWER(TRIM(B.trans_type)), A.audit_status_id
            UNION ALL
            SELECT B.added_by AS uid, 'sales' AS type, A.audit_status_id AS st, COUNT(A.id) AS cnt
            FROM t_audit_history A
            INNER JOIN t_sales_invoice_master B ON B.invoice_no = A.reference_no
            WHERE B.added_by IN ({$in}) AND DATE(A.created_at) = ? {$aud}
            GROUP BY B.added_by, A.audit_status_id", [...$b, ...$b]);

        $raw = [];   // uid → type → status → count
        foreach ($rows as $r) {
            $raw[(int) $r->uid][$r->type][(int) $r->st] = ($raw[(int) $r->uid][$r->type][(int) $r->st] ?? 0) + (int) $r->cnt;
        }

        $out = [];
        foreach ($map as $trade => $users) {
            $row = ['trade_name' => $trade];
            foreach (['sales', 'purchases', 'expenses'] as $type) {
                $byStatus = [];
                foreach ($users as $u) {
                    foreach ($raw[$u][$type] ?? [] as $st => $n) $byStatus[$st] = ($byStatus[$st] ?? 0) + $n;
                }
                $row[$type] = array_sum(array_intersect_key($byStatus, array_flip([0, 1, 2, 3, 4])));
                $row["{$type}_status"] = [];
                foreach (self::AUDIT_LABELS as $id => $label) {
                    if (isset($byStatus[$id])) $row["{$type}_status"][] = ['status' => $label, 'count' => $byStatus[$id]];
                }
            }
            $out[] = $row;
        }
        return response()->json(['rows' => $out]);
    }

    /* =====================================================================
       EFFICIENCY / REVERTED MONITORING (old pages/Monitoring/Efficiency_Monitoring.php)
       reverted invoices (audit_status_id = 3) per data analyst per day of a month
       ===================================================================== */

    const REVERTED = 3;

    /* GET /monitoring/efficiency?month=YYYY-MM   (old doGetRevertedMonitoring) */
    public function efficiency(Request $request)
    {
        $d = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $start = new DateTime($d['month'] . '-01');
        $end = (clone $start)->modify('last day of this month');
        $days = [];
        for ($c = clone $start; $c <= $end; $c->modify('+1 day')) $days[] = $c->format('Y-m-d');
        $range = [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')];

        // data analysts (old: tbl_employee Active = 1 AND DesignationID = 3)
        $active = $this->pick('m_employee', ['is_active', 'active']);
        $analysts = DB::table('m_employee as E')->where('E.designation_id', self::DATA_ANALYST)
            ->when($active, fn ($q) => $q->where("E.{$active}", 1))
            ->orderBy('E.last_name')->get(['E.id', DB::raw($this->employeeName() . ' AS name')]);
        if ($analysts->isEmpty()) return response()->json(['month' => $d['month'], 'days' => $days, 'rows' => []]);

        $ids = $analysts->pluck('id')->map(fn ($v) => (int) $v)->all();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $au = $this->employeeName('C');
        $part = fn (string $table) => "
            SELECT A.assigned_daid AS da, DATE(A.created_at) AS day, COUNT(A.id) AS cnt, GROUP_CONCAT(DISTINCT {$au}) AS au
            FROM {$table} A
            LEFT JOIN m_employee C ON C.id = A.assigned_auid
            WHERE A.audit_status_id = " . self::REVERTED . " AND A.created_at BETWEEN ? AND ? AND A.assigned_daid IN ({$in})
            GROUP BY A.assigned_daid, DATE(A.created_at)";
        $b = [...$range, ...$ids];
        $rows = DB::select($part('t_sales_invoice_master') . ' UNION ALL ' . $part('t_purchase_expense_master'), [...$b, ...$b]);

        $byDa = [];   // da → { days: {date: n}, au: {name: true} }
        foreach ($rows as $r) {
            $k = (int) $r->da;
            $byDa[$k]['days'][$r->day] = ($byDa[$k]['days'][$r->day] ?? 0) + (int) $r->cnt;
            foreach (array_filter(array_map('trim', explode(',', (string) $r->au))) as $n) $byDa[$k]['au'][$n] = true;
        }

        $out = [];
        foreach ($analysts as $a) {
            $perDay = $byDa[(int) $a->id]['days'] ?? [];
            $total = array_sum($perDay);
            if ($total <= 0) continue;                                   // old: only analysts with reverted invoices
            $out[] = [
                'emp_id' => (int) $a->id, 'name' => trim($a->name), 'days' => (object) $perDay, 'total' => $total,
                'auditors' => array_keys($byDa[(int) $a->id]['au'] ?? []),
            ];
        }
        return response()->json(['month' => $d['month'], 'days' => $days, 'rows' => $out]);
    }

    /* GET /monitoring/efficiency/detail?emp_id=&date=YYYY-MM-DD   (old doGetRevertedMonitoring_Detail) */
    public function efficiencyDetail(Request $request)
    {
        $d = $request->validate(['emp_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        $range = ["{$d['date']} 00:00:00", "{$d['date']} 23:59:59"];
        $part = fn (string $table, string $type) => "
            SELECT A.invoice_no AS ref_no, MAX({$type}) AS type, A.created_at, A.declared_month, A.document_no,
                   MAX(E.branch_code) AS branch_code, MAX(E.trade_name) AS trade_name,
                   GROUP_CONCAT(F.remarks ORDER BY F.id SEPARATOR '~') AS remarks
            FROM {$table} A
            LEFT JOIN m_user_client D ON D.id = A.added_by
            LEFT JOIN m_client_account E ON E.id = D.caid
            LEFT JOIN t_audit_history F ON F.reference_no = A.invoice_no AND F.remarks IS NOT NULL AND F.remarks != ''
            WHERE A.audit_status_id = " . self::REVERTED . " AND A.created_at BETWEEN ? AND ? AND A.assigned_daid = ?
            GROUP BY A.id, A.invoice_no, A.created_at, A.declared_month, A.document_no";
        $b = [...$range, (int) $d['emp_id']];
        $rows = DB::select($part('t_sales_invoice_master', "'Sales'") . ' UNION ALL '
            . $part('t_purchase_expense_master', 'A.trans_type') . ' ORDER BY created_at', [...$b, ...$b]);

        return response()->json(['rows' => collect($rows)->map(fn ($r) => [
            'branch_code'    => $r->branch_code,
            'trade_name'     => $r->trade_name,
            'declared_month' => $r->declared_month,
            'ref_no'         => $r->ref_no,
            'document_no'    => $r->document_no,
            'type'           => $r->type,
            'remarks'        => array_values(array_filter(array_map('trim', explode('~', (string) $r->remarks)))),
        ])]);
    }

    /* =====================================================================
       SCAN MONITORING (old pages/Monitoring/Scan_Monitoring.php)
       scanned receipts (t_mobile_transaction) per client — or per data analyst — per day
       ===================================================================== */

    const SCAN_SALES_LABELS = [0 => 'Pending', 1 => 'Confirmed', 2 => 'Invalid', 3 => 'Partially Invalid', 4 => 'For Collection', 5 => 'Audit Pending', 6 => 'Audited', 7 => 'Reverted'];
    const SCAN_OTHER_LABELS = [0 => 'Pending', 1 => 'Confirmed', 2 => 'Invalid', 3 => 'Partially Invalid', 4 => 'For Payment', 5 => 'Audit Pending', 6 => 'Audited', 7 => 'Reverted'];

    /** from / to months → every day + the datetime range */
    private function scanRange(string $from, string $to): array
    {
        if ($to < $from) [$from, $to] = [$to, $from];
        $start = new DateTime($from . '-01');
        $end = (new DateTime($to . '-01'))->modify('last day of this month');
        $days = [];
        for ($c = clone $start; $c <= $end; $c->modify('+1 day')) $days[] = $c->format('Y-m-d');
        return [$days, [$start->format('Y-m-d 00:00:00'), $end->format('Y-m-d 23:59:59')]];
    }

    /** scans per login per day + DA / auditor names (old "Sales Query" on tbl2_mobile_transaction) */
    private function scanCounts(array $userIds, array $range): array
    {
        if (!$userIds) return [];
        $emp = $this->pick('t_mobile_transaction', ['employee_id', 'emp_id']);
        $aud = $this->pick('t_mobile_transaction', ['assigned_auid', 'assigned_auditor_id']);
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $rows = DB::select("
            SELECT A.added_by AS uid, DATE(A.created_at) AS day, COUNT(A.id) AS cnt,
                   " . ($emp ? "GROUP_CONCAT(DISTINCT {$this->employeeName('B')})" : 'NULL') . " AS da,
                   " . ($aud ? "GROUP_CONCAT(DISTINCT {$this->employeeName('C')})" : 'NULL') . " AS au
            FROM t_mobile_transaction A
            " . ($emp ? "LEFT JOIN m_employee B ON B.id = A.{$emp}" : '') . "
            " . ($aud ? "LEFT JOIN m_employee C ON C.id = A.{$aud}" : '') . "
            WHERE A.added_by IN ({$in}) AND A.created_at BETWEEN ? AND ?
            GROUP BY A.added_by, DATE(A.created_at)", [...$userIds, ...$range]);

        $by = [];
        foreach ($rows as $r) {
            $u = (int) $r->uid;
            $by[$u]['days'][$r->day] = ($by[$u]['days'][$r->day] ?? 0) + (int) $r->cnt;
            foreach (['da', 'au'] as $k) {
                foreach (array_filter(array_map('trim', explode(',', (string) $r->$k))) as $n) $by[$u][$k][$n] = true;
            }
        }
        return $by;
    }

    /** sum the per-login counts into one row */
    private function scanRow(array $userIds, array $by): array
    {
        $perDay = [];
        $da = [];
        $au = [];
        foreach ($userIds as $u) {
            foreach ($by[$u]['days'] ?? [] as $day => $n) $perDay[$day] = ($perDay[$day] ?? 0) + $n;
            $da += $by[$u]['da'] ?? [];
            $au += $by[$u]['au'] ?? [];
        }
        return ['days' => (object) $perDay, 'total' => array_sum($perDay), 'da' => array_keys($da), 'auditors' => array_keys($au)];
    }

    /** data analysts of the logged-in company (old: DesignationID = 3, Active, CompanyCAID) */
    private function companyAnalysts(Request $request)
    {
        $emp = $this->me($request);
        $company = $emp ? DB::table('m_employee')->where('id', $emp)->value('company_caid') : null;
        $active = $this->pick('m_employee', ['is_active', 'active']);
        $q = fn (bool $byCompany) => DB::table('m_employee as E')->where('E.designation_id', self::DATA_ANALYST)
            ->when($active, fn ($q) => $q->where("E.{$active}", 1))
            ->when($byCompany && $company, fn ($q) => $q->where('E.company_caid', $company))
            ->orderBy('E.last_name')->get(['E.id', DB::raw($this->employeeName() . ' AS name')]);
        $list = $q(true);
        return $list->isEmpty() && $company ? $q(false) : $list;   // nobody in my company → every active analyst
    }

    /* GET /monitoring/scan/lookups   client statuses + data analysts */
    public function scanLookups(Request $request)
    {
        $sl = $this->pick('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']);
        return response()->json([
            'statuses' => $sl ? DB::table('m_client_account_status')->orderBy($sl)->get(['id', "{$sl} as name"]) : [],
            'analysts' => $this->companyAnalysts($request),
            'month'    => date('Y-m'),
        ]);
    }

    /* GET /monitoring/scan?from=YYYY-MM&to=YYYY-MM&status_id=&da_id=   BY CLIENT (old doGetScanMonitoring) */
    public function scanByClient(Request $request)
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m'],
            'status_id' => ['nullable', 'integer'], 'da_id' => ['nullable', 'integer'],
        ]);
        [$days, $range] = $this->scanRange($d['from'], $d['to']);

        $sl = $this->pick('m_client_account_status', ['status_name', 'client_acc_status', 'description', 'name']);
        $active = $this->pick('m_client', ['active', 'is_active']);
        $clients = DB::table('m_client as A')
            ->join('m_client_account as B', 'B.client_num', '=', 'A.client_num')
            ->join('m_user_client as U', 'U.caid', '=', 'B.id')
            ->when($sl, fn ($q) => $q->leftJoin('m_client_account_status as S', 'S.id', '=', 'A.status_id'))
            ->when($active, fn ($q) => $q->where("A.{$active}", 1))
            ->when((int) ($d['status_id'] ?? 0) > 0, fn ($q) => $q->where('A.status_id', (int) $d['status_id']))
            ->when((int) ($d['da_id'] ?? 0) > 0, fn ($q) => $q->where('B.assigned_daid', (int) $d['da_id']))
            ->groupBy('A.client_num')
            ->orderByRaw('MAX(A.last_name)')
            ->get([
                'A.client_num',
                DB::raw("MAX(CONCAT_WS(' ', A.last_name, A.first_name, A.middle_name)) AS name"),
                DB::raw(($sl ? "MAX(S.{$sl})" : "''") . ' AS status'),
                DB::raw('GROUP_CONCAT(DISTINCT U.id) AS user_ids'),
            ]);

        $ids = fn ($c) => array_values(array_filter(array_map('intval', explode(',', (string) $c->user_ids))));
        $by = $this->scanCounts($clients->flatMap($ids)->unique()->values()->all(), $range);

        $rows = $clients->map(fn ($c) => ['client_num' => $c->client_num, 'name' => trim($c->name), 'status' => (string) $c->status]
            + $this->scanRow($ids($c), $by))->values();

        return response()->json(['from' => $d['from'], 'to' => $d['to'], 'days' => $days, 'rows' => $rows]);
    }

    /* GET /monitoring/scan/by-da?from=YYYY-MM&to=YYYY-MM   BY DA (old doGetScanMonitoringDA) */
    public function scanByDa(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m'], 'to' => ['required', 'date_format:Y-m']]);
        [$days, $range] = $this->scanRange($d['from'], $d['to']);

        $analysts = $this->companyAnalysts($request);
        $users = [];   // da → login ids of the accounts assigned to him/her
        if ($analysts->isNotEmpty()) {
            foreach (DB::table('m_client_account as B')->join('m_user_client as U', 'U.caid', '=', 'B.id')
                         ->whereIn('B.assigned_daid', $analysts->pluck('id'))->get(['B.assigned_daid', 'U.id']) as $r) {
                $users[(int) $r->assigned_daid][] = (int) $r->id;
            }
        }
        $by = $this->scanCounts(collect($users)->flatten()->unique()->values()->all(), $range);

        $rows = $analysts->filter(fn ($a) => !empty($users[(int) $a->id]))      // old: skip analysts without accounts
            ->map(fn ($a) => ['emp_id' => (int) $a->id, 'name' => trim($a->name)] + $this->scanRow($users[(int) $a->id], $by))
            ->values();

        $info = [
            'analysts'               => $analysts->count(),
            'analysts_with_accounts' => count($users),
            'accounts_with_da'       => DB::table('m_client_account')->whereNotNull('assigned_daid')->where('assigned_daid', '>', 0)->count(),
        ];
        return response()->json(['from' => $d['from'], 'to' => $d['to'], 'days' => $days, 'rows' => $rows, 'info' => $info]);
    }

    /* GET /monitoring/scan/detail?date=YYYY-MM-DD&client_num=   or   &da_id=   (old doGetScanMonitoring_Detail / _DetailDA) */
    public function scanDetail(Request $request)
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'client_num' => ['nullable', 'string', 'required_without:da_id'],
            'da_id' => ['nullable', 'integer', 'required_without:client_num'],
        ]);

        // the clients to show: one client, or every client with an account assigned to the DA (old doGetClientNum)
        $clientNums = !empty($d['client_num'])
            ? [$d['client_num']]
            : DB::table('m_client_account')->where('assigned_daid', (int) $d['da_id'])->distinct()->pluck('client_num')->all();
        if (!$clientNums) return response()->json(['rows' => []]);

        $active = $this->pick('m_client', ['active', 'is_active']);
        $map = [];   // trade name → { client_num, users }
        foreach (DB::table('m_client as A')
                     ->join('m_client_account as B', 'B.client_num', '=', 'A.client_num')
                     ->join('m_user_client as U', 'U.caid', '=', 'B.id')
                     ->when($active, fn ($q) => $q->where("A.{$active}", 1))
                     ->whereIn('A.client_num', $clientNums)->orderBy('B.branch_code')
                     ->get(['A.client_num', DB::raw("CONCAT_WS(' : ', B.branch_code, B.trade_name) AS trade"), 'U.id']) as $r) {
            $map[$r->trade]['client_num'] = $r->client_num;
            $map[$r->trade]['users'][] = (int) $r->id;
        }
        if (!$map) return response()->json(['rows' => []]);

        $userIds = collect($map)->pluck('users')->flatten()->unique()->values()->all();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $audit = "CASE WHEN B.audit_status_id = 1 THEN 5 WHEN B.audit_status_id = 2 THEN 6 ELSE 7 END";
        $b = [...$userIds, $d['date']];
        $rows = DB::select("
            SELECT added_by AS uid, LOWER(TRIM(trans_type)) AS type, trans_status AS st, COUNT(id) AS cnt
            FROM t_mobile_transaction
            WHERE added_by IN ({$in}) AND DATE(created_at) = ?
            GROUP BY added_by, LOWER(TRIM(trans_type)), trans_status
            UNION ALL
            SELECT A.added_by, LOWER(TRIM(A.trans_type)), {$audit}, COUNT(A.id)
            FROM t_mobile_transaction A
            INNER JOIN t_purchase_expense_master B ON B.invoice_no = A.reference_no
            WHERE A.added_by IN ({$in}) AND DATE(A.created_at) = ?
            GROUP BY A.added_by, LOWER(TRIM(A.trans_type)), {$audit}
            UNION ALL
            SELECT A.added_by, LOWER(TRIM(A.trans_type)), {$audit}, COUNT(A.id)
            FROM t_mobile_transaction A
            INNER JOIN t_sales_invoice_master B ON B.invoice_no = A.reference_no
            WHERE A.added_by IN ({$in}) AND DATE(A.created_at) = ?
            GROUP BY A.added_by, LOWER(TRIM(A.trans_type)), {$audit}", [...$b, ...$b, ...$b]);

        $raw = [];   // uid → type → status → count
        foreach ($rows as $r) {
            $raw[(int) $r->uid][$r->type][(int) $r->st] = ($raw[(int) $r->uid][$r->type][(int) $r->st] ?? 0) + (int) $r->cnt;
        }

        $out = [];
        foreach ($map as $trade => $m) {
            $row = ['client_num' => $m['client_num'], 'trade_name' => $trade];
            foreach (['sales', 'purchases', 'expenses'] as $type) {
                $byStatus = [];
                foreach ($m['users'] as $u) {
                    foreach ($raw[$u][$type] ?? [] as $st => $n) $byStatus[$st] = ($byStatus[$st] ?? 0) + $n;
                }
                $row[$type] = array_sum(array_intersect_key($byStatus, array_flip([0, 1, 2, 3, 4])));
                $row["{$type}_status"] = [];
                foreach ($type === 'sales' ? self::SCAN_SALES_LABELS : self::SCAN_OTHER_LABELS as $id => $label) {
                    if (isset($byStatus[$id])) $row["{$type}_status"][] = ['status' => $label, 'count' => $byStatus[$id]];
                }
            }
            $out[] = $row;
        }
        return response()->json(['rows' => $out]);
    }

    /* =====================================================================
       COLLECTION MONITORING (old pages/Monitoring/Collection_Monitoring_PF_And_Return.php)
       payments (t_payment) by payment date — PF / Return / EO — paged on the server, Excel-style column filters
       ===================================================================== */

    // service type of the paid service → group (same groups as Billing); col type = fallback when the payment has no service
    const COLLECTION_TYPES = [
        'PF'     => ['ser_types' => [4, 5, 6, 7], 'col_type' => 1],
        'Return' => ['ser_types' => [3],          'col_type' => 2],
        'EO'     => ['ser_types' => [2],          'col_type' => 4],
    ];

    /** m_service_list ids per type group (small lookup, so the main query needs no join to m_service_list) */
    protected function collectionListIds(array $serTypes): array
    {
        static $cache = [];
        $key = implode(',', $serTypes);
        return $cache[$key] ??= DB::table('m_service_list')->whereIn('ser_type_id', $serTypes)->pluck('id')->all() ?: [0];
    }

    /**
     * column key → [SQL expression, tables it needs]
     * S = t_service · L = m_service_list · T = t_transaction · COL = m_payment_col_type · A = m_client_account · C = m_client
     */
    private function collectionCols(): array
    {
        $col = fn (array $names) => $this->pick('t_payment', $names);
        $d1 = $col(['details1', 'payment_details', 'details']);
        $d2 = $col(['details2']);
        $or = $col(['or_number']);
        $ar = $col(['ar_number']);
        $rm = $col(['remarks']);
        $dep = $col(['is_deposited', 'deposited']);
        $sp = $this->pick('t_service', ['service_period']) ? 'S.service_period' : 'NULL';
        $returnIds = implode(',', $this->collectionListIds(self::COLLECTION_TYPES['Return']['ser_types']));
        $details = array_filter([$d1 ? "P.{$d1}" : null, $d2 ? "P.{$d2}" : null]);
        $less = $this->collectionLessSql();
 
        return [
            'client'    => ["COALESCE(CONCAT_WS(' ', C.last_name, C.first_name, C.middle_name), '')", ['A', 'C']],
            'trade'     => ["COALESCE(CONCAT_WS(' ', A.branch_code, A.trade_name), '')", ['A']],
            'date'      => ["COALESCE(DATE_FORMAT(P.payment_date, '%b %d %Y'), '')", []],
            'period'    => ["COALESCE({$sp}, T.service_period, 'N/A')", ['S', 'T']],
            'amount'    => ["FORMAT(COALESCE(P.amount, 0), 2)", []],
            'less'      => ["FORMAT({$less}, 2)", ['S']],
            'col_type'  => ["COALESCE(COL.name, '')", ['COL']],
            'service'   => ["COALESCE(L.name, '')", ['S', 'L']],
            'details'   => [$details ? "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $details) . ")), ''), 'N/A')" : "'N/A'", []],
            'or_number' => [$or ? "COALESCE(NULLIF(P.{$or}, ''), 'N/A')" : "'N/A'", []],
            'ar_number' => [$ar ? "COALESCE(NULLIF(P.{$ar}, ''), 'N/A')" : "'N/A'", []],
            'remarks'   => [$rm ? "COALESCE(NULLIF(P.{$rm}, ''), 'N/A')" : "'N/A'", []],
            'deposited' => [$dep ? "CASE WHEN P.{$dep} = 1 THEN 'YES' ELSE 'NO' END" : "'NO'", []],
            'status'    => ["CASE WHEN S.ser_list_id IN ({$returnIds}) THEN CASE WHEN S.is_paid = 1 THEN 'PAID' ELSE 'UNPAID' END ELSE 'N/A' END", ['S']],
        ];
    }

    /** add only the joins the query needs */
    private function collectionJoin($q, array $need): void
    {
        $need = array_flip($need);
        if (isset($need['T'])) $need['S'] = true;
        if (isset($need['L'])) $need['S'] = true;
        if (isset($need['C'])) $need['A'] = true;
        if (isset($need['S'])) $q->leftJoin('t_service as S', 'S.id', '=', 'P.service_id');
        if (isset($need['L'])) $q->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id');
        if (isset($need['T'])) $q->leftJoin('t_transaction as T', 'T.trans_number', '=', 'P.trans_number');
        if (isset($need['COL'])) $q->leftJoin('m_payment_col_type as COL', 'COL.id', '=', 'P.payment_col_type_id');
        if (isset($need['A'])) $q->leftJoin('m_client_account as A', 'A.id', '=', 'P.ca_id');
        if (isset($need['C'])) $q->leftJoin('m_client as C', 'C.client_num', '=', 'A.client_num');
    }

    /**
     * payments in the date range, of the type / service, matching the column filters (except $skip).
     * Fast: payment_date is compared as a plain range (index), the type uses ser_list_id lists, and
     * only the tables a filter needs are joined.
     */
    private function collectionBase(array $d, ?string $skip = null, array $alsoNeed = [])
    {
        $cols = $this->collectionCols();
        $filters = array_filter((array) ($d['filters'] ?? []), fn ($v, $k) => $k !== $skip && isset($cols[$k]) && is_array($v), ARRAY_FILTER_USE_BOTH);

        $need = ['S', ...$alsoNeed];
        foreach (array_keys($filters) as $k) $need = [...$need, ...$cols[$k][1]];

        $q = DB::table('t_payment as P')
            ->whereBetween('P.payment_date', [$d['from'] . ' 00:00:00', $d['to'] . ' 23:59:59']);
        $this->collectionJoin($q, array_unique($need));

        $types = ($d['type'] ?? 'All') === 'All' ? array_keys(self::COLLECTION_TYPES) : [$d['type']];
        $q->where(function ($w) use ($types) {
            foreach ($types as $t) {
                $g = self::COLLECTION_TYPES[$t];
                $w->orWhereIn('S.ser_list_id', $this->collectionListIds($g['ser_types']))
                  ->orWhere(fn ($x) => $x->whereNull('S.id')->where('P.payment_col_type_id', $g['col_type']));
            }
        });
        if ((int) ($d['service_id'] ?? 0) > 0) $q->where('S.ser_list_id', (int) $d['service_id']);

        foreach ($filters as $key => $values) {
            $values = array_values(array_map('strval', $values));
            $values ? $q->whereIn(DB::raw($cols[$key][0]), $values) : $q->whereRaw('1 = 0');
        }
        return $q;
    }

    private function collectionRules(): array
    {
        return [
            'from'       => ['required', 'date_format:Y-m-d'],
            'to'         => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type'       => ['nullable', 'in:All,PF,Return,EO'],
            'service_id' => ['nullable', 'integer'],
            'filters'    => ['nullable', 'array'],     // { column key: [values…] }
        ];
    }

    /** filters may come as JSON text in the query string */
    private function collectionInput(Request $request): array
    {
        $in = $request->all();
        if (isset($in['filters']) && is_string($in['filters'])) $in['filters'] = json_decode($in['filters'], true) ?: [];
        return validator($in, $this->collectionRules() + ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'col' => ['nullable', 'string']])->validate();
    }

    /* GET /monitoring/collection/lookups   services */
    public function collectionLookups()
    {
        return response()->json([
            'services' => DB::table('m_service_list')->where('name', '!=', '')->orderBy('name')->get(['id', 'name']),
            'from'     => date('Y-m-01', strtotime('-2 months')),
            'to'       => date('Y-m-d'),
        ]);
    }

    /* GET /monitoring/collection?from=&to=&type=All|PF|Return|EO&service_id=&filters={json}&page=&per_page=   (old doGetPFAndReturn) */
    public function collectionMonitoring(Request $request)
    {
        $d = $this->collectionInput($request);
        $cols = $this->collectionCols();
 
        // 1) count + total in ONE query
        $agg = $this->collectionBase($d)->selectRaw('COUNT(*) AS c, COALESCE(SUM(P.amount), 0) AS s')->first();
        // Less / WH total: each service once (a service paid in 2 payments is not counted twice)
        $lessSum = (float) DB::query()->fromSub(
            $this->collectionBase($d)->whereNotNull('S.id')->select(['S.id', DB::raw($this->collectionLessSql() . ' AS v')])->distinct(), 'X'
        )->sum('v');
        $total = (int) $agg->c;
        $per = !empty($d['export']) ? max(1, min($total, 50000)) : (int) ($d['per_page'] ?? 50);   // export = every row (max 50,000)
        $last = max(1, (int) ceil($total / $per));
        $page = min((int) ($d['page'] ?? 1), $last);
 
        // 2) the ids of this page only
        $ids = $this->collectionBase($d)->orderByDesc('P.payment_date')->orderByDesc('P.id')
            ->offset(($page - 1) * $per)->limit($per)->pluck('P.id')->all();
 
        // 3) all the display columns for just those rows
        $rows = collect();
        if ($ids) {
            $q = DB::table('t_payment as P')->whereIn('P.id', $ids);
            $this->collectionJoin($q, ['S', 'L', 'T', 'COL', 'A', 'C']);
            $select = ['P.id', 'P.payment_date', 'A.client_num', DB::raw('COALESCE(P.amount, 0) AS amount_num'),
                DB::raw($this->collectionLessSql() . ' AS less_num'), DB::raw($this->collectionLessParts() . ' AS less_note')];
            foreach ($cols as $k => [$sql]) $select[] = DB::raw("{$sql} AS `{$k}`");
            $byId = $q->get($select)->keyBy('id');
            $rows = collect($ids)->map(fn ($id) => $byId[$id] ?? null)->filter()->map(function ($r) {
                $r->amount = (float) $r->amount_num;
                $r->less = round((float) $r->less_num, 2);
                unset($r->amount_num, $r->less_num, $r->payment_date);
                return $r;
            })->values();
        }
 
        return response()->json([
            'rows' => $rows, 'total' => $total, 'sum' => round((float) $agg->s, 2), 'less_sum' => round($lessSum, 2),
            'page' => $page, 'per_page' => $per, 'last_page' => $last,
        ]);
    }

    /* GET /monitoring/collection/values?col=&…same filters   distinct values for one column's filter list */
    public function collectionValues(Request $request)
    {
        $d = $this->collectionInput($request);
        $cols = $this->collectionCols();
        abort_unless(isset($cols[$d['col'] ?? '']), 422, 'Unknown column.');
        [$expr, $need] = $cols[$d['col']];

        $values = $this->collectionBase($d, $d['col'], $need)
            ->select(DB::raw("{$expr} AS v"))->distinct()->limit(2000)->pluck('v')
            ->map(fn ($v) => (string) $v)->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values();
        return response()->json(['values' => $values]);
    }

    /* =====================================================================
       RETAINERS FEE MONTHLY MONITORING (old pages/Monitoring/Retainers_Fee_Monthly_Monitoring.php)
       BILLED: services billed in the period range · NON-BILLED: recurring PF accounts with no PF bill for a month
       Fast: periods are matched by exact name (whereIn → index), payments are summed only for the services shown,
       and the amount due is worked out in PHP.
       ===================================================================== */

    const RF_TYPES = ['PF' => 4, 'Return' => 3, 'EO' => 2];   // m_service_list.ser_type_id
    const RF_NET_COLS = ['total_service_fee', 'd_vat', 'd_witholding', 'inherited_vat', 'inherited_wh', 'd_less_sc', 'd_less_pwd',
        'd_less_sp', 'd_less_others', 'inherited_discount_amount', 'less_other_percentage'];

    /** amount due (old MonthlyRF formula): total + VAT − withholding − discount */
    protected function rfNet(object $s): float
    {
        $base = (float) $s->total_service_fee;
        $vatOn = (int) $s->d_vat === 1;
        $whOn = (int) $s->d_witholding === 1;
        $vat = $vatOn ? ((float) $s->inherited_vat != 0 ? (float) $s->inherited_vat : $base * 0.12) : 0;
        $wh = $whOn ? ((float) $s->inherited_wh != 0 ? (float) $s->inherited_wh : $base * 0.05) : 0;
        $gross = $base + ($vatOn ? $base * 0.12 : 0) - ($whOn ? $base * 0.05 : 0);

        $sc = (int) $s->d_less_sc === 1;
        $pwd = (int) $s->d_less_pwd === 1;
        $sp = (int) $s->d_less_sp === 1;
        $oth = (int) $s->d_less_others === 1;
        $disc = 0;
        if ($sc || $pwd || $sp || $oth) {
            if ((float) $s->inherited_discount_amount != 0) $disc = (float) $s->inherited_discount_amount;
            elseif ($sc || $pwd) $disc = $gross * 0.20;
            elseif ($sp) $disc = $gross * 0.10;
            else $disc = $gross * ((float) $s->less_other_percentage / 100);
        }
        return round($base + $vat - $wh - $disc, 2);
    }

    /** m_service_list ids of one service type (PF / Return / EO) */
    protected function rfListIds(int $serType): array
    {
        return DB::table('m_service_list')->where('ser_type_id', $serType)->pluck('id')->all();
    }

    /** every period between from and to: "AUGUST 2026" … or EO-2025 … */
    private function rfPeriods(string $from, string $to, bool $eo): array
    {
        if ($eo) {
            [$a, $b] = [(int) substr($from, 3), (int) substr($to, 3)];
            if ($a > $b) [$a, $b] = [$b, $a];
            return array_map(fn ($y) => "EO-{$y}", range($a, $b));
        }
        $start = DateTime::createFromFormat('!F Y', ucwords(strtolower($from)));
        $end = DateTime::createFromFormat('!F Y', ucwords(strtolower($to)));
        abort_unless($start && $end, 422, 'Pick the From and To periods.');
        if ($start > $end) [$start, $end] = [$end, $start];
        $out = [];
        for ($c = clone $start; $c <= $end; $c->modify('+1 month')) $out[] = strtoupper($c->format('F Y'));
        return $out;
    }

    /** the service periods used on bills that are earlier than $before (exact names, so whereIn can use the index) */
    protected function rfEarlier(string $before, bool $eo): array
    {
        static $all = null;
        $all ??= DB::table('t_service')->whereNotNull('service_period')->distinct()->pluck('service_period')
            ->map(fn ($p) => strtoupper(trim((string) $p)))->unique()->values();
        if ($eo) return $all->filter(fn ($p) => preg_match('/^EO-\d{4}$/', $p) && $p < $before)->values()->all();
        $limit = strtotime('1 ' . $before);
        return $all->filter(fn ($p) => !str_starts_with($p, 'EO-') && ($t = strtotime('1 ' . $p)) && $t < $limit)->values()->all();
    }

    /** t_service lines of a type in some periods (void lines left out) */
    protected function rfServices(array $listIds, array $periods, array $cols, bool $unpaidOnly = false, ?array $caIds = null)
    {
        if (!$listIds || !$periods) return collect();
        return DB::table('t_service')
            ->select(array_values(array_unique([...$cols, ...self::RF_NET_COLS])))
            ->whereIn('ser_list_id', $listIds)
            ->whereIn('service_period', $periods)
            ->where(fn ($w) => $w->whereNull('invoice_status_id')->orWhere('invoice_status_id', '<>', self::VOID))
            ->when($unpaidOnly, fn ($q) => $q->where('total_service_fee', '>', 0)
                ->where(fn ($w) => $w->whereNull('is_paid')->orWhere('is_paid', '<>', 1)))
            ->when($caIds !== null, fn ($q) => $q->whereIn('ca_id', $caIds ?: [0]))
            ->get();
    }

    /** service id → { paid, last_paid } — only for the services given */
    protected function rfPaid($serviceIds): array
    {
        $out = [];
        foreach (collect($serviceIds)->chunk(1000) as $chunk) {
            Payment::query()->whereIn('service_id', $chunk->all())->groupBy('service_id')
                ->selectRaw('service_id, SUM(COALESCE(amount, 0)) AS paid, MAX(payment_date) AS last_paid')
                ->get()->each(function ($p) use (&$out) { $out[$p->service_id] = $p; });
        }
        return $out;
    }

    /** ca_id → { client_num, client_name, trade_name } */
    protected function rfAccounts(array $caIds): array
    {
        $accounts = ClientAccount::query()->whereIn('id', $caIds ?: [0])->get(['id', 'client_num', 'branch_code', 'trade_name']);
        $clients = Client::query()->whereIn('client_num', $accounts->pluck('client_num')->unique()->all() ?: [''])
            ->get(['client_num', 'last_name', 'first_name', 'middle_name', 'status_id'])->keyBy('client_num');
        $out = [];
        foreach ($accounts as $a) {
            $c = $clients[$a->client_num] ?? null;
            $out[$a->id] = [
                'client_num' => $a->client_num,
                'client_name' => $c ? trim(implode(' ', array_filter([$c->last_name, $c->first_name, $c->middle_name]))) : '',
                'last_name' => $c->last_name ?? '',
                'status_id' => $c->status_id ?? null,
                'trade_name' => trim(($a->branch_code ?? '') . ' ' . ($a->trade_name ?? '')),
                'branch_code' => $a->branch_code,
            ];
        }
        return $out;
    }

    /**
     * FORWARDING BALANCE (fixed): what each account still owed for this type BEFORE the range —
     * every earlier service (not void, not marked paid, fee > 0) minus what was paid on that service, never below zero.
     * Old query dropped a whole SOA once it had ANY payment, mixed service types and repeated the balance on every row.
     *
     * @return array ca_id => balance
     */
    protected function rfForwarding(int $serType, string $before, bool $eo, ?array $caIds = null): array
    {
        $services = $this->rfServices($this->rfListIds($serType), $this->rfEarlier($before, $eo), ['id', 'ca_id'], true, $caIds);
        $paid = $this->rfPaid($services->pluck('id'));
        $out = [];
        foreach ($services as $s) {
            $bal = $this->rfNet($s) - (float) ($paid[$s->id]->paid ?? 0);
            if ($bal > 0.004) $out[$s->ca_id] = round(($out[$s->ca_id] ?? 0) + $bal, 2);
        }
        return $out;
    }

    /* GET /monitoring/retainers-fee/lookups   service periods (months) + EO years */
    public function rfLookups()
    {
        $periods = DB::table('t_transaction')->whereNotNull('service_period')->distinct()->pluck('service_period')
            ->map(fn ($p) => strtoupper(trim((string) $p)))->filter()->unique();

        $months = $periods->reject(fn ($p) => str_starts_with($p, 'EO-'))->filter(fn ($p) => strtotime('1 ' . $p))
            ->merge(collect(range(0, 12))->map(fn ($i) => strtoupper(now()->startOfMonth()->subMonths($i)->format('F Y'))))
            ->unique()->sortByDesc(fn ($p) => strtotime('1 ' . $p))->values();
        $eo = $periods->filter(fn ($p) => preg_match('/^EO-\d{4}$/', $p))->push('EO-' . now()->format('Y'))
            ->unique()->sortDesc()->values();

        return response()->json(['months' => $months, 'eo' => $eo, 'current' => strtoupper(now()->format('F Y')), 'current_eo' => 'EO-' . now()->format('Y')]);
    }

    /* GET /monitoring/retainers-fee?type=PF|Return|EO&from=&to=&sort=latest|client&order=desc|asc   BILLED (old doGetMasterDisplay) */
    public function rfBilled(Request $request)
    {
        $d = $request->validate([
            'type'  => ['required', 'in:PF,Return,EO'],
            'from'  => ['required', 'string', 'max:30'],
            'to'    => ['required', 'string', 'max:30'],
            'sort'  => ['nullable', 'in:latest,client'],
            'order' => ['nullable', 'in:asc,desc'],
        ]);
        $eo = $d['type'] === 'EO';
        $isReturn = $d['type'] === 'Return';
        $serType = self::RF_TYPES[$d['type']];
        $periods = $this->rfPeriods(strtoupper(trim($d['from'])), strtoupper(trim($d['to'])), $eo);
        $from = $periods[0];

        $services = $this->rfServices($this->rfListIds($serType), $periods,
            ['id', 'trans_number', 'ca_id', 'service_period', 'ser_list_id', 'is_paid', 'return_status_id']);

        // lookups for just these rows
        $paid = $this->rfPaid($services->pluck('id'));
        $acc = $this->rfAccounts($services->pluck('ca_id')->unique()->all());
        $names = DB::table('m_service_list')->whereIn('id', $services->pluck('ser_list_id')->unique()->all() ?: [0])->pluck('name', 'id');
        $dateCol = $this->pick('t_transaction', ['date_start', 'created_at']) ?? 'created_at';
        $billedOn = DB::table('t_transaction')->whereIn('trans_number', $services->pluck('trans_number')->unique()->all() ?: [''])
            ->pluck($dateCol, 'trans_number');
        $rsCol = $isReturn && Schema::hasTable('m_payment_return_status')
            ? $this->pick('m_payment_return_status', ['name', 'return_status', 'description']) : null;
        $modes = $rsCol ? DB::table('m_payment_return_status')->pluck($rsCol, 'id') : collect();

        // forwarding balance as of the range start, shown ONCE per account (on its earliest row) so totals add up
        $fwd = $this->rfForwarding($serType, $from, $eo);
        $order = array_flip($periods);
        $first = [];
        foreach ($services->sortBy(fn ($s) => [$order[strtoupper($s->service_period)] ?? 0, $s->id]) as $s) $first[$s->ca_id] ??= $s->id;

        $out = $services->map(function ($s) use ($fwd, $first, $paid, $acc, $names, $billedOn, $modes, $isReturn) {
            $forward = ($first[$s->ca_id] ?? null) === $s->id ? ($fwd[$s->ca_id] ?? 0) : 0;
            $monthly = $this->rfNet($s);
            $collected = round((float) ($paid[$s->id]->paid ?? 0), 2);
            $last = $paid[$s->id]->last_paid ?? null;
            $total = round($forward + $monthly, 2);
            $billed = $billedOn[$s->trans_number] ?? null;
            $a = $acc[$s->ca_id] ?? [];
            return [
                'id' => (int) $s->id, 'ca_id' => (int) $s->ca_id, 'client_num' => $a['client_num'] ?? null,
                'client_name' => $a['client_name'] ?? '', 'trade_name' => $a['trade_name'] ?? '',
                'forwarding' => $forward, 'has_forwarding' => isset($fwd[$s->ca_id]),
                'period' => $s->service_period, 'monthly' => $monthly, 'total' => $total,
                'collected' => $collected, 'collected_date' => $last ? date('M d Y', strtotime($last)) : 'N/A',
                'balance' => round($total - $collected, 2), 'trans_number' => $s->trans_number,
                'status' => $isReturn ? ((int) $s->is_paid === 1 ? 'Paid' : 'Unpaid') : 'N/A',
                'mode' => $isReturn ? ($modes[$s->return_status_id] ?? '-') : 'N/A',
                'service' => $names[$s->ser_list_id] ?? '', 'date_billed' => $billed ? date('M d Y - h:iA', strtotime($billed)) : '',
                'trans_id' => (int) $s->id,
            ];
        });

        // accounts that only carry a forwarded balance (nothing billed in the range) still owe it — keep them in the totals
        $missing = array_diff_key($fwd, $out->pluck('ca_id')->flip()->all());
        foreach ($this->rfAccounts(array_keys($missing)) as $caId => $a) {
            $out->push([
                'id' => -$caId, 'ca_id' => $caId, 'client_num' => $a['client_num'],
                'client_name' => $a['client_name'], 'trade_name' => $a['trade_name'],
                'forwarding' => $missing[$caId], 'has_forwarding' => true, 'period' => 'Forwarded only',
                'monthly' => 0.0, 'total' => $missing[$caId], 'collected' => 0.0, 'collected_date' => 'N/A',
                'balance' => $missing[$caId], 'trans_number' => '', 'status' => 'N/A', 'mode' => 'N/A',
                'service' => '', 'date_billed' => '', 'trans_id' => 0,
            ]);
        }

        $desc = ($d['order'] ?? 'desc') === 'desc';
        $out = ($d['sort'] ?? 'latest') === 'client'
            ? $out->sortBy(fn ($r) => [mb_strtoupper((string) $r['client_name']), $r['trade_name']], SORT_REGULAR, $desc)
            : $out->sortBy(fn ($r) => $r['trans_id'], SORT_REGULAR, $desc);

        return response()->json(['type' => $d['type'], 'from' => $from, 'to' => end($periods), 'rows' => $out->values()]);
    }

    /* GET /monitoring/retainers-fee/forwarding?ca_id=&type=&before=   the unpaid bills behind a forwarding balance */
    public function rfForwardingDetail(Request $request)
    {
        $d = $request->validate([
            'ca_id' => ['required', 'integer'], 'type' => ['required', 'in:PF,Return,EO'], 'before' => ['required', 'string', 'max:30'],
        ]);
        $services = $this->rfServices(
            $this->rfListIds(self::RF_TYPES[$d['type']]),
            $this->rfEarlier(strtoupper(trim($d['before'])), $d['type'] === 'EO'),
            ['id', 'trans_number', 'service_period', 'ser_list_id', 'service_fee', 'qty'], true, [(int) $d['ca_id']]
        )->sortByDesc('id');
        $paid = $this->rfPaid($services->pluck('id'));
        $names = DB::table('m_service_list')->whereIn('id', $services->pluck('ser_list_id')->unique()->all() ?: [0])->pluck('name', 'id');

        $rows = $services->map(function ($s) use ($paid, $names) {
            $amount = $this->rfNet($s);
            $p = round((float) ($paid[$s->id]->paid ?? 0), 2);
            return [
                'id' => (int) $s->id, 'trans_number' => $s->trans_number, 'period' => $s->service_period,
                'service' => $names[$s->ser_list_id] ?? '', 'service_fee' => round((float) $s->service_fee, 2), 'qty' => (float) $s->qty,
                'amount' => $amount, 'paid' => $p, 'balance' => round($amount - $p, 2),
            ];
        })->filter(fn ($r) => $r['balance'] > 0.004)->values();

        return response()->json(['rows' => $rows]);
    }

    /* GET /monitoring/retainers-fee/non-billed?from=&to=   NON-BILLED (old doGetNonBilledClients) */
    public function rfNonBilled(Request $request)
    {
        $d = $request->validate(['from' => ['required', 'string', 'max:30'], 'to' => ['required', 'string', 'max:30']]);
        $months = $this->rfPeriods(strtoupper(trim($d['from'])), strtoupper(trim($d['to'])), false);
        $pfIds = $this->rfListIds(self::RF_TYPES['PF']);

        // recurring PF lines (fee > 0, active) → the latest EO version per account → monthly fee
        $rActive = $this->pick('t_recurring_service', ['is_active']);
        $lines = DB::table('t_recurring_service')
            ->whereIn('ser_list_id', $pfIds ?: [0])->where('total_service_fee', '>', 0)
            ->when($rActive, fn ($q) => $q->where($rActive, 1))
            ->get(['ca_id', 'eo_version', 'total_service_fee']);
        $latest = $lines->groupBy('ca_id')->map(fn ($g) => $g->max('eo_version'));
        $monthly = $lines->filter(fn ($l) => (int) $l->eo_version === (int) $latest[$l->ca_id])
            ->groupBy('ca_id')->map(fn ($g) => round((float) $g->sum('total_service_fee'), 2));
        if ($monthly->isEmpty()) return response()->json(['rows' => []]);

        // accounts of active clients (status 2/9/11) that have a login
        $withLogin = DB::table('m_user_client')->whereIn('caid', $monthly->keys()->all())->distinct()->pluck('caid')->flip();
        $activeCol = $this->pick('m_client', ['active', 'is_active']);
        $activeClients = $activeCol ? Client::query()->where($activeCol, 1)->pluck('client_num')->flip() : null;
        $accounts = collect($this->rfAccounts($monthly->keys()->all()))
            ->filter(fn ($a, $id) => isset($withLogin[$id]) && in_array((int) $a['status_id'], self::CLIENT_STATUSES, true)
                && ($activeClients === null || isset($activeClients[$a['client_num']])))
            ->sortBy(fn ($a) => [mb_strtoupper((string) $a['last_name']), $a['branch_code']]);
        if ($accounts->isEmpty()) return response()->json(['rows' => []]);

        // PF already billed per account + month
        $billed = $this->rfServices($pfIds, $months, ['ca_id', 'service_period'], false, $accounts->keys()->all())
            ->map(fn ($s) => $s->ca_id . '|' . strtoupper($s->service_period))->flip();

        $fwd = $this->rfForwarding(self::RF_TYPES['PF'], $months[0], false, $accounts->keys()->all());

        $out = [];
        $shown = [];
        foreach ($months as $m) {
            foreach ($accounts as $caId => $a) {
                if (isset($billed[$caId . '|' . $m])) continue;
                $forward = isset($shown[$caId]) ? 0 : ($fwd[$caId] ?? 0);   // once per account, on its first month
                $shown[$caId] = true;
                $fee = $monthly[$caId] ?? 0;
                $out[] = [
                    'ca_id' => $caId, 'client_num' => $a['client_num'], 'client_name' => $a['client_name'],
                    'trade_name' => $a['trade_name'], 'forwarding' => $forward, 'period' => $m,
                    'monthly' => $fee, 'total' => round($forward + $fee, 2),
                ];
            }
        }
        return response()->json(['rows' => $out]);
    }
    private function collectionLessSql(): string
    {
        $c = fn ($n) => $this->pick('t_service', [$n]);
        $parts = [];
        if ($c('less')) $parts[] = 'COALESCE(S.less, 0)';
        if ($c('inherited_wh')) {
            $parts[] = $c('d_witholding')
                ? 'CASE WHEN COALESCE(S.inherited_wh, 0) <> 0 THEN S.inherited_wh WHEN S.d_witholding = 1 THEN ROUND(COALESCE(S.total_service_fee, 0) * 0.05, 2) ELSE 0 END'
                : 'COALESCE(S.inherited_wh, 0)';
        }
        if ($c('inherited_discount_amount')) $parts[] = 'COALESCE(S.inherited_discount_amount, 0)';
        return '(' . ($parts ? implode(' + ', $parts) : '0') . ')';
    }
    private function collectionLessParts(): string
    {
        $c = fn ($n) => $this->pick('t_service', [$n]);
        $wh = $c('inherited_wh')
            ? ($c('d_witholding')
                ? 'CASE WHEN COALESCE(S.inherited_wh, 0) <> 0 THEN S.inherited_wh WHEN S.d_witholding = 1 THEN ROUND(COALESCE(S.total_service_fee, 0) * 0.05, 2) ELSE 0 END'
                : 'COALESCE(S.inherited_wh, 0)')
            : null;
        $parts = array_filter([
            $c('less') ? "CASE WHEN COALESCE(S.less, 0) <> 0 THEN CONCAT('Less ', FORMAT(S.less, 2)) END" : null,
            $wh ? "CASE WHEN ({$wh}) <> 0 THEN CONCAT('WH ', FORMAT({$wh}, 2)) END" : null,
            $c('inherited_discount_amount') ? "CASE WHEN COALESCE(S.inherited_discount_amount, 0) <> 0 THEN CONCAT('Disc. ', FORMAT(S.inherited_discount_amount, 2)) END" : null,
        ]);
        return $parts ? "COALESCE(CONCAT_WS(' · ', " . implode(', ', $parts) . "), '')" : "''";
    }
}