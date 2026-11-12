<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client → Masterlist → Engagement → Print
 *   Engagement / Disengagement letter: Word template (resources/templates/*.docx) → filled → LibreOffice → PDF
 *
 * routes/api.php (inside the auth:sanctum group):
 *   use App\Http\Controllers\Api\ClientLetterController;
 *   Route::get('/clients/{num}/letter/{kind}', [ClientLetterController::class, 'letter'])
 *       ->where('kind', 'engagement|disengagement');
 *
 * GET /api/clients/{num}/letter/{kind}?account_id=&date=YYYY-MM-DD&letter_date=YYYY-MM-DD&balance=7500
 *   account_id   business account (trade name / address) – default: the client's first account
 *   date         effective date – default: the client's Date of engagement / disengagement
 *   letter_date  date on top – default: today
 *   balance      disengagement only – outstanding balance (₱); not sent → unpaid PF + EO from Billing
 *
 * GET /api/clients/{num}/outstanding?account_id=   unpaid PF & Other Charges + Non-recurring / EO (Billing)
 *   Route::get('/clients/{num}/outstanding', [ClientLetterController::class, 'outstanding']);
 */
class ClientLetterController extends Controller
{
    // same as BillingController::GROUPS (PF & EO only) – service types → payment collection type
    const BILL_GROUPS = [
        'pf' => ['ser_types' => [4, 5, 6, 7], 'col_type' => 1],
        'eo' => ['ser_types' => [2], 'col_type' => 4],
    ];
    const VOID = 5, CANCELLED = 9;

    const TEMPLATES = [
        'engagement' => 'ENGAGEMENT-LETTER.docx',
        'disengagement' => 'DISENGAGEMENT-LETTER.docx',
    ];

    public function letter(Request $request, string $num, string $kind)
    {
        try {
            return $this->makeLetter($request, $num, $kind);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Couldn\'t make the letter.'], $e->getStatusCode());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Letter failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'], 500);
        }
    }

    private function makeLetter(Request $request, string $num, string $kind)
    {
        $d = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
            'letter_date' => ['nullable', 'date'],
            'balance' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $c = DB::table('m_client')->where('client_num', $num)->first();
        abort_if(!$c, 404, 'Client not found.');
        $acc = DB::table('m_client_account')->where('client_num', $num)
            ->when(!empty($d['account_id']), fn ($q) => $q->where('id', $d['account_id']))
            ->orderBy('branch_code')->orderBy('id')->first();

        $dateCol = $kind === 'engagement' ? 'date_of_engagement' : 'date_of_disengagement';
        $effective = $d['date'] ?? ($c->$dateCol ?? null);
        abort_if(!$effective, 422, $kind === 'engagement' ? 'Set the Date of engagement first.' : 'Set the Date of disengagement first.');

        $trade = trim((string) ($acc->trade_name ?? '')) ?: $this->owner($c);
        $values = [
            '{LETTER_DATE}' => date('F j, Y', strtotime($d['letter_date'] ?? now()->toDateString())),
            '{OWNER}' => $this->owner($c),
            '{TRADE}' => $this->title($trade),
            '{TRADE_UPPER}' => mb_strtoupper($trade),
            '{ADDRESS}' => $this->address($acc, $c),
            '{SALUTE}' => $this->salute($c->gender ?? ''),
            '{EFFECTIVE_DATE}' => date('F j, Y', strtotime($effective)),
            '{BALANCE}' => number_format(isset($d['balance']) ? (float) $d['balance'] : $this->unpaid($acc ? [(int) $acc->id] : [])['total'], 2),
        ];

        $template = resource_path('templates/' . self::TEMPLATES[$kind]);
        abort_unless(is_file($template), 500, 'Missing template: resources/templates/' . self::TEMPLATES[$kind]);

        $docx = $this->fill($template, $values);
        $pdf = $this->toPdf($docx);
        $bytes = file_get_contents($pdf);
        @unlink($pdf);

        $file = strtoupper($kind) . '-LETTER-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $trade) . '.pdf';
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /* GET /api/clients/{num}/outstanding?account_id=   (no account → every account of the client) */
    public function outstanding(Request $request, string $num)
    {
        $id = (int) $request->query('account_id', 0);
        $ids = DB::table('m_client_account')->where('client_num', $num)
            ->when($id, fn ($q) => $q->where('id', $id))->pluck('id')->map(fn ($x) => (int) $x)->all();
        return response()->json($this->unpaid($ids));
    }

    /** PF + EO: bill (net of VAT / withholding / discount, not void / cancelled) − payments, per group, never below 0 */
    private function unpaid(array $caids): array
    {
        $out = ['pf' => 0.0, 'eo' => 0.0, 'total' => 0.0];
        if (!$caids) return $out;
        $net = '(COALESCE(S.total_service_fee,0) + COALESCE(S.inherited_vat,0) - COALESCE(S.inherited_wh,0) - COALESCE(S.inherited_discount_amount,0))';
        $bills = DB::table('t_service as S')->leftJoin('m_service_list as L', 'L.id', '=', 'S.ser_list_id')
            ->whereIn('S.ca_id', $caids)
            ->whereNotIn(DB::raw('COALESCE(S.invoice_status_id, 0)'), [self::VOID, self::CANCELLED])
            ->groupBy('L.ser_type_id')->selectRaw("L.ser_type_id AS t, SUM({$net}) AS amt")->pluck('amt', 't');
        $paid = DB::table('t_payment')->whereIn('ca_id', $caids)
            ->groupBy('payment_col_type_id')->selectRaw('payment_col_type_id AS t, SUM(amount) AS amt')->pluck('amt', 't');
        foreach (self::BILL_GROUPS as $k => $g) {
            $bill = collect($g['ser_types'])->sum(fn ($t) => (float) ($bills[$t] ?? 0));
            $out[$k] = round(max(0, $bill - (float) ($paid[$g['col_type']] ?? 0)), 2);
        }
        $out['total'] = round($out['pf'] + $out['eo'], 2);
        return $out;
    }

    /* ---------------- text ---------------- */

    private function title(string $s): string
    {
        $s = mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');
        $s = preg_replace_callback("/(['’])(\p{Lu})/u", fn ($m) => $m[1] . mb_strtolower($m[2]), $s);   // Eve’S → Eve’s
        // initials stay capital: ND, JT, CC, GB (short words without a vowel)
        return preg_replace_callback('/\b([A-Za-z]{2,3})\b(?!\.)/u', fn ($m) => preg_match('/[aeiouy]/i', $m[1]) ? $m[1] : strtoupper($m[1]), $s);
    }

    /** "Marie Melody Anne A. Nadal-Kelly" */
    private function owner(object $c): string
    {
        $mid = trim((string) ($c->middle_name ?? ''));
        $name = trim(implode(' ', array_filter([
            $this->title((string) ($c->first_name ?? '')),
            $mid !== '' ? mb_strtoupper(mb_substr($mid, 0, 1)) . '.' : '',
            $this->title((string) ($c->last_name ?? '')),
            trim((string) ($c->suffix ?? '')),
        ])));
        return $name !== '' ? $name : $this->title((string) ($c->registered_name ?? $c->client_name ?? ''));
    }

    private function salute(string $gender): string
    {
        $g = strtolower(trim($gender));
        if ($g !== '' && $g[0] === 'f') return 'Ma’am';
        if ($g !== '' && $g[0] === 'm') return 'Sir';
        return 'Sir/Ma’am';
    }

    /** street, Brgy. …, City (account first, else client) */
    private function address(?object $a, object $c): string
    {
        $v = fn ($k) => ($a && !empty($a->$k)) ? $a->$k : ($c->$k ?? null);
        $brgy = $this->place(['m_barangay', 'm_brgy', 'm_barangays'], $v('brgy_id'));
        $city = $this->place(['m_city', 'm_city_municipality', 'm_city_mun', 'm_cities'], $v('city_id'));
        $parts = array_filter([
            $this->title((string) ($v('street') ?? '')),
            $brgy !== '' ? (preg_match('/^(brgy|barangay)/i', $brgy) ? $this->title($brgy) : 'Brgy. ' . $this->title($brgy)) : '',
            $this->title($city),
        ]);
        return implode(', ', $parts);
    }

    private function place(array $tables, $id): string
    {
        if (!$id) return '';
        foreach ($tables as $t) {
            if (!Schema::hasTable($t)) continue;
            $cols = Schema::getColumnListing($t);
            foreach (['brgy_name', 'barangay_name', 'barangay', 'city_name', 'city_mun_name', 'citymun_name', 'city', 'name', 'description'] as $n) {
                foreach ($cols as $col) {
                    if (strcasecmp($col, $n) === 0) return (string) DB::table($t)->where('id', $id)->value($col);
                }
            }
        }
        return '';
    }

    /* ---------------- docx → pdf ---------------- */

    /** copy of the template with {PLACEHOLDERS} replaced (word/document.xml) */
    private function fill(string $template, array $values): string
    {
        $docx = $this->tempPath('docx');
        copy($template, $docx);
        $zip = new \ZipArchive();
        abort_unless($zip->open($docx) === true, 500, "Couldn't open the letter template.");
        $xml = $zip->getFromName('word/document.xml');
        $safe = array_map(fn ($x) => htmlspecialchars((string) $x, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $values);
        $zip->addFromString('word/document.xml', strtr($xml, $safe));
        $zip->close();
        return $docx;
    }

    private function tempPath(string $ext): string
    {
        // one kind of slash (LibreOffice can't load C:\...\storage\app/letters-tmp\x.docx)
        $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, storage_path('app' . DIRECTORY_SEPARATOR . 'letters-tmp'));
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $dir = realpath($dir) ?: $dir;
        return $dir . DIRECTORY_SEPARATOR . 'letter-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    }

    /** LibreOffice (same lookup as PrintController) */
    private function toPdf(string $docx): string
    {
        @set_time_limit(300);
        $outDir = dirname($docx);
        $pdf = $outDir . DIRECTORY_SEPARATOR . pathinfo($docx, PATHINFO_FILENAME) . '.pdf';
        $win = PHP_OS_FAMILY === 'Windows';
        $candidates = array_filter([
            config('services.libreoffice.path'),
            env('LIBREOFFICE_PATH'),
            ...($win ? ['C:\\Program Files\\LibreOffice\\program\\soffice.com', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com', 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe']
                     : ['/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice']),
        ]);
        $profileUrl = 'file:///' . ltrim(str_replace('\\', '/', storage_path('app/libreoffice-profile')), '/');
        $lock = fopen(storage_path('app/libreoffice.lock'), 'c');
        $tried = [];

        foreach (array_unique($candidates) as $soffice) {
            if (!is_file($soffice)) continue;
            if ($win && str_ends_with(strtolower($soffice), '.exe') && is_file(substr($soffice, 0, -4) . '.com')) $soffice = substr($soffice, 0, -4) . '.com';
            if ($lock) flock($lock, LOCK_EX);
            [$code, $out] = $this->run([$soffice, "-env:UserInstallation={$profileUrl}", '--headless', '--norestore', '--nologo',
                '--convert-to', 'pdf', '--outdir', $outDir, $docx], 180);
            for ($i = 0; $i < 40 && !is_file($pdf); $i++) usleep(250000);
            if ($lock) flock($lock, LOCK_UN);
            if (is_file($pdf) && filesize($pdf) > 0) {
                if ($lock) fclose($lock);
                @unlink($docx);
                return $pdf;
            }
            $tried[] = "{$soffice}: exit {$code} " . trim(mb_substr($out, 0, 300));
        }
        if ($lock) fclose($lock);
        @unlink($docx);
        $why = $tried ? implode(' | ', $tried) : 'soffice not found – set LIBREOFFICE_PATH in .env';
        abort(response()->json(['message' => "Couldn't make the PDF (LibreOffice): {$why}", 'tried' => $tried], 422));
    }

    /** same as PrintController::run() – proc_open without a shell */
    private function run(array $cmd, int $timeout): array
    {
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($p)) return [-1, 'could not start'];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $end = time() + $timeout;
        while (true) {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) break;
            if (time() > $end) { proc_terminate($p); $out .= ' (timed out)'; break; }
            usleep(100000);
        }
        $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return [isset($st['exitcode']) && $st['exitcode'] >= 0 ? $st['exitcode'] : $code, $out];
    }
}