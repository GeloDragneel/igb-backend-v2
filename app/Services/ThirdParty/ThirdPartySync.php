<?php

namespace App\Services\ThirdParty;

use Illuminate\Support\Facades\DB;

/** runs the AWS / GoDaddy syncs and writes t_tp_sync_log */
class ThirdPartySync
{
    public const PROVIDERS = ['aws', 'godaddy'];

    /** @return array<string, array{ok:bool, message:string, counts:array}> */
    public function run(?string $only = null, ?int $userId = null, int $months = 12): array
    {
        $out = [];
        foreach (self::PROVIDERS as $p) {
            if ($only && $only !== $p) continue;
            $out[$p] = $this->one($p, $userId, $months);
        }
        return $out;
    }

    private function one(string $p, ?int $userId, int $months): array
    {
        $started = now();
        $logId = DB::table('t_tp_sync_log')->insertGetId([
            'provider' => $p, 'ok' => false, 'message' => 'running…', 'run_by' => $userId,
            'started_at' => $started, 'created_at' => $started, 'updated_at' => $started,
        ]);
        try {
            $configured = $p === 'aws' ? AwsBilling::configured() : GoDaddyBilling::configured();
            if (!$configured) {
                throw new \RuntimeException($p === 'aws'
                    ? 'Not set up: add AWS_BILLING_KEY, AWS_BILLING_SECRET and AWS_BILLING_ACCOUNT_ID to .env'
                    : 'Not set up: add GODADDY_API_KEY and GODADDY_API_SECRET to .env');
            }
            $counts = $p === 'aws' ? (new AwsBilling())->sync($months) : (new GoDaddyBilling())->sync($months);
            $warnings = $counts['warnings'] ?? [];
            unset($counts['warnings']);
            $msg = collect($counts)->map(fn ($v, $k) => "{$v} {$k}")->implode(' · ')
                . ($warnings ? ' · warnings: ' . implode(' | ', array_slice($warnings, 0, 5)) : '');
            $ok = true;
        } catch (\Throwable $e) {
            $counts = [];
            $msg = mb_substr($e->getMessage(), 0, 1000);
            $ok = false;
            report($e);
        }
        DB::table('t_tp_sync_log')->where('id', $logId)->update([
            'ok' => $ok, 'message' => mb_substr($msg, 0, 1000), 'counts' => json_encode($counts),
            'finished_at' => now(), 'updated_at' => now(),
        ]);
        return ['ok' => $ok, 'message' => $msg, 'counts' => $counts];
    }
}
