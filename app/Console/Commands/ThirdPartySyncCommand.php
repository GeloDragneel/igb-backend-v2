<?php

namespace App\Console\Commands;

use App\Services\ThirdParty\ThirdPartySync;
use Illuminate\Console\Command;

/**
 * php artisan thirdparty:sync            → AWS + GoDaddy
 * php artisan thirdparty:sync aws        → only AWS
 * php artisan thirdparty:sync godaddy --months=24
 */
class ThirdPartySyncCommand extends Command
{
    protected $signature = 'thirdparty:sync {provider? : aws | godaddy} {--months=12 : how many months back}';
    protected $description = 'Pull billing from Amazon (AWS) and GoDaddy into Administration → Third Party';

    public function handle(ThirdPartySync $sync): int
    {
        $p = $this->argument('provider');
        if ($p && !in_array($p, ThirdPartySync::PROVIDERS, true)) {
            $this->error('provider must be aws or godaddy');
            return self::INVALID;
        }
        $fail = false;
        foreach ($sync->run($p, null, max(1, (int) $this->option('months'))) as $name => $r) {
            $r['ok'] ? $this->info(strtoupper($name) . ': ' . $r['message']) : $this->error(strtoupper($name) . ': ' . $r['message']);
            $fail = $fail || !$r['ok'];
        }
        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
