<?php

namespace App\Http\Controllers\Concerns;

/**
 * E-Sub DAT validation – the same kind of checks the BIR RELIEF Validation Module does,
 * run BEFORE the DAT is given out. Errors block the download, warnings don't.
 *
 *  Errors   : missing / wrong TIN (letters, special characters, wrong length, all zeros, same as the client's) – not for Sales,
 *             missing registered name, special characters in the name, negative amounts,
 *             missing ATC / amount (1601EQ, 1604E),
 *             client header without TIN / name / address / RDO, a period longer than one quarter.
 *  Warnings : name longer than 50, VAT not 12% of the net, tax not amount × rate, line with all zero amounts,
 *             RDO not 3 digits.
 *  Fixed    : dashes/spaces in TIN removed, Ñ → N, accents removed, commas and quotes removed,
 *             upper case, extra spaces – and lines with the same TIN in one file are added together (P / S).
 */
trait EsubValidation
{
    /** RELIEF text: upper case, Ñ → N, no accents, no commas / quotes / line breaks, single spaces */
    protected function esDatText($s): string
    {
        $s = (string) $s;
        $s = strtr($s, ['Ñ' => 'N', 'ñ' => 'N', '“' => '', '”' => '', '"' => '', "'" => "'", '’' => "'", '‘' => "'",
            ',' => ' ', "\r" => ' ', "\n" => ' ', "\t" => ' ', '–' => '-', '—' => '-']);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) $s = $t;
        }
        $s = preg_replace('/[^\x20-\x7E]/', '', $s);
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /** address / city: same as above and anything RELIEF doesn't take is dropped (fixed, not an error) */
    protected function esDatAddress($s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace("/[^A-Z0-9 &.\-'()\/#]/", ' ', $this->esDatText($s))));
    }

    /** TIN problem → message, null = fine */
    protected function esTinProblem(string $raw, string $ownTin): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return 'No TIN';
        if (preg_match('/[^0-9\-\s]/', $raw)) return "TIN has letters or special characters ({$raw})";
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) < 9) return "TIN has only " . strlen($d) . " digits ({$raw}) – it needs 9";
        if (!in_array(strlen($d), [9, 12, 14], true)) return "TIN has " . strlen($d) . " digits ({$raw}) – 9 digits, plus a 3 or 5 digit branch code";
        if ((int) substr($d, 0, 9) === 0) return 'TIN is all zeros';
        if ($ownTin !== '' && substr($d, 0, 9) === $ownTin) return "TIN is the client's own TIN";
        return null;
    }

    /** registered name problem → message, null = fine (commas, Ñ, accents are fixed, not errors) */
    protected function esNameProblem(string $raw): ?string
    {
        if (trim($raw) === '') return 'No registered name';
        $clean = strtr($raw, ['Ñ' => 'N', 'ñ' => 'n', '’' => "'", '‘' => "'", '–' => '-', '—' => '-']);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean);
            if ($t !== false) $clean = $t;
        }
        $bad = array_values(array_unique(str_split(preg_replace("/[A-Za-z0-9 ,&.\-'()\/]/", '', $clean))));
        $bad = array_filter($bad, fn ($ch) => trim($ch) !== '');
        return $bad ? 'Registered name has special characters: ' . implode(' ', $bad) : null;
    }

    /** same TIN in one DAT → one line (RELIEF lists one line per TIN per period) */
    protected function esConsolidate(array $rows, array $moneyKeys): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $r['tin'] !== '' ? 'T' . substr($r['tin'], 0, 9) : 'N' . strtoupper($r['name']);
            if (!isset($out[$k])) { $out[$k] = $r; continue; }
            foreach ($moneyKeys as $m) $out[$k][$m] = round(($out[$k][$m] ?? 0) + ($r[$m] ?? 0), 2);
        }
        return array_values($out);
    }

    /**
     * @param array $rows  the report lines, each with tin, name and the amounts, plus _tin_raw / _name_raw
     * @return array{ok: bool, errors: array, warnings: array, fixed: array}
     */
    protected function esValidate(string $type, array $rows, array $c, ?string $from, ?string $to): array
    {
        $errors = []; $warnings = []; $fixed = [];
        $own = (string) ($c['tin9'] ?? '');
        $add = function (array &$list, ?int $line, array $r, string $field, string $msg) {
            $list[] = ['line' => $line, 'ref' => $r['ref'] ?? ($r['doc'] ?? ''), 'tin' => $r['tin'] ?? '', 'name' => $r['name'] ?? '', 'field' => $field, 'message' => $msg];
        };
        $none = [];

        /* ---------- the client (header line) ---------- */
        if (!preg_match('/^\d{9}$/', $own)) $add($errors, null, $none, 'Client TIN', "The client's TIN must be 9 digits.");
        if (trim((string) ($c['reg_name'] ?? '')) === '') $add($errors, null, $none, 'Client name', "The client has no registered name.");
        if (trim((string) ($c['address'] ?? '')) === '') $add($errors, null, $none, 'Client address', "The client has no address.");
        $rdo = trim((string) ($c['rdo'] ?? ''));
        if ($rdo === '') $add($errors, null, $none, 'RDO', "The client has no RDO code. Add it to the client's MAIN branch.");
        elseif (!preg_match('/^\d{3}[A-Z]?$/i', $rdo)) $add($warnings, null, $none, 'RDO', "RDO code \"{$rdo}\" doesn't look like a BIR RDO code (3 digits, e.g. 098).");

        /* ---------- period: a DAT is one month or one quarter ---------- */
        if ($type !== '1604E' && $from && $to) {
            [$fy, $fm] = array_map('intval', explode('-', $from));
            [$ty, $tm] = array_map('intval', explode('-', $to));
            $sameQuarter = $fy === $ty && intdiv($fm - 1, 3) === intdiv($tm - 1, 3);
            if (!$sameQuarter) {
                $add($errors, null, $none, 'Period', "A DAT file covers one month or one quarter. {$from} to {$to} is longer – choose months inside one quarter (e.g. Jan–Mar).");
            }
        }

        /* ---------- every line ---------- */
        $isList = in_array($type, ['P', 'S'], true);
        foreach ($rows as $i => $r) {
            $line = $i + 1;
            $tinRaw = (string) ($r['_tin_raw'] ?? $r['tin'] ?? '');
            $nameRaw = (string) ($r['_name_raw'] ?? $r['name'] ?? '');

            if ($type !== 'S') {
                if ($p = $this->esTinProblem($tinRaw, $own)) $add($errors, $line, $r, 'TIN', $p);
                elseif (preg_match('/[\-\s]/', $tinRaw)) $fixed['tin'] = 'Dashes and spaces were removed from TINs.';
            } else {
                // Sales: an empty TIN is allowed (walk-in / customers without TIN),
                // but a TIN of only zeros (000-000-000, 00000000 …) is not – leave it empty instead
                $digits = preg_replace('/\D/', '', $tinRaw);
                if ($digits !== '' && preg_match('/^0+$/', $digits)) {
                    $add($errors, $line, $r, 'TIN', "TIN is all zeros ({$tinRaw}) – leave the TIN empty if the customer has none.");
                }
            }

            if ($p = $this->esNameProblem($nameRaw)) $add($errors, $line, $r, 'Registered name', $p);
            else {
                if (preg_match('/[,ñÑ]|[^\x20-\x7E]/u', $nameRaw)) $fixed['name'] = 'Names: commas removed, Ñ changed to N, accents removed.';
            }

            if ($isList) {
                // negative amounts (returns / credit memos) are allowed – no error, they go into the DAT as they are
                $net = (float) ($r['net'] ?? 0); $vat = (float) ($r['vat'] ?? 0);
                if ($net == 0 && $vat == 0 && (float) ($r['exempt'] ?? 0) == 0 && (float) ($r['zero'] ?? 0) == 0) {
                    $add($warnings, $line, $r, 'Amounts', 'All amounts are zero.');
                } elseif ($net > 0 && abs($vat - $net * 0.12) > 1) {
                    $add($warnings, $line, $r, 'VAT', 'VAT ' . number_format($vat, 2) . ' is not 12% of the net (' . number_format($net * 0.12, 2) . ').');
                }
            } else {
                if (trim((string) ($r['atc'] ?? '')) === '') $add($errors, $line, $r, 'ATC', 'No ATC.');
                $base = (float) ($r['base'] ?? 0); $tax = (float) ($r['tax'] ?? 0); $rate = (float) ($r['rate'] ?? 0);
                if ($base <= 0) $add($errors, $line, $r, 'Amount', 'Amount (tax base) must be more than zero.');
                if ($tax < 0) $add($errors, $line, $r, 'Tax withheld', 'Tax withheld is negative.');
                if ($base > 0 && $rate > 0 && abs($tax - $base * $rate / 100) > 1) {
                    $add($warnings, $line, $r, 'Tax withheld', 'Tax ' . number_format($tax, 2) . " is not {$rate}% of " . number_format($base, 2) . ' (' . number_format($base * $rate / 100, 2) . ').');
                }
            }
        }

        if ($isList && count($this->esConsolidate($rows, [])) < count($rows)) {
            $fixed['merge'] = 'Lines with the same TIN were added together into one DAT line each (RELIEF lists one line per TIN).';
        }
        if (!$rows) $add($warnings, null, $none, 'Lines', 'No transactions for this period – the DAT has the header only.');

        return ['ok' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'fixed' => array_values($fixed)];
    }
}