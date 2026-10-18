<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * BIR Form 2307 PDF (port of Print/ReportPHP/merge.php).
 * Fills resources/templates/2307_Template.odg ([p.field] placeholders) and converts it to PDF with LibreOffice.
 *
 *   $pdf = Bir2307::pdf([
 *       'from' => '2026-07-01', 'to' => '2026-07-31',
 *       'payee' => ['tin' => '123-456-789', 'name' => '…', 'address' => '… 9014'],
 *       'payor' => ['tin' => '…', 'name' => '…', 'address' => '…'],
 *       'income'   => [['desc' => '…', 'atc' => 'WC158', 'm1' => 0, 'm2' => 1000, 'm3' => 0, 'tax' => 10]],   // Part III EWT
 *       'business' => [[…]],                                                                                // Part III business tax
 *   ]);
 *
 * LibreOffice path: SOFFICE_PATH in .env, else the usual Windows / Linux locations.
 */
class Bir2307
{
    public static function pdf(array $d): string
    {
        $p = self::blank();

        if (!empty($d['from'])) self::date($p, 'from', $d['from']);
        if (!empty($d['to'])) self::date($p, 'to', $d['to']);

        foreach (['payee', 'payor'] as $w) {
            $x = $d[$w] ?? [];
            $tin = trim((string) ($x['tin'] ?? ''));
            $digits = preg_replace('/\D/', '', $tin);
            if ($digits !== '' && strlen($digits) <= 9) $digits .= '00000';     // old code added -00000 (branch code)
            self::boxes($p, "{$w}_tin", $digits, 14);

            [$addr, $zip] = self::splitZip((string) ($x['address'] ?? ''));
            $p["{$w}_name"] = (string) ($x['name'] ?? '');
            $p["{$w}_addr"] = $addr;
            self::boxes($p, "{$w}_zip", $x['zip'] ?? $zip, 4);
            $p["{$w}_sig"] = trim(($x['name'] ?? '') . ($tin !== '' ? ' - ' . self::fmtTin($digits) : ''));
        }

        self::rows($p, 'r', $d['income'] ?? []);
        self::rows($p, 'b', $d['business'] ?? []);

        return self::render($p);
    }

    /* ---------------- fields ---------------- */

    private static function blank(): array
    {
        $p = [];
        foreach (['from', 'to'] as $k) for ($i = 0; $i < 8; $i++) $p["{$k}_$i"] = '';
        foreach (['payee', 'payor'] as $w) {
            for ($i = 0; $i < 14; $i++) $p["{$w}_tin_$i"] = '';
            for ($i = 0; $i < 4; $i++) $p["{$w}_zip_$i"] = '';
            foreach (['name', 'addr', 'sig', 'agent'] as $k) $p["{$w}_$k"] = '';
            for ($i = 0; $i < 8; $i++) { $p["{$w}_iss_$i"] = ''; $p["{$w}_exp_$i"] = ''; }
        }
        $p['payee_foreign'] = '';
        foreach (['r', 'b'] as $t) {
            for ($r = 1; $r <= 10; $r++) foreach (['desc', 'atc', 'm1', 'm2', 'm3', 'tot', 'tax'] as $c) $p["{$t}{$r}_$c"] = '';
            foreach (['m1', 'm2', 'm3', 'tot', 'tax'] as $c) $p["{$t}tot_$c"] = '';
        }
        return $p;
    }

    private static function boxes(array &$p, string $prefix, $value, int $max): void
    {
        $chars = str_split(preg_replace('/[^0-9A-Za-z]/', '', (string) $value));
        for ($i = 0; $i < $max && $i < count($chars); $i++) $p["{$prefix}_$i"] = $chars[$i];
    }

    private static function date(array &$p, string $prefix, string $date): void
    {
        self::boxes($p, $prefix, date('mdY', strtotime($date)), 8);
    }

    private static function money($v): string
    {
        return number_format((float) $v, 2);
    }

    private static function fmtTin(string $digits): string
    {
        return implode('-', array_filter([substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3), substr($digits, 9)], 'strlen'));
    }

    // "Purok 4, Gingoog City 9014" → ["Purok 4, Gingoog City", "9014"]
    private static function splitZip(string $addr): array
    {
        $addr = trim($addr, " ,");
        return preg_match('/^(.*?)[\s,]*(\d{4})\s*$/', $addr, $m) ? [$m[1], $m[2]] : [$addr, ''];
    }

    private static function rows(array &$p, string $prefix, array $rows): void
    {
        $tot = ['m1' => 0, 'm2' => 0, 'm3' => 0, 'tot' => 0, 'tax' => 0];
        $n = 0;
        foreach (array_slice($rows, 0, 10) as $row) {
            $n++;
            $desc = trim((string) ($row['desc'] ?? ''));
            if (mb_strlen($desc) > 42) $desc = rtrim(mb_substr($desc, 0, 39), ' ,.-/') . '...';
            $m1 = (float) ($row['m1'] ?? 0); $m2 = (float) ($row['m2'] ?? 0); $m3 = (float) ($row['m3'] ?? 0);
            $t = $m1 + $m2 + $m3;
            $tax = isset($row['tax']) ? (float) $row['tax'] : round($t * (float) ($row['rate'] ?? 0), 2);

            $p["{$prefix}{$n}_desc"] = $desc;
            $p["{$prefix}{$n}_atc"] = (string) ($row['atc'] ?? '');
            foreach (['m1' => $m1, 'm2' => $m2, 'm3' => $m3, 'tot' => $t, 'tax' => $tax] as $k => $v) {
                $p["{$prefix}{$n}_$k"] = self::money($v);
                $tot[$k] += $v;
            }
        }
        if ($n) foreach ($tot as $k => $v) $p["{$prefix}tot_$k"] = self::money($v);
    }

    /* ---------------- ODG → PDF ---------------- */

    private static function render(array $p): string
    {
        $template = resource_path('templates/2307_Template.odg');
        if (!is_file($template)) throw new RuntimeException("2307 template not found: {$template}");

        $work = storage_path('app/tmp/2307_' . uniqid());
        @mkdir($work, 0777, true);
        $odg = "{$work}/report.odg";
        $pdf = "{$work}/report.pdf";
        copy($template, $odg);

        // fill [p.field] placeholders
        $zip = new ZipArchive();
        if ($zip->open($odg) !== true) throw new RuntimeException('Could not open the 2307 template.');
        $map = [];
        foreach ($p as $k => $v) $map["[p.{$k}]"] = htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        foreach (['content.xml', 'styles.xml'] as $part) {
            $xml = $zip->getFromName($part);
            if ($xml !== false) $zip->addFromString($part, strtr($xml, $map));
        }
        $zip->close();

        try {
            $lock = fopen(storage_path('app/tmp/lo_convert.lock'), 'c');
            flock($lock, LOCK_EX);                       // one LibreOffice conversion at a time
            [$code, $out] = self::convert($odg, $work);
            if (!is_file($pdf) || filesize($pdf) === 0) [$code, $out] = self::convert($odg, $work);
            flock($lock, LOCK_UN);
            fclose($lock);

            if (!is_file($pdf) || filesize($pdf) === 0) {
                throw new RuntimeException("PDF conversion failed (exit code {$code}). " . trim((string) $out));
            }
            return file_get_contents($pdf);
        } finally {
            self::rrmdir($work);
        }
    }

    private static function soffice(): string
    {
        foreach (array_filter([
            env('SOFFICE_PATH'),
            'C:\\Program Files\\LibreOffice\\program\\soffice.com',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com',
            '/usr/bin/soffice', '/usr/local/bin/soffice', '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ]) as $path) {
            if (is_file($path)) return $path;
        }
        throw new RuntimeException('LibreOffice not found. Install it or set SOFFICE_PATH in .env.');
    }

    private static function convert(string $in, string $outDir): array
    {
        $soffice = self::soffice();
        $profile = storage_path('app/tmp/lo_profile');
        @mkdir($profile, 0777, true);
        $profileUrl = 'file:///' . ltrim(str_replace('\\', '/', $profile), '/');

        $cmd = '"' . $soffice . '" --headless --invisible --norestore --nologo --nolockcheck --nodefault --nofirststartwizard'
            . ' "-env:UserInstallation=' . $profileUrl . '" --convert-to pdf --outdir "' . $outDir . '" "' . $in . '"';

        $env = null;
        if (DIRECTORY_SEPARATOR === '\\') {            // Windows / XAMPP
            $tmp = storage_path('app/tmp');
            $env = [
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'windir' => getenv('windir') ?: 'C:\\Windows',
                'PATH' => getenv('PATH'), 'TEMP' => $tmp, 'TMP' => $tmp,
                'APPDATA' => $profile, 'LOCALAPPDATA' => $profile, 'USERPROFILE' => $profile,
            ];
        } else {
            $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $profile];
        }

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($soffice), $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) return [-1, 'proc_open failed'];
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $x = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($x) ? self::rrmdir($x) : @unlink($x);
        }
        @rmdir($dir);
    }
}
