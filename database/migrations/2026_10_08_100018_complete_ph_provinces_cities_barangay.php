<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes m_province / m_city_mun / m_barangay with every province, city / municipality and barangay
 * of the Philippines (PSA PSGC, 3Q 2022 publication: 82 provinces · 1,642 cities / municipalities · 42,047 barangays).
 * Data: database/data/psgc_ph.json
 *
 * INSERT ONLY — existing rows are never updated or deleted:
 *   province  : matched by name (ignoring case / spaces / punctuation) · a missing one is added
 *   city / mun: matched by province + name ("City of Gingoog" = "Gingoog City" = "GINGOOG") · a missing one is added
 *   barangay  : matched by city + name (also ignoring "(Pob.)") · a missing one is added
 * Metro Manila cities go under the existing "METRO MANILA" province; independent cities under their province
 * (e.g. Cebu City → Cebu, Davao City → Davao del Sur). Running it again adds nothing new.
 * New cities get no zip code (the PSGC has none).
 */
return new class extends Migration
{
    private function norm(?string $v): string
    {
        $v = mb_strtolower(trim((string) $v));
        $v = strtr($v, ['ñ' => 'n', 'é' => 'e', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
        return preg_replace('/[^a-z0-9]/', '', $v);
    }

    /** a city name without "City of" / "City" */
    private function cityNorm(?string $v): string
    {
        return $this->norm(preg_replace('/\bcity of\b|\bcity\b/i', ' ', (string) $v));
    }

    /** a barangay name without "(Pob.)" etc. */
    private function brgyNorm(?string $v): string
    {
        return $this->norm(preg_replace('/\([^)]*\)/', ' ', (string) $v));
    }

    /** a value for a NOT NULL column that has no default */
    private function filler(string $table, array $row): array
    {
        foreach (Schema::getColumns($table) as $c) {
            $name = $c['name'];
            if (array_key_exists($name, $row) || $name === 'id' || ($c['auto_increment'] ?? false)) continue;
            if (!($c['nullable'] ?? true) && ($c['default'] ?? null) === null) {
                $row[$name] = preg_match('/int|dec|float|double|num/i', (string) ($c['type_name'] ?? $c['type'] ?? '')) ? 0 : '';
            }
        }
        return $row;
    }

    /** only the columns the table really has */
    private function only(string $table, array $row): array
    {
        static $cols = [];
        $cols[$table] ??= array_flip(Schema::getColumnListing($table));
        return array_intersect_key($row, $cols[$table]);
    }

    public function up(): void
    {
        foreach (['m_province', 'm_city_mun', 'm_barangay'] as $t) {
            if (!Schema::hasTable($t)) return; // the location tables are not there: nothing to complete
        }
        $file = __DIR__ . '/../data/psgc_ph.json'; // database/data/psgc_ph.json
        if (!is_file($file)) throw new RuntimeException('database/data/psgc_ph.json is missing — copy it next to the migrations folder.');
        $raw = file_get_contents($file);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // a "UTF-8 with BOM" save (Notepad) breaks json_decode
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['barangays'])) {
            throw new RuntimeException('database/data/psgc_ph.json is not complete (' . json_last_error_msg() . ', ' . number_format(strlen($raw)) . ' bytes; the right file is about 1.3 MB with 43,780 lines). '
                . 'Download psgc_ph.json again and save the file itself — do not copy / paste its text.');
        }
        $now = now();
        $stamp = fn (string $t) => Schema::hasColumn($t, 'created_at') ? ['created_at' => $now, 'updated_at' => $now] : [];

        DB::transaction(function () use ($data, $stamp) {
            /* ---------- provinces ---------- */
            $provId = [];
            foreach (DB::table('m_province')->orderBy('id')->get(['id', 'province_name']) as $p) {
                $provId[$this->norm($p->province_name)] ??= (int) $p->id;
            }
            $count = [];
            foreach ($data['cities'] as $c) $count[$c['province']][$c['kind']] = ($count[$c['province']][$c['kind']] ?? 0) + 1;
            $cityProv = [];
            foreach ($data['cities'] as $c) $cityProv[$c['code']] = $c['province'];
            foreach ($data['barangays'] as [$cityCode]) {
                $p = $cityProv[$cityCode] ?? null;
                if ($p) $count[$p]['brgy'] = ($count[$p]['brgy'] ?? 0) + 1;
            }
            foreach ($data['provinces'] as $p) {
                if (isset($provId[$this->norm($p['name'])])) continue;
                $row = $this->only('m_province', [
                    'province_name' => $p['name'], 'population' => $p['population'], 'region_desc' => $p['region'],
                    'city_count' => $count[$p['name']]['city'] ?? 0, 'mun_count' => $count[$p['name']]['mun'] ?? 0, 'brgy_count' => $count[$p['name']]['brgy'] ?? 0,
                ] + $stamp('m_province'));
                $row = array_filter($row, fn ($v) => $v !== null); // unknown values: the column default (or 0 / '')
                $provId[$this->norm($p['name'])] = (int) DB::table('m_province')->insertGetId($this->filler('m_province', $row));
            }

            /* ---------- cities / municipalities ---------- */
            $existing = []; // "provId|name" => [city ids]
            foreach (DB::table('m_city_mun')->orderBy('id')->get(['id', 'description', 'province_id']) as $c) {
                $existing[(int) $c->province_id . '|' . $this->cityNorm($c->description)][] = (int) $c->id;
            }
            $cityIds = []; // PSGC code => [city ids] (the first one gets the new barangays)
            foreach ($data['cities'] as $c) {
                $pid = $provId[$this->norm($c['province'])] ?? null;
                if (!$pid) continue;
                $key = $pid . '|' . $this->cityNorm($c['name']);
                if (!isset($existing[$key])) {
                    $row = $this->only('m_city_mun', ['description' => $c['name'], 'province_id' => $pid, 'zip_code' => null] + $stamp('m_city_mun'));
                    $row = $this->filler('m_city_mun', array_filter($row, fn ($v) => $v !== null));
                    $existing[$key] = [(int) DB::table('m_city_mun')->insertGetId($row)];
                }
                $cityIds[$c['code']] = $existing[$key];
            }

            /* ---------- barangays ---------- */
            $have = [];
            foreach (DB::table('m_barangay')->get(['description', 'city_id']) as $b) {
                $have[(int) $b->city_id . '|' . $this->brgyNorm($b->description)] = true;
            }
            $rows = [];
            $brgyStamp = $stamp('m_barangay');
            $template = $this->filler('m_barangay', $this->only('m_barangay', ['description' => 'x', 'city_id' => 0] + $brgyStamp));
            foreach ($data['barangays'] as [$cityCode, $name]) {
                $ids = $cityIds[$cityCode] ?? null;
                if (!$ids) continue;
                $n = $this->brgyNorm($name);
                $dup = false;
                foreach ($ids as $id) if (isset($have["{$id}|{$n}"])) { $dup = true; break; }
                if ($dup) continue;
                $have["{$ids[0]}|{$n}"] = true;
                $rows[] = ['description' => $name, 'city_id' => $ids[0]] + $template;
                if (count($rows) >= 1000) { DB::table('m_barangay')->insert($rows); $rows = []; }
            }
            if ($rows) DB::table('m_barangay')->insert($rows);
        });
    }

    /** nothing is removed: the rows may already be used by employees' addresses */
    public function down(): void
    {
    }
};