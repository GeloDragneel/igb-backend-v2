<?php

namespace App\Services\ThirdParty;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * GoDaddy → t_tp_bill (orders) + t_tp_renewal (domains, subscriptions)
 *
 * .env (never in code / git) — key from developer.godaddy.com → API Keys → Production:
 *   GODADDY_API_KEY=
 *   GODADDY_API_SECRET=
 *   GODADDY_SHOPPER_ID=        optional (only for a reseller reading a customer account)
 *   GODADDY_AMOUNT_MICRO=true  order totals come in micro-units (1,000,000 = 1.00); set false if the amounts look 1,000,000× too small
 */
class GoDaddyBilling
{
    private const BASE = 'https://api.godaddy.com';

    public static function configured(): bool
    {
        return (bool) (config('services.godaddy.key') && config('services.godaddy.secret'));
    }

    private function http(): PendingRequest
    {
        $h = ['Authorization' => 'sso-key ' . config('services.godaddy.key') . ':' . config('services.godaddy.secret'), 'Accept' => 'application/json'];
        if (config('services.godaddy.shopper_id')) $h['X-Shopper-Id'] = (string) config('services.godaddy.shopper_id');
        return Http::baseUrl(self::BASE)->withHeaders($h)->timeout(30)->retry(2, 1500, throw: false);
    }

    /** @return array{orders:int, domains:int, subscriptions:int, warnings:array} */
    public function sync(int $months = 12): array
    {
        $warnings = [];
        $orders = $this->step('orders', fn () => $this->syncOrders($months), $warnings);
        $domains = $this->step('domains', fn () => $this->syncDomains(), $warnings);
        $subs = $this->step('subscriptions', fn () => $this->syncSubscriptions(), $warnings);
        if ($orders === null && $domains === null && $subs === null) {
            throw new \RuntimeException('GoDaddy: nothing could be read · ' . implode(' · ', $warnings));
        }
        return ['orders' => (int) $orders, 'domains' => (int) $domains, 'subscriptions' => (int) $subs, 'warnings' => $warnings];
    }

    /** one part failing (e.g. no access to the Orders API) does not stop the others */
    private function step(string $name, callable $fn, array &$warnings): ?int
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $warnings[] = "{$name}: " . mb_substr($e->getMessage(), 0, 300);
            return null;
        }
    }

    private function get(string $path, array $query = []): array
    {
        $res = $this->http()->get($path, $query);
        if (!$res->successful()) {
            $msg = $res->json('message') ?: $res->json('code') ?: $res->reason();
            throw new \RuntimeException("HTTP {$res->status()} on {$path}: {$msg}");
        }
        return (array) $res->json();
    }

    private function money($v): float
    {
        $v = (float) ($v ?? 0);
        return filter_var(config('services.godaddy.micro', true), FILTER_VALIDATE_BOOLEAN) ? round($v / 1000000, 2) : round($v, 2);
    }

    private function syncOrders(int $months): int
    {
        $now = now();
        $from = now()->startOfMonth()->subMonthsNoOverflow(max(1, $months) - 1)->toIso8601ZuluString();
        $to = now()->toIso8601ZuluString();
        $n = 0;
        $offset = 0;
        $limit = 100;
        do {
            $body = $this->get('/v1/orders', ['periodStart' => $from, 'periodEnd' => $to, 'limit' => $limit, 'offset' => $offset]);
            $list = $body['orders'] ?? (array_is_list($body) ? $body : []);
            foreach ($list as $o) {
                $items = collect($o['items'] ?? [])->map(fn ($i) => $i['label'] ?? ($i['domains'][0] ?? null))->filter()->implode(', ');
                $pricing = $o['pricing'] ?? [];
                $date = isset($o['createdAt']) ? substr((string) $o['createdAt'], 0, 10) : null;
                DB::table('t_tp_bill')->updateOrInsert(
                    ['provider' => 'godaddy', 'ref_no' => (string) ($o['orderId'] ?? $o['id'] ?? md5(json_encode($o)))],
                    [
                        'kind' => 'order', 'bill_date' => $date, 'period' => $date ? substr($date, 0, 7) : null,
                        'description' => mb_substr($items ?: 'GoDaddy order', 0, 500),
                        'amount' => $this->money($pricing['total'] ?? $pricing['totalPaid'] ?? 0),
                        'tax' => $this->money($pricing['taxes'] ?? $pricing['tax'] ?? 0),
                        'currency' => $o['currency'] ?? $pricing['currency'] ?? null,
                        'due_date' => null, 'status' => $o['status'] ?? $o['paymentStatus'] ?? null,
                        'raw' => json_encode($o, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                        'synced_at' => $now, 'updated_at' => $now, 'created_at' => $now,
                    ]
                );
                $n++;
            }
            $offset += $limit;
            $total = (int) ($body['pagination']['total'] ?? 0);
        } while (count($list) === $limit && ($total === 0 || $offset < $total));
        return $n;
    }

    private function syncDomains(): int
    {
        $now = now();
        $n = 0;
        $marker = null;
        do {
            $list = $this->get('/v1/domains', array_filter(['limit' => 1000, 'marker' => $marker]));
            foreach ($list as $d) {
                if (empty($d['domain'])) continue;
                DB::table('t_tp_renewal')->updateOrInsert(
                    ['provider' => 'godaddy', 'kind' => 'domain', 'ref' => strtolower($d['domain'])],
                    [
                        'name' => $d['domain'], 'product' => 'Domain', 'status' => $d['status'] ?? null,
                        'expires_at' => isset($d['expires']) ? substr((string) $d['expires'], 0, 10) : null,
                        'renew_auto' => (bool) ($d['renewAuto'] ?? false),
                        'raw' => json_encode($d, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                        'synced_at' => $now, 'updated_at' => $now, 'created_at' => $now,
                    ]
                );
                $n++;
            }
            $marker = count($list) === 1000 ? end($list)['domain'] ?? null : null;
        } while ($marker);
        return $n;
    }

    private function syncSubscriptions(): int
    {
        $now = now();
        $n = 0;
        $offset = 0;
        $limit = 100;
        do {
            $body = $this->get('/v1/subscriptions', ['limit' => $limit, 'offset' => $offset]);
            $list = $body['subscriptions'] ?? (array_is_list($body) ? $body : []);
            foreach ($list as $s) {
                $id = (string) ($s['subscriptionId'] ?? '');
                if ($id === '') continue;
                $exp = $s['expiresAt'] ?? $s['billing']['renewAt'] ?? null;
                DB::table('t_tp_renewal')->updateOrInsert(
                    ['provider' => 'godaddy', 'kind' => 'subscription', 'ref' => $id],
                    [
                        'name' => $s['label'] ?? ($s['product']['label'] ?? $id),
                        'product' => $s['product']['label'] ?? ($s['product']['productGroupKey'] ?? null),
                        'status' => $s['status'] ?? null,
                        'expires_at' => $exp ? substr((string) $exp, 0, 10) : null,
                        'renew_auto' => (bool) ($s['renewAuto'] ?? false),
                        'raw' => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                        'synced_at' => $now, 'updated_at' => $now, 'created_at' => $now,
                    ]
                );
                $n++;
            }
            $offset += $limit;
            $total = (int) ($body['pagination']['total'] ?? 0);
        } while (count($list) === $limit && ($total === 0 || $offset < $total));
        return $n;
    }
}
