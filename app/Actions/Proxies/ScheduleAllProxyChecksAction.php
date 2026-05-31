<?php

namespace App\Actions\Proxies;

use App\Enums\ProxyCheckSource;
use App\Models\ProxyServer;

class ScheduleAllProxyChecksAction
{
    public function __construct(private readonly ScheduleProxyCheckAction $scheduleProxyCheck)
    {
    }

    public function execute(ProxyCheckSource $source = ProxyCheckSource::Manual): int
    {
        $count = 0;

        ProxyServer::query()
            ->orderBy('id')
            ->chunkById(100, function ($proxies) use ($source, &$count): void {
                foreach ($proxies as $proxy) {
                    $this->scheduleProxyCheck->execute($proxy, $source);
                    $count++;
                }
            });

        return $count;
    }
}
