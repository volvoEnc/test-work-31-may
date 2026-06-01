<?php

namespace App\Console\Commands;

use App\Models\ProxyCheck;
use Illuminate\Console\Command;

class PruneProxyChecksCommand extends Command
{
    protected $signature = 'proxy-checks:prune';

    protected $description = 'Prune proxy check records older than the configured retention window.';

    public function handle(): int
    {
        $deleted = (new ProxyCheck)->pruneAll();

        $this->info("Deleted {$deleted} proxy check records.");

        return self::SUCCESS;
    }
}
