<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Jobs\ScheduleAllProxyChecksJob;
use App\Models\ProxyServer;

class QueueAllProxyChecksAction
{
    public function execute(ProxyCheckSource $source = ProxyCheckSource::Manual): int
    {
        $count = ProxyServer::query()->count();

        ScheduleAllProxyChecksJob::dispatch($source)->afterCommit();

        return $count;
    }
}
