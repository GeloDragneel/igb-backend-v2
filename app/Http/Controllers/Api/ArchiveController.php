<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * STAFF → ARCHIVE: browse every file in the S3 bucket (AWS_BUCKET of the 's3' disk – no keys in code).
 *
 * GET    /archive?prefix=&token=          one folder: sub-folders + files (300 per page, token = next page)
 * GET    /archive/summary?refresh=1       totals per type, last 6 months, recent uploads, folder sizes (cached 6h)
 * GET    /archive/file?key=&download=1    a 10-minute link to open / download the file
 * POST   /archive/folder   {prefix,name}  new folder                    IT OFFICER only
 * PUT    /archive/folder   {path,name}    rename a folder               IT OFFICER only
 * POST   /archive/upload   {prefix,file}  upload a file into a folder   IT OFFICER only
 * Nothing can be deleted from the Archive.
 */
class ArchiveController extends Controller
{
    private const IT_OFFICER = 1;
    private const SUMMARY_KEY = 'archive:summary:v1';
    private const PAGE = 300;

    private const TYPES = [
        'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'xml', 'json', 'html'],
        'photos'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'tif', 'tiff', 'ico'],
        'videos'    => ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', '3gp', 'm4v'],
    ];

    /* ---------------- helpers ---------------- */

    private function staffOnly(Request $request): UserStaff
    {
        $u = $request->user();
        abort_unless($u instanceof UserStaff, 403, 'Only staff can open the Archive.');
        return $u;
    }

    /** IT OFFICER = m_user_staff.user_type_id 1 (or the employee's designation 1) */
    private function isIt(UserStaff $u): bool
    {
        if ((int) ($u->user_type_id ?? 0) === self::IT_OFFICER) return true;
        $designation = isset($u->comid)
            ? (int) DB::table('m_employee')->where('com_id', $u->comid)->value('designation_id') : 0;
        return $designation === self::IT_OFFICER;
    }

    private function itOnly(Request $request): UserStaff
    {
        $u = $this->staffOnly($request);
        abort_unless($this->isIt($u), 403, 'Only the IT Officer can change the Archive.');
        return $u;
    }

    private function client()
    {
        return Storage::disk('s3')->getClient();
    }

    private function bucket(): string
    {
        $b = (string) config('filesystems.disks.s3.bucket');
        abort_if($b === '', 500, 'AWS_BUCKET is not set in .env.');
        return $b;
    }

    /** "Billing_Collection/2026/" – no leading "/", always ends with "/", no ".." */
    private function cleanPrefix(?string $p): string
    {
        $p = trim(str_replace('\\', '/', (string) $p));
        $p = trim(preg_replace('#/+#', '/', $p), '/');
        abort_if(preg_match('#(^|/)\.\.?(/|$)#', $p), 422, 'Invalid folder.');
        return $p === '' ? '' : $p . '/';
    }

    private function cleanKey(?string $k): string
    {
        $k = ltrim(str_replace('\\', '/', (string) $k), '/');
        abort_if($k === '' || preg_match('#(^|/)\.\.?(/|$)#', $k), 422, 'Invalid file.');
        return $k;
    }

    private function cleanName(?string $n): string
    {
        $n = trim(preg_replace('/\s+/', ' ', (string) $n));
        abort_if($n === '' || mb_strlen($n) > 120, 422, 'Enter a folder name (up to 120 characters).');
        abort_unless(preg_match('/^[\pL\pN _.,()&+\-]+$/u', $n) && $n !== '.' && $n !== '..', 422,
            'Use letters, numbers, spaces and _ . , ( ) & + - only.');
        return $n;
    }

    private function typeOf(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        foreach (self::TYPES as $t => $exts) if (in_array($ext, $exts, true)) return $t;
        return 'others';
    }

    private function exists(string $key): bool
    {
        try {
            $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function folderExists(string $prefix): bool
    {
        $r = $this->client()->listObjectsV2(['Bucket' => $this->bucket(), 'Prefix' => $prefix, 'MaxKeys' => 1]);
        return !empty($r['Contents']);
    }

    private function fileRow(array $o): array
    {
        $key = (string) $o['Key'];
        return [
            'key'      => $key,
            'name'     => basename($key),
            'ext'      => strtolower(pathinfo($key, PATHINFO_EXTENSION)),
            'type'     => $this->typeOf($key),
            'size'     => (int) ($o['Size'] ?? 0),
            'modified' => isset($o['LastModified']) ? $o['LastModified']->format('c') : null,
        ];
    }

    /* ---------------- browse ---------------- */

    public function index(Request $request)
    {
        $u = $this->staffOnly($request);
        $prefix = $this->cleanPrefix($request->query('prefix'));

        $args = ['Bucket' => $this->bucket(), 'Prefix' => $prefix, 'Delimiter' => '/', 'MaxKeys' => self::PAGE];
        if ($t = $request->query('token')) $args['ContinuationToken'] = (string) $t;
        $r = $this->client()->listObjectsV2($args);

        $stats = Cache::get(self::SUMMARY_KEY)['folders'] ?? [];

        $folders = collect($r['CommonPrefixes'] ?? [])->map(function ($p) use ($prefix, $stats) {
            $path = (string) $p['Prefix'];
            return [
                'path'  => $path,
                'name'  => rtrim(substr($path, strlen($prefix)), '/'),
                'size'  => $stats[$path][0] ?? null,
                'count' => $stats[$path][1] ?? null,
                'modified' => isset($stats[$path][2]) ? date('c', $stats[$path][2]) : null,
            ];
        })->values();

        $files = collect($r['Contents'] ?? [])
            ->filter(fn ($o) => $o['Key'] !== $prefix && !str_ends_with($o['Key'], '/'))   // the folder marker itself
            ->map(fn ($o) => $this->fileRow($o))
            ->values();

        return response()->json([
            'prefix'     => $prefix,
            'folders'    => $folders,
            'files'      => $files,
            'next'       => $r['IsTruncated'] ? ($r['NextContinuationToken'] ?? null) : null,
            'can_manage' => $this->isIt($u),
            'bucket'     => $this->bucket(),
        ]);
    }

    /* ---------------- summary (whole bucket, cached) ---------------- */

    public function summary(Request $request)
    {
        $u = $this->staffOnly($request);
        if ($request->boolean('refresh')) Cache::forget(self::SUMMARY_KEY);

        $s = Cache::remember(self::SUMMARY_KEY, now()->addHours(6), function () {
            @set_time_limit(600);
            $types = ['documents' => 0, 'photos' => 0, 'videos' => 0, 'others' => 0];
            $cat = [];
            foreach ($types as $t => $_) $cat[$t] = ['count' => 0, 'size' => 0, 'last' => 0];

            // last 6 months, oldest first
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = now()->startOfMonth()->subMonths($i);
                $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M')] + $types;
            }

            $folders = [];          // "A/B/" => [size, count, last]
            $recent = [];           // newest 8
            $latest = array_fill_keys(array_keys($types), []);   // newest 40 per type
            $total = ['count' => 0, 'size' => 0];

            $pages = $this->client()->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket(), 'MaxKeys' => 1000]);
            foreach ($pages as $page) {
                foreach ($page['Contents'] ?? [] as $o) {
                    $key = (string) $o['Key'];
                    if (str_ends_with($key, '/')) continue;
                    $size = (int) $o['Size'];
                    $time = $o['LastModified']->getTimestamp();
                    $t = $this->typeOf($key);

                    $total['count']++;
                    $total['size'] += $size;
                    $cat[$t]['count']++;
                    $cat[$t]['size'] += $size;
                    $cat[$t]['last'] = max($cat[$t]['last'], $time);

                    $mk = date('Y-m', $time);
                    if (isset($months[$mk])) $months[$mk][$t] += $size;

                    // every parent folder of the file
                    $parts = explode('/', $key);
                    array_pop($parts);
                    $path = '';
                    foreach ($parts as $part) {
                        $path .= $part . '/';
                        $f = $folders[$path] ?? [0, 0, 0];
                        $folders[$path] = [$f[0] + $size, $f[1] + 1, max($f[2], $time)];
                    }

                    $row = ['key' => $key, 'name' => basename($key), 'ext' => strtolower(pathinfo($key, PATHINFO_EXTENSION)),
                        'type' => $t, 'size' => $size, 't' => $time];
                    $recent[] = $row;
                    $latest[$t][] = $row;
                    if (count($recent) > 64) $recent = $this->newest($recent, 8);
                    if (count($latest[$t]) > 200) $latest[$t] = $this->newest($latest[$t], 40);
                }
            }

            $out = fn ($r) => ['key' => $r['key'], 'name' => $r['name'], 'ext' => $r['ext'], 'type' => $r['type'],
                'size' => $r['size'], 'modified' => date('c', $r['t'])];

            return [
                'total'      => $total,
                'types'      => collect($cat)->map(fn ($c) => $c + ['last' => $c['last'] ? date('c', $c['last']) : null])
                    ->map(fn ($c) => ['count' => $c['count'], 'size' => $c['size'], 'last' => $c['last']])->all(),
                'months'     => array_values($months),
                'recent'     => array_map($out, $this->newest($recent, 8)),
                'latest'     => collect($latest)->map(fn ($rows) => array_map($out, $this->newest($rows, 40)))->all(),
                'folders'    => $folders,
                'scanned_at' => now()->toIso8601String(),
            ];
        });

        unset($s['folders']);   // the list endpoint uses them; the page doesn't need them all
        return response()->json($s + ['can_manage' => $this->isIt($u)]);
    }

    private function newest(array $rows, int $n): array
    {
        usort($rows, fn ($a, $b) => $b['t'] <=> $a['t']);
        return array_slice($rows, 0, $n);
    }

    /* ---------------- open / download ---------------- */

    public function file(Request $request)
    {
        $this->staffOnly($request);
        $key = $this->cleanKey($request->query('key'));
        abort_unless($this->exists($key), 404, 'File not found.');

        $name = str_replace('"', '', basename($key));
        $url = Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(10), $request->boolean('download')
            ? ['ResponseContentDisposition' => 'attachment; filename="' . $name . '"']
            : ['ResponseContentDisposition' => 'inline; filename="' . $name . '"']);

        return response()->json(['url' => $url, 'name' => $name]);
    }

    /* ---------------- IT Officer: folders, upload, delete ---------------- */

    public function createFolder(Request $request)
    {
        $this->itOnly($request);
        $prefix = $this->cleanPrefix($request->input('prefix'));
        $name = $this->cleanName($request->input('name'));
        $path = $prefix . $name . '/';
        abort_if($this->folderExists($path), 422, "The folder \"{$name}\" already exists here.");

        $this->client()->putObject(['Bucket' => $this->bucket(), 'Key' => $path, 'Body' => '']);
        Cache::forget(self::SUMMARY_KEY);

        return response()->json(['message' => "Folder \"{$name}\" created.", 'path' => $path]);
    }

    /** rename = copy every object to the new folder, then delete the old ones */
    public function renameFolder(Request $request)
    {
        $this->itOnly($request);
        $old = $this->cleanPrefix($request->input('path'));
        abort_if($old === '', 422, 'Pick a folder.');
        $name = $this->cleanName($request->input('name'));
        $parent = substr($old, 0, strrpos(rtrim($old, '/'), '/') === false ? 0 : strrpos(rtrim($old, '/'), '/') + 1);
        $new = $parent . $name . '/';
        if ($new === $old) return response()->json(['message' => 'Nothing changed.', 'path' => $old]);
        abort_if($this->folderExists($new), 422, "The folder \"{$name}\" already exists here.");

        @set_time_limit(900);
        $c = $this->client();
        $bucket = $this->bucket();
        $keys = [];
        foreach ($c->getPaginator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => $old]) as $page) {
            foreach ($page['Contents'] ?? [] as $o) $keys[] = (string) $o['Key'];
        }
        abort_if(!$keys, 404, 'Folder not found.');
        abort_if(count($keys) > 20000, 422, 'This folder has more than 20,000 files – rename it in the AWS console.');

        $copied = [];
        try {
            foreach ($keys as $k) {
                $c->copyObject([
                    'Bucket'     => $bucket,
                    'Key'        => $new . substr($k, strlen($old)),
                    'CopySource' => $bucket . '/' . str_replace('%2F', '/', rawurlencode($k)),
                ]);
                $copied[] = $k;
            }
        } catch (\Throwable $e) {
            // undo the half-done copy so nothing is left in two places
            foreach (array_chunk($copied, 1000) as $chunk) {
                $c->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => array_map(fn ($k) => ['Key' => $new . substr($k, strlen($old))], $chunk)]]);
            }
            report($e);
            abort(500, 'Rename failed, nothing was changed: ' . $e->getMessage());
        }
        foreach (array_chunk($keys, 1000) as $chunk) {
            $c->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => array_map(fn ($k) => ['Key' => $k], $chunk)]]);
        }
        Cache::forget(self::SUMMARY_KEY);

        return response()->json(['message' => 'Folder renamed to "' . $name . '" (' . count($keys) . ' file(s) moved).', 'path' => $new]);
    }

    public function upload(Request $request)
    {
        $this->itOnly($request);
        $request->validate(['file' => 'required|file|max:20480']);   // 20 MB (php.ini upload_max_filesize 20M)
        $prefix = $this->cleanPrefix($request->input('prefix'));
        $f = $request->file('file');

        $name = preg_replace('/[^\pL\pN _.,()&+\-]/u', '_', $f->getClientOriginalName());
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $key = $prefix . $name;
        for ($i = 1; $this->exists($key); $i++) {        // never overwrite: "file (1).pdf"
            $key = $prefix . $base . " ({$i})" . ($ext !== '' ? ".{$ext}" : '');
        }

        Storage::disk('s3')->putFileAs(rtrim($prefix, '/'), $f, basename($key));
        Cache::forget(self::SUMMARY_KEY);

        return response()->json([
            'message' => 'Uploaded ' . basename($key) . '.',
            'file'    => $this->fileRow(['Key' => $key, 'Size' => $f->getSize(), 'LastModified' => now()->toDateTime()]),
        ]);
    }
}