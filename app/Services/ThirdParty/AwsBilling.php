<?php

namespace App\Services\ThirdParty;

use Aws\CostExplorer\CostExplorerClient;
use Aws\Exception\AwsException;
use Aws\Invoicing\InvoicingClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Amazon (AWS) billing → t_tp_bill (invoices) + t_tp_cost (cost per month per service)
 *
 * .env (never in code / git):
 *   AWS_BILLING_KEY=        IAM user with: invoicing:ListInvoiceSummaries, ce:GetCostAndUsage (read only)
 *   AWS_BILLING_SECRET=
 *   AWS_BILLING_ACCOUNT_ID= 12-digit account id (the payer / management account)
 *
 * Cost Explorer bills USD 0.01 per request: one sync ≈ 1–2 requests.
 */
class AwsBilling
{
    private const REGION = 'us-east-1';   // billing APIs are global, served from us-east-1

    public static function configured(): bool
    {
        return (bool) (config('services.aws_billing.key') && config('services.aws_billing.secret') && config('services.aws_billing.account_id'));
    }

    private function creds(): array
    {
        return [
            'version' => 'latest', 'region' => self::REGION,
            'credentials' => ['key' => config('services.aws_billing.key'), 'secret' => config('services.aws_billing.secret')],
        ];
    }

    /** @return array{invoices:int, costs:int, warnings:array} */
    public function sync(int $months = 12): array
    {
        $warnings = [];
        $invoices = $this->syncInvoices($months, $warnings);
        $costs = $this->syncCosts($months);
        return ['invoices' => $invoices, 'costs' => $costs, 'warnings' => $warnings];
    }

    /** invoices: one billing period per call (the API allows one month without ReceiverRole) */
    private function syncInvoices(int $months, array &$warnings): int
    {
        $client = new InvoicingClient($this->creds());
        $account = (string) config('services.aws_billing.account_id');
        $now = now();
        $n = 0;

        for ($i = 0; $i < $months; $i++) {
            $m = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($i);
            $token = null;
            do {
                try {
                    $res = $client->listInvoiceSummaries(array_filter([
                        'Selector' => ['ResourceType' => 'ACCOUNT_ID', 'Value' => $account],
                        'Filter' => ['BillingPeriod' => ['Month' => (int) $m->month, 'Year' => (int) $m->year]],
                        'MaxResults' => 100,
                        'NextToken' => $token,
                    ]));
                } catch (AwsException $e) {
                    // periods before AWS keeps invoice data (or with no invoice) answer with a validation error → skip the month
                    if (in_array($e->getAwsErrorCode(), ['ValidationException', 'ResourceNotFoundException'], true)) {
                        $warnings[] = $m->format('Y-m') . ': ' . ($e->getAwsErrorMessage() ?: $e->getAwsErrorCode());
                        break;
                    }
                    throw $e;
                }

                foreach ($res['InvoiceSummaries'] ?? [] as $inv) {
                    $base = $inv['BaseCurrencyAmount'] ?? [];
                    $bp = $inv['BillingPeriod'] ?? [];
                    $period = isset($bp['Year'], $bp['Month']) ? sprintf('%04d-%02d', $bp['Year'], $bp['Month']) : $m->format('Y-m');
                    DB::table('t_tp_bill')->updateOrInsert(
                        ['provider' => 'aws', 'ref_no' => (string) $inv['InvoiceId']],
                        [
                            'kind' => 'invoice',
                            'bill_date' => $this->date($inv['IssuedDate'] ?? null),
                            'period' => $period,
                            'description' => trim('AWS ' . ($inv['InvoiceType'] ?? 'invoice') . ' · ' . $period
                                . (!empty($inv['Entity']['InvoicingEntity']) ? ' · ' . $inv['Entity']['InvoicingEntity'] : '')),
                            'amount' => (float) ($base['TotalAmount'] ?? 0),
                            'tax' => (float) ($base['AmountBreakdown']['Taxes']['TotalAmount'] ?? 0),
                            'currency' => $base['CurrencyCode'] ?? 'USD',
                            'due_date' => $this->date($inv['DueDate'] ?? null),
                            'status' => $inv['InvoiceType'] ?? null,
                            'raw' => json_encode($inv, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                            'synced_at' => $now, 'updated_at' => $now, 'created_at' => $now,
                        ]
                    );
                    $n++;
                }
                $token = $res['NextToken'] ?? null;
            } while ($token);
        }
        return $n;
    }

    /** cost per month per service (unblended), current month = estimated */
    private function syncCosts(int $months): int
    {
        $client = new CostExplorerClient($this->creds());
        $start = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(max(1, $months) - 1);
        $end = CarbonImmutable::now()->addDay()->startOfDay();            // End is exclusive
        $now = now();
        $n = 0;
        $token = null;
        do {
            $res = $client->getCostAndUsage(array_filter([
                'TimePeriod' => ['Start' => $start->toDateString(), 'End' => $end->toDateString()],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'SERVICE']],
                'NextPageToken' => $token,
            ]));
            foreach ($res['ResultsByTime'] ?? [] as $r) {
                $period = substr((string) $r['TimePeriod']['Start'], 0, 7);
                foreach ($r['Groups'] ?? [] as $g) {
                    $m = $g['Metrics']['UnblendedCost'] ?? [];
                    DB::table('t_tp_cost')->updateOrInsert(
                        ['provider' => 'aws', 'period' => $period, 'service' => mb_substr((string) ($g['Keys'][0] ?? 'Other'), 0, 150)],
                        ['amount' => (float) ($m['Amount'] ?? 0), 'currency' => $m['Unit'] ?? 'USD', 'estimated' => (bool) ($r['Estimated'] ?? false),
                            'synced_at' => $now, 'updated_at' => $now, 'created_at' => $now]
                    );
                    $n++;
                }
            }
            $token = $res['NextPageToken'] ?? null;
        } while ($token);
        return $n;
    }

    private function date($v): ?string
    {
        if (!$v) return null;
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
        return is_numeric($v) ? date('Y-m-d', (int) $v) : substr((string) $v, 0, 10);
    }
}
