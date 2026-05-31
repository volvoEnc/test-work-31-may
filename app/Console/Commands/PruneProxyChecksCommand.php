<?php

namespace App\Console\Commands;

use App\Models\ProxyCheck;
use Illuminate\Console\Command;

class PruneProxyChecksCommand extends Command
{
    protected $signature = 'proxy-checks:prune';

    protected $description = 'Prune proxy check records older than 30 days.';

    public function handle(): int
    {
        $deleted = ProxyCheck::query()
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Deleted {$deleted} proxy check records.");

        return self::SUCCESS;
    }
}
