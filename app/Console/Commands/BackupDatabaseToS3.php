<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan db:backup-s3
 *
 * Same as Administration → Database → "Backup Database": mysqldump → S3 Db_Fems_v2/<db>_<Y-m-d_H-i-s>.sql
 * (so the scheduled backups show in the same list on the web page).
 * Run by Windows Task Scheduler at 3:00 AM, 12:30 PM and 6:30 PM.
 */
class BackupDatabaseToS3 extends Command
{
    protected $signature = 'db:backup-s3';
    protected $description = 'Dump the database with mysqldump and upload it to S3 (Db_Fems_v2/)';

    private const FOLDER = 'Db_Fems_v2';

    public function handle(): int
    {
        @set_time_limit(0);
        $conn = config('database.connections.' . config('database.default'));
        $dbName = (string) ($conn['database'] ?? '');
        if ($dbName === '') return $this->stop('No database configured.');

        $dump = env('MYSQLDUMP_PATH') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump');
        if (PHP_OS_FAMILY === 'Windows' && !is_file($dump)) {
            return $this->stop("mysqldump not found at: {$dump} (set MYSQLDUMP_PATH in .env)");
        }

        $file = storage_path('app/backup/' . $dbName . '_' . now('Asia/Manila')->format('Y-m-d_H-i-s') . '.sql');
        @mkdir(dirname($file), 0777, true);

        $cmd = [$dump, '--user=' . ($conn['username'] ?? 'root'), '--host=' . ($conn['host'] ?? '127.0.0.1'),
            '--port=' . ($conn['port'] ?? 3306), '--routines', '--triggers', '--single-transaction',
            '--result-file=' . $file, $dbName];
        $env = array_merge(getenv() ?: [], ['MYSQL_PWD' => (string) ($conn['password'] ?? '')]);   // password not on the command line

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) return $this->stop('Could not start mysqldump.');
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        try {
            if ($code !== 0 || !is_file($file) || filesize($file) === 0) {
                return $this->stop("mysqldump failed (code {$code}): " . (trim((string) $err) ?: 'no error output'));
            }
            $size = filesize($file);
            $key = self::FOLDER . '/' . basename($file);
            $in = fopen($file, 'r');
            Storage::disk('s3')->writeStream($key, $in, ['ServerSideEncryption' => 'AES256', 'StorageClass' => 'STANDARD_IA']);
            if (is_resource($in)) fclose($in);
        } catch (\Throwable $e) {
            return $this->stop('S3 error: ' . $e->getMessage());
        } finally {
            @unlink($file);
        }

        Cache::forget('admin-db-files:' . self::FOLDER);   // refresh the list on the web page
        $msg = sprintf('Backup OK: %s (%.2f MB)', $key, $size / 1048576);
        Log::info('[db:backup-s3] ' . $msg);
        $this->info($msg);
        return self::SUCCESS;
    }

    private function stop(string $message): int
    {
        Log::error('[db:backup-s3] ' . $message);
        $this->error($message);
        return self::FAILURE;
    }
}